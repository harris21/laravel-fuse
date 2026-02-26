<?php

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Events\CircuitBreakerClosed;
use Harris21\Fuse\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Cache::flush();
    config(['fuse.services' => [
        'stripe' => ['threshold' => 50, 'min_requests' => 5],
        'mailgun' => ['threshold' => 50, 'min_requests' => 5],
    ]]);
});

it('displays status of all configured services', function () {
    $this->artisan('fuse:status')
        ->assertExitCode(0);
});

it('displays status of a specific service', function () {
    $this->artisan('fuse:status stripe')
        ->assertExitCode(0);
});

it('warns when no services are configured', function () {
    config(['fuse.services' => []]);

    $this->artisan('fuse:status')
        ->expectsOutput('No services configured in config/fuse.php')
        ->assertExitCode(0);
});

it('warns when service is not configured', function () {
    $this->artisan('fuse:status unknown-service')
        ->expectsOutput("Service 'unknown-service' is not configured in config/fuse.php")
        ->assertExitCode(0);
});

it('warns when service is not configured in fuse:reset', function () {
    $this->artisan('fuse:reset unknown-service')
        ->expectsOutput("Service 'unknown-service' is not configured in config/fuse.php")
        ->assertExitCode(0);
});

it('warns when service is not configured in fuse:open', function () {
    $this->artisan('fuse:open unknown-service')
        ->expectsOutput("Service 'unknown-service' is not configured in config/fuse.php")
        ->assertExitCode(0);
});

it('warns when service is not configured in fuse:close', function () {
    $this->artisan('fuse:close unknown-service')
        ->expectsOutput("Service 'unknown-service' is not configured in config/fuse.php")
        ->assertExitCode(0);
});

it('resets a specific circuit breaker', function () {
    $breaker = new CircuitBreaker('stripe');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    $this->artisan('fuse:reset stripe')
        ->assertExitCode(0);

    expect($breaker->isClosed())->toBeTrue();
});

it('resets all circuit breakers when no service is provided', function () {
    $stripe = new CircuitBreaker('stripe');
    $mailgun = new CircuitBreaker('mailgun');
    for ($i = 0; $i < 5; $i++) {
        $stripe->recordFailure();
        $mailgun->recordFailure();
    }
    expect($stripe->isOpen())->toBeTrue()
        ->and($mailgun->isOpen())->toBeTrue();

    $this->artisan('fuse:reset')
        ->assertExitCode(0);

    expect($stripe->isClosed())->toBeTrue()
        ->and($mailgun->isClosed())->toBeTrue();
});

it('warns when no services are configured for reset', function () {
    config(['fuse.services' => []]);
    $this->artisan('fuse:reset')
        ->expectsOutput('No services configured in config/fuse.php')
        ->assertExitCode(0);
});

it('manually opens a circuit breaker', function () {
    $breaker = new CircuitBreaker('stripe');
    expect($breaker->isClosed())->toBeTrue();

    $this->artisan('fuse:open stripe')
        ->assertExitCode(0);

    expect($breaker->isOpen())->toBeTrue();
});

it('dispatches CircuitBreakerOpened event when force opening', function () {
    Event::fake([CircuitBreakerOpened::class]);

    $this->artisan('fuse:open stripe')
        ->assertExitCode(0);

    Event::assertDispatched(CircuitBreakerOpened::class, fn ($event) => $event->service === 'stripe');
});

it('manually closes a circuit breaker', function () {
    $breaker = new CircuitBreaker('stripe');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }
    expect($breaker->isOpen())->toBeTrue();

    $this->artisan('fuse:close stripe')
        ->assertExitCode(0);

    expect($breaker->isClosed())->toBeTrue();
});

it('dispatches CircuitBreakerClosed event when force closing', function () {
    Event::fake([CircuitBreakerClosed::class]);

    $breaker = new CircuitBreaker('stripe');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    $this->artisan('fuse:close stripe')
        ->assertExitCode(0);

    Event::assertDispatched(CircuitBreakerClosed::class, fn ($event) => $event->service === 'stripe');
});
