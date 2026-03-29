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
    config(['fuse.default_release' => 10]);
});

it('passes through when fuse is disabled via config', function () {
    config(['fuse.enabled' => false]);

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class
    {
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

it('uses the explicit middleware release over config values', function () {
    config([
        'fuse.default_release' => 10,
        'fuse.services.test-service.release' => 15,
    ]);

    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    $middleware = new CircuitBreakerMiddleware('test-service', release: 20);
    $job = new class
    {
        public int $releaseDelay = 0;

        public function release(int $delay): string
        {
            $this->releaseDelay = $delay;

            return 'released';
        }
    };

    $result = $middleware->handle($job, fn () => 'success');

    expect($job->releaseDelay)->toBe(20);
    expect($result)->toBe('released');
});

it('uses the per-service release when the middleware release is not provided', function () {
    config([
        'fuse.default_release' => 10,
        'fuse.services.test-service.release' => 15,
    ]);

    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class
    {
        public int $releaseDelay = 0;

        public function release(int $delay): string
        {
            $this->releaseDelay = $delay;

            return 'released';
        }
    };

    $result = $middleware->handle($job, fn () => 'success');

    expect($job->releaseDelay)->toBe(15);
    expect($result)->toBe('released');
});

it('uses the global default release when no explicit or per-service release is provided', function () {
    config([
        'fuse.default_release' => 12,
        'fuse.services.test-service' => [],
    ]);

    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class
    {
        public int $releaseDelay = 0;

        public function release(int $delay): string
        {
            $this->releaseDelay = $delay;

            return 'released';
        }
    };

    $result = $middleware->handle($job, fn () => 'success');

    expect($job->releaseDelay)->toBe(12);
    expect($result)->toBe('released');
});

it('falls back to 10 seconds when no release is configured anywhere', function () {
    config([
        'fuse.default_release' => null,
        'fuse.services.test-service' => [],
    ]);

    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class
    {
        public int $releaseDelay = 0;

        public function release(int $delay): string
        {
            $this->releaseDelay = $delay;

            return 'released';
        }
    };

    $result = $middleware->handle($job, fn () => 'success');

    expect($job->releaseDelay)->toBe(10);
    expect($result)->toBe('released');
});

it('passes through when fuse is disabled via cache', function () {
    config(['fuse.enabled' => true]);
    $prefix = config('fuse.cache.prefix');
    Cache::put("{$prefix}:enabled", false);

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class
    {
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
    $job = new class
    {
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
    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class
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
    $job = new class
    {
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
    $job = new class
    {
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
    $prefix = config('fuse.cache.prefix');
    Cache::put("{$prefix}:enabled", true);

    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class
    {
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

    expect($job->handled)->toBeFalse();
    expect($job->released)->toBeTrue();
});

it('executes probe and closes circuit on success in half-open state', function () {
    config(['fuse.default_timeout' => 1]);

    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    sleep(2);

    $breaker->isOpen();
    expect($breaker->isHalfOpen())->toBeTrue();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class
    {
        public bool $handled = false;

        public function release(int $delay): void {}
    };

    $middleware->handle($job, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($job->handled)->toBeTrue();

    $freshBreaker = new CircuitBreaker('test-service');
    expect($freshBreaker->isClosed())->toBeTrue();
});

it('reopens circuit on failure in half-open state', function () {
    config(['fuse.default_timeout' => 1]);

    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    sleep(2);
    $breaker->isOpen();
    expect($breaker->isHalfOpen())->toBeTrue();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class
    {
        public function release(int $delay): void {}
    };

    try {
        $middleware->handle($job, function () {
            throw new Exception('Service still down');
        });
    } catch (Exception) {
    }

    $freshBreaker = new CircuitBreaker('test-service');
    expect($freshBreaker->isOpen())->toBeTrue();
});

it('releases non-probe workers in half-open state', function () {
    config(['fuse.default_timeout' => 1]);

    $breaker = new CircuitBreaker('test-service');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    sleep(2);
    $breaker->isOpen();
    expect($breaker->isHalfOpen())->toBeTrue();
    $prefix = config('fuse.cache.prefix');
    $probeLock = Cache::lock("{$prefix}:test-service:probe", 5);
    expect($probeLock->get())->toBeTrue();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = new class
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

    $result = $middleware->handle($job, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($job->handled)->toBeFalse();
    expect($job->released)->toBeTrue();
    expect($job->releaseDelay)->toBe(10);
    expect($result)->toBe('released');

    $probeLock->forceRelease();
});
