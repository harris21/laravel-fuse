<?php

use Harris21\Fuse\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Cache::flush();
    config(['fuse.enabled' => true]);
    config(['fuse.default_threshold' => 50]);
    config(['fuse.default_timeout' => 60]);
    config(['fuse.default_min_requests' => 5]);
    config(['fuse.services' => []]);
    config(['fuse.status_page.enabled' => false]);
    config(['fuse.status_page.prefix' => 'fuse']);
    config(['fuse.status_page.middleware' => []]);
    config(['fuse.status_page.polling_interval' => 2]);
});

it('returns 404 when status page is disabled', function () {
    config(['fuse.status_page.enabled' => false]);

    $this->get('/fuse')->assertNotFound();
});

it('returns 200 when status page is enabled and gate allows', function () {
    config(['fuse.status_page.enabled' => true]);
    Gate::define('viewFuse', fn ($user = null) => true);

    $this->get('/fuse')->assertSuccessful();
});

it('returns 403 when gate denies access', function () {
    config(['fuse.status_page.enabled' => true]);
    Gate::define('viewFuse', fn ($user = null) => false);

    $this->get('/fuse')->assertForbidden();
});

it('returns json with all configured services', function () {
    config(['fuse.status_page.enabled' => true]);
    Gate::define('viewFuse', fn ($user = null) => true);
    config(['fuse.services' => [
        'stripe' => ['threshold' => 50, 'timeout' => 30, 'min_requests' => 5],
        'mailgun' => ['threshold' => 60, 'timeout' => 45, 'min_requests' => 10],
    ]]);

    $this->getJson('/fuse/data')
        ->assertOk()
        ->assertJsonStructure([
            'services' => [
                'stripe' => ['state', 'attempts', 'failures', 'failure_rate', 'opened_at', 'recovery_at', 'timeout', 'threshold', 'min_requests', 'state_history'],
                'mailgun' => ['state', 'attempts', 'failures', 'failure_rate', 'opened_at', 'recovery_at', 'timeout', 'threshold', 'min_requests', 'state_history'],
            ],
            'circuit_breaker_enabled',
            'timestamp',
        ]);
});

it('handles empty services gracefully', function () {
    config(['fuse.status_page.enabled' => true]);
    Gate::define('viewFuse', fn ($user = null) => true);
    config(['fuse.services' => []]);

    $this->getJson('/fuse/data')
        ->assertOk()
        ->assertJson([
            'services' => [],
            'circuit_breaker_enabled' => true,
        ]);
});

it('tracks state history across sequential requests', function () {
    config(['fuse.status_page.enabled' => true]);
    Gate::define('viewFuse', fn ($user = null) => true);
    config(['fuse.default_min_requests' => 5]);
    config(['fuse.services' => [
        'stripe' => ['threshold' => 50, 'timeout' => 60, 'min_requests' => 5],
    ]]);

    // First request — closed state, establishes baseline
    $this->getJson('/fuse/data')
        ->assertOk()
        ->assertJsonPath('services.stripe.state', 'closed');

    // Trip the circuit
    $breaker = new CircuitBreaker('stripe');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }
    expect($breaker->isOpen())->toBeTrue();

    // Second request — open state, should record transition
    $response = $this->getJson('/fuse/data')
        ->assertOk()
        ->assertJsonPath('services.stripe.state', 'open');

    $history = $response->json('services.stripe.state_history');
    expect($history)->toHaveCount(1);
    expect($history[0]['from'])->toBe('closed');
    expect($history[0]['to'])->toBe('open');
});

it('returns circuit_breaker_enabled from cache override', function () {
    config(['fuse.status_page.enabled' => true]);
    Gate::define('viewFuse', fn ($user = null) => true);
    config(['fuse.enabled' => true]);
    config(['fuse.services' => []]);
    Cache::put('fuse:enabled', false);

    $this->getJson('/fuse/data')
        ->assertOk()
        ->assertJsonPath('circuit_breaker_enabled', false);
});

it('returns circuit_breaker_enabled from config fallback', function () {
    config(['fuse.status_page.enabled' => true]);
    Gate::define('viewFuse', fn ($user = null) => true);
    config(['fuse.enabled' => false]);
    config(['fuse.services' => []]);

    $this->getJson('/fuse/data')
        ->assertOk()
        ->assertJsonPath('circuit_breaker_enabled', false);
});

it('provides independent stats per service', function () {
    config(['fuse.status_page.enabled' => true]);
    Gate::define('viewFuse', fn ($user = null) => true);
    config(['fuse.services' => [
        'stripe' => ['threshold' => 50, 'timeout' => 30, 'min_requests' => 5],
        'mailgun' => ['threshold' => 60, 'timeout' => 45, 'min_requests' => 10],
    ]]);

    $stripeBreaker = new CircuitBreaker('stripe');
    for ($i = 0; $i < 5; $i++) {
        $stripeBreaker->recordFailure();
    }

    $response = $this->getJson('/fuse/data')->assertOk();

    expect($response->json('services.stripe.state'))->toBe('open');
    expect($response->json('services.mailgun.state'))->toBe('closed');
    expect($response->json('services.stripe.timeout'))->toBe(30);
    expect($response->json('services.mailgun.timeout'))->toBe(45);
});

it('registers named routes', function () {
    expect(route('fuse.status'))->toContain('/fuse');
    expect(route('fuse.status.data'))->toContain('/fuse/data');
});

it('data endpoint also guarded by middleware', function () {
    config(['fuse.status_page.enabled' => false]);

    $this->getJson('/fuse/data')->assertNotFound();
});
