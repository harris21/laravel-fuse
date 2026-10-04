<?php

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Events\CircuitBreakerClosed;
use Harris21\Fuse\Events\CircuitBreakerHalfOpen;
use Harris21\Fuse\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    Cache::flush();
    config(['fuse.services.test-service' => [
        'threshold' => 50,
        'timeout' => 60,
        'min_requests' => 5,
    ]]);
});

it('dispatches CircuitBreakerOpened event when circuit opens', function () {
    Event::fake([CircuitBreakerOpened::class]);

    $breaker = new CircuitBreaker('test-service');

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

    config(['fuse.services.test-service.timeout' => 1]);

    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    sleep(2);
    $breaker->isOpen();

    Event::assertDispatched(CircuitBreakerHalfOpen::class, function ($event) {
        return $event->service === 'test-service';
    });
});

it('dispatches CircuitBreakerClosed event when circuit closes from half-open', function () {
    Event::fake([CircuitBreakerOpened::class, CircuitBreakerHalfOpen::class, CircuitBreakerClosed::class]);

    config(['fuse.services.test-service.timeout' => 1]);

    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    sleep(2);
    $breaker->isOpen();

    $breaker->recordSuccess();

    Event::assertDispatched(CircuitBreakerClosed::class, function ($event) {
        return $event->service === 'test-service';
    });
});

it('does not dispatch CircuitBreakerOpened event when already open', function () {
    Event::fake([CircuitBreakerOpened::class]);

    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    Event::assertDispatchedTimes(CircuitBreakerOpened::class, 1);
});

it('CircuitBreakerOpened event contains correct data', function () {
    Event::fake([CircuitBreakerOpened::class]);

    config(['fuse.services.stripe' => [
        'threshold' => 50,
        'min_requests' => 10,
    ]]);

    $breaker = new CircuitBreaker('stripe');

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

it('dispatches CircuitBreakerOpened event when probe fails in half-open state', function () {
    Event::fake([CircuitBreakerOpened::class, CircuitBreakerHalfOpen::class]);

    config(['fuse.services.test-service.timeout' => 1]);

    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    sleep(2);
    $breaker->isOpen();

    expect($breaker->isHalfOpen())->toBeTrue();

    Event::fake([CircuitBreakerOpened::class]);

    $breaker->recordFailure();

    Event::assertDispatched(CircuitBreakerOpened::class, function ($event) {
        return $event->service === 'test-service'
            && $event->failureRate === 100.0
            && $event->attempts === 1
            && $event->failures === 1;
    });
});

it('reports a listener that throws instead of letting the exception escape', function () {
    Exceptions::fake();
    Event::listen(CircuitBreakerOpened::class, fn () => throw new RuntimeException('listener down'));

    $breaker = new CircuitBreaker('test-service');

    expect(fn () => $breaker->forceOpen())->not->toThrow(RuntimeException::class);
    expect($breaker->isOpen())->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'listener down');
});

it('releases the probe lock when a CircuitBreakerOpened listener throws on a failed probe', function () {
    $breaker = forceHalfOpen();
    expect($breaker->recoveryStrategy()->allowsAttempt($breaker))->toBeTrue();

    Exceptions::fake();
    Event::listen(CircuitBreakerOpened::class, fn () => throw new RuntimeException('listener down'));

    expect(fn () => $breaker->recordFailure(new RuntimeException('probe failed')))->not->toThrow(RuntimeException::class);
    expect($breaker->isOpen())->toBeTrue();
    expect(Cache::lock($breaker->key('probe'), 5)->get())->toBeTrue();
});

it('releases the probe lock when both a CircuitBreakerOpened listener and the reporter throw', function () {
    $breaker = forceHalfOpen();
    expect($breaker->recoveryStrategy()->allowsAttempt($breaker))->toBeTrue();

    Event::listen(CircuitBreakerOpened::class, fn () => throw new RuntimeException('listener down'));
    Exceptions::reportable(fn (RuntimeException $e) => throw new LogicException('reporter down'));

    expect(fn () => $breaker->recordFailure(new RuntimeException('probe failed')))->not->toThrow(LogicException::class);
    expect($breaker->isOpen())->toBeTrue();
    expect(Cache::lock($breaker->key('probe'), 5)->get())->toBeTrue();
});
