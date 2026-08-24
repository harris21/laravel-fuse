<?php

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Events\CircuitBreakerClosed;
use Harris21\Fuse\Events\CircuitBreakerOpened;
use Harris21\Fuse\Strategies\OldestJobProbe;
use Illuminate\Support\Facades\Artisan;
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

it('clears an elected probe when manually closing a circuit breaker', function () {
    config(['fuse.services.stripe.recovery_strategy' => OldestJobProbe::class]);
    $breaker = new CircuitBreaker('stripe');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    Cache::put('fuse:stripe:probe-candidate', ['uuid' => 'abc-123', 'created_at' => 1000, 'name' => 'App\Jobs\ChargeCustomer'], 60);
    Cache::put('fuse:probe-candidate:abc-123', 'stripe', 60);

    $this->artisan('fuse:close stripe')->assertExitCode(0);

    expect(Cache::get('fuse:stripe:probe-candidate'))->toBeNull()
        ->and(Cache::get('fuse:probe-candidate:abc-123'))->toBeNull();
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

it('does not redispatch CircuitBreakerOpened when the circuit is already open', function () {
    $this->artisan('fuse:open stripe')->assertExitCode(0);

    Event::fake([CircuitBreakerOpened::class]);

    $this->artisan('fuse:open stripe')->assertExitCode(0);

    Event::assertNotDispatched(CircuitBreakerOpened::class);
});

it('reports that the circuit was already open when fuse:open is repeated', function () {
    $this->artisan('fuse:open stripe')
        ->expectsOutput('Circuit breaker for stripe has been manually opened.')
        ->assertExitCode(0);

    $this->artisan('fuse:open stripe')
        ->expectsOutput('Circuit breaker for stripe was already open.')
        ->assertExitCode(0);
});

it('still recovers automatically after the timeout when the circuit was manually opened', function () {
    config(['fuse.default_timeout' => 1]);

    $this->artisan('fuse:open stripe')->assertExitCode(0);

    $breaker = new CircuitBreaker('stripe');
    expect($breaker->isOpen())->toBeTrue();

    sleep(2);

    expect($breaker->isOpen())->toBeFalse()
        ->and($breaker->isHalfOpen())->toBeTrue();
});

it('clears opened_at when manually closing a previously open circuit', function () {
    $breaker = new CircuitBreaker('stripe');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }
    expect($breaker->isOpen())->toBeTrue();
    expect($breaker->getStats()['opened_at'])->not->toBeNull();

    $this->artisan('fuse:close stripe')->assertExitCode(0);

    expect($breaker->getStats()['opened_at'])->toBeNull();
});

it('does not redispatch CircuitBreakerClosed when the circuit is already closed', function () {
    $breaker = new CircuitBreaker('stripe');
    expect($breaker->isClosed())->toBeTrue();

    Event::fake([CircuitBreakerClosed::class]);

    $this->artisan('fuse:close stripe')->assertExitCode(0);

    Event::assertNotDispatched(CircuitBreakerClosed::class);
});

it('reports that the circuit was already closed when fuse:close is repeated', function () {
    $breaker = new CircuitBreaker('stripe');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }
    expect($breaker->isOpen())->toBeTrue();

    $this->artisan('fuse:close stripe')
        ->expectsOutput('Circuit breaker for stripe has been manually closed.')
        ->assertExitCode(0);

    $this->artisan('fuse:close stripe')
        ->expectsOutput('Circuit breaker for stripe was already closed.')
        ->assertExitCode(0);
});

it('renders json output for fuse:status --json', function () {
    config(['fuse.services' => [
        'stripe' => ['threshold' => 50, 'timeout' => 30, 'min_requests' => 5],
    ]]);

    $exitCode = Artisan::call('fuse:status', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"service": "stripe"');
    expect($output)->toContain('"threshold": 50');
});

it('renders json output for a single service', function () {
    config(['fuse.services' => [
        'stripe' => ['threshold' => 50, 'timeout' => 30, 'min_requests' => 5],
        'mailgun' => ['threshold' => 60, 'timeout' => 45, 'min_requests' => 10],
    ]]);

    $exitCode = Artisan::call('fuse:status', ['service' => 'stripe', '--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"service": "stripe"');
    expect($output)->not->toContain('"service": "mailgun"');
});

it('includes the elected probe job in fuse:status --json', function () {
    config(['fuse.services' => [
        'stripe' => ['threshold' => 50, 'timeout' => 30, 'min_requests' => 5, 'recovery_strategy' => OldestJobProbe::class],
    ]]);
    (new CircuitBreaker('stripe'))->forceOpen();
    Cache::put('fuse:stripe:probe-candidate', ['uuid' => 'abc-123', 'created_at' => 1000, 'name' => 'App\\Jobs\\ChargeCustomer'], 60);

    Artisan::call('fuse:status', ['--json' => true]);
    $output = Artisan::output();

    expect($output)->toContain('"probe_candidate"');
    expect($output)->toContain('"uuid": "abc-123"');
    expect($output)->toContain('"name": "App\\\\Jobs\\\\ChargeCustomer"');
});

it('shows the elected probe job in the fuse:status table', function () {
    config(['fuse.services' => [
        'stripe' => ['threshold' => 50, 'timeout' => 30, 'min_requests' => 5, 'recovery_strategy' => OldestJobProbe::class],
    ]]);
    (new CircuitBreaker('stripe'))->forceOpen();
    Cache::put('fuse:stripe:probe-candidate', ['uuid' => 'abc-123', 'created_at' => 1000, 'name' => 'App\\Jobs\\ChargeCustomer'], 60);

    $this->artisan('fuse:status')
        ->expectsOutputToContain('Probe')
        ->expectsOutputToContain('ChargeCustomer')
        ->assertExitCode(0);
});
