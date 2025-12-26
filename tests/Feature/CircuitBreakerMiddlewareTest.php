<?php

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Middleware\CircuitBreakerMiddleware;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config(['fuse.enabled' => true]);
    config(['fuse.default_threshold' => 50]);
    config(['fuse.default_timeout' => 60]);
    config(['fuse.default_min_requests' => 5]);
});

it('passes through when fuse is disabled via config', function () {
    config(['fuse.enabled' => false]);

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class {
        public bool $handled = false;
        public bool $released = false;

        public function release(int $delay): void
        {
            $this->released = true;
        }
    };

    $middleware->handle($job, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($job->handled)->toBeTrue();
    expect($job->released)->toBeFalse();
});

it('passes through when fuse is disabled via cache', function () {
    config(['fuse.enabled' => true]);
    Cache::put('fuse:enabled', false);

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class {
        public bool $handled = false;

        public function release(int $delay): void {}
    };

    $middleware->handle($job, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($job->handled)->toBeTrue();
});

it('executes job when circuit is closed', function () {
    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class {
        public bool $handled = false;

        public function release(int $delay): void {}
    };

    $result = $middleware->handle($job, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($job->handled)->toBeTrue();
    expect($result)->toBe('success');
});

it('releases job when circuit is open', function () {
    // Trip the circuit using a breaker (shares cache with middleware's breaker)
    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class {
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

    $result = $middleware->handle($job, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($job->handled)->toBeFalse();
    expect($job->released)->toBeTrue();
    expect($job->releaseDelay)->toBe(10);
    expect($result)->toBe('released');
});

it('records success on successful job execution', function () {
    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class {
        public function release(int $delay): void {}
    };

    $middleware->handle($job, function () {
        return 'success';
    });

    $breaker = new CircuitBreaker('test-service');
    $stats = $breaker->getStats();

    expect($stats['attempts'])->toBe(1);
    expect($stats['failures'])->toBe(0);
});

it('records failure and rethrows exception on failed job', function () {
    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class {
        public function release(int $delay): void {}
    };

    expect(function () use ($middleware, $job) {
        $middleware->handle($job, function () {
            throw new Exception('Job failed');
        });
    })->toThrow(Exception::class, 'Job failed');

    $breaker = new CircuitBreaker('test-service');
    $stats = $breaker->getStats();

    expect($stats['attempts'])->toBe(1);
    expect($stats['failures'])->toBe(1);
});

it('cache override takes precedence over config for enabled state', function () {
    config(['fuse.enabled' => false]);
    Cache::put('fuse:enabled', true);

    // Trip the circuit first
    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class {
        public bool $handled = false;
        public bool $released = false;

        public function release(int $delay): void
        {
            $this->released = true;
        }
    };

    $middleware->handle($job, function ($job) {
        $job->handled = true;

        return 'success';
    });

    // Since cache says enabled=true and circuit is open, job should be released
    expect($job->handled)->toBeFalse();
    expect($job->released)->toBeTrue();
});

it('executes probe and closes circuit on success in half-open state', function () {
    config(['fuse.default_timeout' => 1]);

    // Trip the circuit
    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    // Wait for half-open
    sleep(2);

    // Verify it transitioned to half-open
    $breaker->isOpen(); // Trigger transition check
    expect($breaker->isHalfOpen())->toBeTrue();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class {
        public bool $handled = false;

        public function release(int $delay): void {}
    };

    // Execute job through middleware - should succeed and close circuit
    $middleware->handle($job, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($job->handled)->toBeTrue();

    // Circuit should now be closed
    $freshBreaker = new CircuitBreaker('test-service');
    expect($freshBreaker->isClosed())->toBeTrue();
});

it('reopens circuit on failure in half-open state', function () {
    config(['fuse.default_timeout' => 1]);

    // Trip the circuit
    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    // Wait for half-open
    sleep(2);
    $breaker->isOpen(); // Trigger transition
    expect($breaker->isHalfOpen())->toBeTrue();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class {
        public function release(int $delay): void {}
    };

    // Execute job that fails
    try {
        $middleware->handle($job, function () {
            throw new Exception('Service still down');
        });
    } catch (Exception $e) {
        // Expected
    }

    // Circuit should be back to open
    $freshBreaker = new CircuitBreaker('test-service');
    expect($freshBreaker->isOpen())->toBeTrue();
});
