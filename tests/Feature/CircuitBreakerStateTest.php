<?php

use Carbon\Carbon;
use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Enums\CircuitState;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(Carbon::createFromTime(12, 0, 0));
    config(['fuse.services.test-service' => [
        'threshold' => 50,
        'timeout' => 60,
        'min_requests' => 5,
    ]]);
});

afterEach(function () {
    Carbon::setTestNow(null);
});

it('starts in closed state', function () {
    $breaker = new CircuitBreaker('test-service');

    expect($breaker->getState())->toBe(CircuitState::Closed);
    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->isOpen())->toBeFalse();
    expect($breaker->isHalfOpen())->toBeFalse();
});

it('transitions to open when failure threshold is exceeded', function () {
    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->getState())->toBe(CircuitState::Open);
    expect($breaker->isOpen())->toBeTrue();
});

it('does not open circuit if below minimum requests', function () {
    config(['fuse.services.test-service.min_requests' => 10]);

    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->getState())->toBe(CircuitState::Closed);
});

it('does not open circuit if below failure threshold', function () {
    config(['fuse.services.test-service.min_requests' => 10]);

    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 4; $i++) {
        $breaker->recordFailure();
    }
    for ($i = 0; $i < 6; $i++) {
        $breaker->recordSuccess();
    }

    expect($breaker->getState())->toBe(CircuitState::Closed);
});

it('transitions to half-open after timeout', function () {
    config(['fuse.services.test-service.timeout' => 1]);

    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    sleep(2);

    expect($breaker->isOpen())->toBeFalse();
    expect($breaker->isHalfOpen())->toBeTrue();
});

it('transitions to closed on success in half-open state', function () {
    config(['fuse.services.test-service.timeout' => 1]);

    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    sleep(2);
    $breaker->isOpen();

    expect($breaker->isHalfOpen())->toBeTrue();

    $breaker->recordSuccess();

    expect($breaker->isClosed())->toBeTrue();
});

it('transitions back to open on failure in half-open state', function () {
    config(['fuse.services.test-service.timeout' => 1]);

    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    sleep(2);
    $breaker->isOpen();

    expect($breaker->isHalfOpen())->toBeTrue();

    $breaker->recordFailure();

    expect($breaker->isOpen())->toBeTrue();
});

it('resets to closed state', function () {
    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    $breaker->reset();

    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->getStats()['attempts'])->toBe(0);
    expect($breaker->getStats()['failures'])->toBe(0);
});

it('returns correct stats', function () {
    config(['fuse.services.test-service' => [
        'threshold' => 50,
        'timeout' => 60,
        'min_requests' => 10,
    ]]);

    $breaker = new CircuitBreaker('test-service');

    $breaker->recordSuccess();
    $breaker->recordSuccess();
    $breaker->recordFailure();

    $stats = $breaker->getStats();

    expect($stats['state'])->toBe('closed');
    expect($stats['attempts'])->toBe(3);
    expect($stats['failures'])->toBe(1);
    expect($stats['failure_rate'])->toBe(33.3);
    expect($stats['threshold'])->toBe(50);
    expect($stats['timeout'])->toBe(60);
    expect($stats['min_requests'])->toBe(10);
});

it('uses service-specific config', function () {
    config(['fuse.services.stripe' => [
        'threshold' => 30,
        'timeout' => 120,
        'min_requests' => 3,
    ]]);

    $breaker = new CircuitBreaker('stripe');
    $stats = $breaker->getStats();

    expect($stats['threshold'])->toBe(30);
    expect($stats['timeout'])->toBe(120);
    expect($stats['min_requests'])->toBe(3);
});

it('maintains separate state for different services', function () {
    config(['fuse.services.stripe' => [
        'threshold' => 50,
        'min_requests' => 5,
    ]]);
    config(['fuse.services.mailgun' => [
        'threshold' => 50,
        'min_requests' => 5,
    ]]);

    $stripe = new CircuitBreaker('stripe');
    $mailgun = new CircuitBreaker('mailgun');

    for ($i = 0; $i < 5; $i++) {
        $stripe->recordFailure();
    }

    expect($stripe->isOpen())->toBeTrue();
    expect($mailgun->isClosed())->toBeTrue();

    expect($stripe->getStats()['failures'])->toBe(5);
    expect($mailgun->getStats()['failures'])->toBe(0);
});

it('uses custom cache prefix in keys', function () {
    config(['fuse.cache.prefix' => 'my-app']);

    $breaker = new CircuitBreaker('test-service');

    expect($breaker->key('state'))->toBe('my-app:test-service:state');
    expect($breaker->key('opened_at'))->toBe('my-app:test-service:opened_at');

    $breaker->recordFailure();

    expect(Cache::get('my-app:test-service:state') ?? 'closed')->toBe('closed');
});

it('uses peak hours threshold during peak hours via ThresholdCalculator', function () {
    Carbon::setTestNow(Carbon::createFromTime(12, 0, 0));

    config(['fuse.services.stripe' => [
        'threshold' => 40,
        'peak_hours_threshold' => 70,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    $breaker = new CircuitBreaker('stripe');
    $stats = $breaker->getStats();

    expect($stats['threshold'])->toBe(70);
});

it('uses regular threshold during off-peak hours via ThresholdCalculator', function () {
    Carbon::setTestNow(Carbon::createFromTime(22, 0, 0));

    config(['fuse.services.stripe' => [
        'threshold' => 40,
        'peak_hours_threshold' => 70,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    $breaker = new CircuitBreaker('stripe');
    $stats = $breaker->getStats();

    expect($stats['threshold'])->toBe(40);
});

it('trips at different thresholds based on time of day', function () {
    $serviceConfig = [
        'threshold' => 40,
        'peak_hours_threshold' => 80,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
        'min_requests' => 10,
    ];
    config(['fuse.services.stripe-peak' => $serviceConfig]);
    config(['fuse.services.stripe-offpeak' => $serviceConfig]);

    Carbon::setTestNow(Carbon::createFromTime(12, 0, 0));
    $peakBreaker = new CircuitBreaker('stripe-peak');

    for ($i = 0; $i < 3; $i++) {
        $peakBreaker->recordSuccess();
    }
    for ($i = 0; $i < 7; $i++) {
        $peakBreaker->recordFailure();
    }

    expect($peakBreaker->isClosed())->toBeTrue();

    Carbon::setTestNow(Carbon::createFromTime(22, 0, 0));
    $offPeakBreaker = new CircuitBreaker('stripe-offpeak');

    for ($i = 0; $i < 5; $i++) {
        $offPeakBreaker->recordSuccess();
    }
    for ($i = 0; $i < 5; $i++) {
        $offPeakBreaker->recordFailure();
    }

    expect($offPeakBreaker->isOpen())->toBeTrue();
});
