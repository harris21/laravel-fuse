<?php

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Events\CircuitBreakerClosed;
use Harris21\Fuse\Events\CircuitBreakerHalfOpen;
use Harris21\Fuse\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Cache::flush();
});

it('dispatches CircuitBreakerOpened event when circuit opens', function () {
    Event::fake([CircuitBreakerOpened::class]);

    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

    // Trip the circuit
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    Event::assertDispatched(CircuitBreakerOpened::class, function ($event) {
        return $event->service === 'test-service'
            && $event->failureRate === 100.0
            && $event->attempts === 5
            && $event->failures === 5;
    });
});

it('dispatches CircuitBreakerHalfOpen event when circuit transitions to half-open', function () {
    Event::fake([CircuitBreakerOpened::class, CircuitBreakerHalfOpen::class]);

    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, timeout: 1, minRequests: 5);

    // Trip the circuit
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    // Wait for timeout and trigger transition
    sleep(2);
    $breaker->isOpen();

    Event::assertDispatched(CircuitBreakerHalfOpen::class, function ($event) {
        return $event->service === 'test-service';
    });
});

it('dispatches CircuitBreakerClosed event when circuit closes from half-open', function () {
    Event::fake([CircuitBreakerOpened::class, CircuitBreakerHalfOpen::class, CircuitBreakerClosed::class]);

    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, timeout: 1, minRequests: 5);

    // Trip the circuit
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    // Wait for half-open
    sleep(2);
    $breaker->isOpen();

    // Record success to close
    $breaker->recordSuccess();

    Event::assertDispatched(CircuitBreakerClosed::class, function ($event) {
        return $event->service === 'test-service';
    });
});

it('does not dispatch CircuitBreakerOpened event when already open', function () {
    Event::fake([CircuitBreakerOpened::class]);

    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

    // Trip the circuit
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    // Try to trip again
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    // Should only dispatch once
    Event::assertDispatchedTimes(CircuitBreakerOpened::class, 1);
});

it('CircuitBreakerOpened event contains correct data', function () {
    Event::fake([CircuitBreakerOpened::class]);

    $breaker = new CircuitBreaker('stripe', failureThreshold: 50, minRequests: 10);

    // Record 10 attempts: 4 successes first, then 6 failures (60% failure rate)
    // The circuit opens on the 10th attempt (6th failure) when threshold is exceeded
    for ($i = 0; $i < 4; $i++) {
        $breaker->recordSuccess();
    }
    for ($i = 0; $i < 6; $i++) {
        $breaker->recordFailure();
    }

    Event::assertDispatched(CircuitBreakerOpened::class, function ($event) {
        return $event->service === 'stripe'
            && $event->failureRate === 60.0
            && $event->attempts === 10
            && $event->failures === 6;
    });
});
