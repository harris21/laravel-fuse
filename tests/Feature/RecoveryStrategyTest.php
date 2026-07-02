<?php

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Contracts\RecoveryStrategy;
use Harris21\Fuse\Middleware\CircuitBreakerMiddleware;
use Harris21\Fuse\Strategies\SingleProbe;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config(['fuse.enabled' => true]);
    config(['fuse.default_threshold' => 50]);
    config(['fuse.default_timeout' => 60]);
    config(['fuse.default_min_requests' => 5]);
    config(['fuse.default_release' => 10]);
});

function tripToHalfOpen(string $service = 'test-service'): CircuitBreaker
{
    config(['fuse.default_timeout' => 1]);

    $breaker = new CircuitBreaker($service);
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    sleep(2);
    $breaker->isOpen();
    expect($breaker->isHalfOpen())->toBeTrue();

    return $breaker;
}

function makeJob(): object
{
    return new class
    {
        public bool $handled = false;

        public bool $released = false;

        public int $releaseDelay = 0;

        public function release(int $delay): string
        {
            $this->released = true;
            $this->releaseDelay = $delay;

            return 'released';
        }
    };
}

it('defaults to the SingleProbe strategy when none is configured', function () {
    $breaker = new CircuitBreaker('test-service');

    expect($breaker->recoveryStrategy())->toBeInstanceOf(SingleProbe::class);
});

it('resolves the configured recovery strategy through the container', function () {
    $strategy = new class implements RecoveryStrategy
    {
        public function allowsAttempt(CircuitBreaker $breaker): bool
        {
            return true;
        }

        public function recordSuccess(CircuitBreaker $breaker): bool
        {
            return true;
        }

        public function recordFailure(CircuitBreaker $breaker): void {}
    };

    app()->bind('custom-strategy', fn () => $strategy);
    config(['fuse.services.test-service.recovery_strategy' => 'custom-strategy']);

    $breaker = new CircuitBreaker('test-service');

    expect($breaker->recoveryStrategy())->toBe($strategy);
});

it('does not invoke the recovery strategy on a success while the circuit is closed', function () {
    $spy = new class implements RecoveryStrategy
    {
        public int $successes = 0;

        public function allowsAttempt(CircuitBreaker $breaker): bool
        {
            return true;
        }

        public function recordSuccess(CircuitBreaker $breaker): bool
        {
            $this->successes++;

            return true;
        }

        public function recordFailure(CircuitBreaker $breaker): void {}
    };

    app()->bind('counting-strategy', fn () => $spy);
    config(['fuse.services.test-service.recovery_strategy' => 'counting-strategy']);

    $middleware = new CircuitBreakerMiddleware('test-service');
    $middleware->handle(makeJob(), fn () => 'success');

    expect($spy->successes)->toBe(0);
});

it('throws when the recovery strategy does not implement the contract', function () {
    app()->bind('bad-strategy', fn () => new stdClass);
    config(['fuse.services.test-service.recovery_strategy' => 'bad-strategy']);

    new CircuitBreaker('test-service');
})->throws(InvalidArgumentException::class);

it('keeps the default single-probe behavior: closes on first success', function () {
    tripToHalfOpen();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = makeJob();

    $middleware->handle($job, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($job->handled)->toBeTrue();
    expect((new CircuitBreaker('test-service'))->isClosed())->toBeTrue();
});

it('single-probe releases concurrent workers while one probe runs', function () {
    tripToHalfOpen();

    $prefix = config('fuse.cache.prefix');
    $probeLock = Cache::lock("{$prefix}:test-service:probe", 5);
    expect($probeLock->get())->toBeTrue();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = makeJob();

    $result = $middleware->handle($job, fn () => 'success');

    expect($job->handled)->toBeFalse();
    expect($job->released)->toBeTrue();
    expect($result)->toBe('released');

    $probeLock->forceRelease();
});

it('reopens the circuit when the probe fails in half-open', function () {
    tripToHalfOpen();

    $middleware = new CircuitBreakerMiddleware('test-service');

    try {
        $middleware->handle(makeJob(), function () {
            throw new Exception('Service still down');
        });
    } catch (Exception) {
    }

    expect((new CircuitBreaker('test-service'))->isOpen())->toBeTrue();
});

it('lets a custom strategy keep the circuit half-open by returning false on success', function () {
    $strategy = new class implements RecoveryStrategy
    {
        public function allowsAttempt(CircuitBreaker $breaker): bool
        {
            return true;
        }

        public function recordSuccess(CircuitBreaker $breaker): bool
        {
            return false;
        }

        public function recordFailure(CircuitBreaker $breaker): void {}
    };

    app()->bind('ramping-strategy', fn () => $strategy);
    config(['fuse.services.test-service.recovery_strategy' => 'ramping-strategy']);

    tripToHalfOpen();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = makeJob();

    $middleware->handle($job, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($job->handled)->toBeTrue();
    expect((new CircuitBreaker('test-service'))->isHalfOpen())->toBeTrue();
});

it('releases the job when a custom strategy denies the attempt', function () {
    $strategy = new class implements RecoveryStrategy
    {
        public function allowsAttempt(CircuitBreaker $breaker): bool
        {
            return false;
        }

        public function recordSuccess(CircuitBreaker $breaker): bool
        {
            return true;
        }

        public function recordFailure(CircuitBreaker $breaker): void {}
    };

    app()->bind('denying-strategy', fn () => $strategy);
    config(['fuse.services.test-service.recovery_strategy' => 'denying-strategy']);

    tripToHalfOpen();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = makeJob();

    $result = $middleware->handle($job, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($job->handled)->toBeFalse();
    expect($job->released)->toBeTrue();
    expect($result)->toBe('released');
});

it('invokes the custom strategy hooks through the middleware in half-open', function () {
    $spy = new class implements RecoveryStrategy
    {
        public bool $allowed = false;

        public bool $succeeded = false;

        public function allowsAttempt(CircuitBreaker $breaker): bool
        {
            $this->allowed = true;

            return true;
        }

        public function recordSuccess(CircuitBreaker $breaker): bool
        {
            $this->succeeded = true;

            return true;
        }

        public function recordFailure(CircuitBreaker $breaker): void {}
    };

    app()->bind('spy-strategy', fn () => $spy);
    config(['fuse.services.test-service.recovery_strategy' => 'spy-strategy']);

    tripToHalfOpen();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $middleware->handle(makeJob(), fn () => 'success');

    expect($spy->allowed)->toBeTrue();
    expect($spy->succeeded)->toBeTrue();
});
