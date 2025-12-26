<?php

use Harris21\Fuse\CircuitBreaker;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
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

    // Record 5 failures (100% failure rate, above 50% threshold)
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->getState())->toBe('open');
    expect($breaker->isOpen())->toBeTrue();
});

it('does not open circuit if below minimum requests', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 10);

    // Record only 5 failures (below 10 min requests)
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->getState())->toBe('closed');
});

it('does not open circuit if below failure threshold', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 10);

    // Record 10 attempts: 4 failures, 6 successes (40% failure rate)
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

    // Trip the circuit
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    // Wait for timeout
    sleep(2);

    // isOpen() should trigger the transition to half-open
    expect($breaker->isOpen())->toBeFalse();
    expect($breaker->isHalfOpen())->toBeTrue();
});

it('transitions to closed on success in half-open state', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, timeout: 1, minRequests: 5);

    // Trip the circuit
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    // Wait for half-open
    sleep(2);
    $breaker->isOpen(); // Trigger transition

    expect($breaker->isHalfOpen())->toBeTrue();

    // Record success
    $breaker->recordSuccess();

    expect($breaker->isClosed())->toBeTrue();
});

it('transitions back to open on failure in half-open state', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, timeout: 1, minRequests: 5);

    // Trip the circuit
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    // Wait for half-open
    sleep(2);
    $breaker->isOpen(); // Trigger transition

    expect($breaker->isHalfOpen())->toBeTrue();

    // Record failure
    $breaker->recordFailure();

    expect($breaker->isOpen())->toBeTrue();
});

it('resets to closed state', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

    // Trip the circuit
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    // Reset
    $breaker->reset();

    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->getStats()['attempts'])->toBe(0);
    expect($breaker->getStats()['failures'])->toBe(0);
});

it('returns correct stats', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, timeout: 60, minRequests: 10);

    // Record some activity
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

    // Trip the circuit
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isAvailable())->toBeFalse();
});

it('maintains separate state for different services', function () {
    $stripe = new CircuitBreaker('stripe', failureThreshold: 50, minRequests: 5);
    $mailgun = new CircuitBreaker('mailgun', failureThreshold: 50, minRequests: 5);

    // Trip only the stripe circuit
    for ($i = 0; $i < 5; $i++) {
        $stripe->recordFailure();
    }

    // Stripe should be open, mailgun should still be closed
    expect($stripe->isOpen())->toBeTrue();
    expect($mailgun->isClosed())->toBeTrue();

    // Verify stats are independent
    expect($stripe->getStats()['failures'])->toBe(5);
    expect($mailgun->getStats()['failures'])->toBe(0);
});
