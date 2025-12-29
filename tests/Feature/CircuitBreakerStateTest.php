<?php

use Carbon\Carbon;
use Harris21\Fuse\CircuitBreaker;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(Carbon::createFromTime(12, 0, 0));
});

afterEach(function () {
    Carbon::setTestNow(null);
});

it('starts in closed state', function () {
    $breaker = new CircuitBreaker('test-service');

    expect($breaker->getState())->toBe('closed');
    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->isOpen())->toBeFalse();
    expect($breaker->isHalfOpen())->toBeFalse();
});

it('transitions to open when failure threshold is exceeded', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->getState())->toBe('open');
    expect($breaker->isOpen())->toBeTrue();
});

it('does not open circuit if below minimum requests', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 10);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->getState())->toBe('closed');
});

it('does not open circuit if below failure threshold', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 10);

    for ($i = 0; $i < 4; $i++) {
        $breaker->recordFailure();
    }
    for ($i = 0; $i < 6; $i++) {
        $breaker->recordSuccess();
    }

    expect($breaker->getState())->toBe('closed');
});

it('transitions to half-open after timeout', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, timeout: 1, minRequests: 5);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    sleep(2);

    expect($breaker->isOpen())->toBeFalse();
    expect($breaker->isHalfOpen())->toBeTrue();
});

it('transitions to closed on success in half-open state', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, timeout: 1, minRequests: 5);

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
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, timeout: 1, minRequests: 5);

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
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

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
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, timeout: 60, minRequests: 10);

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

it('constructor params override config', function () {
    config(['fuse.services.stripe' => [
        'threshold' => 30,
        'timeout' => 120,
        'min_requests' => 3,
    ]]);

    $breaker = new CircuitBreaker('stripe', failureThreshold: 80, timeout: 30, minRequests: 20);
    $stats = $breaker->getStats();

    expect($stats['threshold'])->toBe(80);
    expect($stats['timeout'])->toBe(30);
    expect($stats['min_requests'])->toBe(20);
});

it('is available when closed', function () {
    $breaker = new CircuitBreaker('test-service');

    expect($breaker->isAvailable())->toBeTrue();
});

it('is not available when open', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isAvailable())->toBeFalse();
});

it('maintains separate state for different services', function () {
    $stripe = new CircuitBreaker('stripe', failureThreshold: 50, minRequests: 5);
    $mailgun = new CircuitBreaker('mailgun', failureThreshold: 50, minRequests: 5);

    for ($i = 0; $i < 5; $i++) {
        $stripe->recordFailure();
    }

    expect($stripe->isOpen())->toBeTrue();
    expect($mailgun->isClosed())->toBeTrue();

    expect($stripe->getStats()['failures'])->toBe(5);
    expect($mailgun->getStats()['failures'])->toBe(0);
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
