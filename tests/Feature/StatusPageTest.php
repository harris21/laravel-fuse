<?php

use Harris21\Fuse\CircuitBreaker;
use Illuminate\Auth\GenericUser;
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

    $this->get('/fuse')
        ->assertSuccessful()
        ->assertSee('Probe candidate')
        ->assertSee('Elected probe');
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
                'stripe' => ['state', 'attempts', 'failures', 'failure_rate', 'opened_at', 'recovery_at', 'timeout', 'threshold', 'min_requests', 'probe_candidate', 'state_history'],
                'mailgun' => ['state', 'attempts', 'failures', 'failure_rate', 'opened_at', 'recovery_at', 'timeout', 'threshold', 'min_requests', 'probe_candidate', 'state_history'],
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

it('keeps state history separate per cache prefix', function () {
    config(['fuse.status_page.enabled' => true]);
    Gate::define('viewFuse', fn ($user = null) => true);
    config(['fuse.services' => [
        'stripe' => ['threshold' => 50, 'timeout' => 60, 'min_requests' => 5],
    ]]);

    config(['fuse.cache.prefix' => 'app1']);
    $this->getJson('/fuse/data')->assertOk();
    (new CircuitBreaker('stripe'))->forceOpen();
    $this->getJson('/fuse/data')
        ->assertOk()
        ->assertJsonCount(1, 'services.stripe.state_history');

    config(['fuse.cache.prefix' => 'app2']);
    $this->getJson('/fuse/data')
        ->assertOk()
        ->assertJsonPath('services.stripe.state', 'closed')
        ->assertJsonCount(0, 'services.stripe.state_history');
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

it('reads circuit_breaker_enabled from the configured cache prefix', function () {
    config(['fuse.status_page.enabled' => true]);
    Gate::define('viewFuse', fn ($user = null) => true);
    config(['fuse.cache.prefix' => 'app1']);
    Cache::put('app1:enabled', false);

    $this->getJson('/fuse/data')
        ->assertOk()
        ->assertJsonPath('circuit_breaker_enabled', false);
});

it('renders the page with the current enabled flag', function () {
    config(['fuse.status_page.enabled' => true]);
    Gate::define('viewFuse', fn ($user = null) => true);
    config(['fuse.cache.prefix' => 'app1']);
    Cache::put('app1:enabled', false);

    $this->get('/fuse')
        ->assertSuccessful()
        ->assertViewHas('circuitBreakerEnabled', fn ($enabled) => $enabled === false)
        ->assertSee('render(initialData, false,', false);
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

it('returns 404 when the status page is disabled even with custom middleware', function () {
    config(['fuse.status_page.enabled' => false]);
    config(['fuse.status_page.middleware' => ['web']]);
    require __DIR__.'/../../routes/web.php';

    $this->get('/fuse')->assertNotFound();
    $this->getJson('/fuse/data')->assertNotFound();
});

it('lets custom middleware replace the viewFuse gate', function () {
    config(['fuse.status_page.enabled' => true]);
    config(['fuse.status_page.middleware' => ['web']]);
    Gate::define('viewFuse', fn ($user = null) => false);
    require __DIR__.'/../../routes/web.php';

    $this->get('/fuse')->assertSuccessful();
});

it('enforces the viewFuse gate when custom middleware includes it', function () {
    config(['fuse.status_page.enabled' => true]);
    config(['fuse.status_page.middleware' => ['web', 'can:viewFuse']]);
    Gate::define('viewFuse', fn ($user = null) => false);
    require __DIR__.'/../../routes/web.php';

    $this->get('/fuse')->assertForbidden();
});

it('returns 404 to a signed-in user when the status page is disabled behind auth middleware', function () {
    config(['fuse.status_page.enabled' => false]);
    config(['fuse.status_page.middleware' => ['web', 'auth']]);
    require __DIR__.'/../../routes/web.php';

    $this->actingAs(new GenericUser(['id' => 1]))->get('/fuse')->assertNotFound();
    $this->actingAs(new GenericUser(['id' => 1]))->getJson('/fuse/data')->assertNotFound();
});
