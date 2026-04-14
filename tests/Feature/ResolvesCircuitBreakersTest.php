<?php

use Harris21\Fuse\Attributes\UseCircuitBreaker;
use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Middleware\CircuitBreakerMiddleware;
use Harris21\Fuse\Middleware\ResolvesCircuitBreakers;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config(['fuse.enabled' => true]);
    config(['fuse.default_threshold' => 50]);
    config(['fuse.default_min_requests' => 5]);
    config(['fuse.default_release' => 10]);
});

it('resolves a single circuit breaker attribute', function () {
    $job = new #[UseCircuitBreaker('stripe')] class
    {
        public function release(int $delay): string
        {
            return (string) $delay;
        }
    };

    $middleware = ResolvesCircuitBreakers::resolve($job);

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(CircuitBreakerMiddleware::class);
});

it('uses the explicit attribute release over config values', function () {
    config([
        'fuse.default_release' => 10,
        'fuse.services.stripe.release' => 15,
    ]);

    $breaker = new CircuitBreaker('stripe');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    $job = new #[UseCircuitBreaker('stripe', release: 20)] class
    {
        public int $releaseDelay = 0;

        public function release(int $delay): string
        {
            $this->releaseDelay = $delay;

            return 'released';
        }
    };

    $middleware = ResolvesCircuitBreakers::resolve($job);
    $result = $middleware[0]->handle($job, fn () => 'success');

    expect($job->releaseDelay)->toBe(20)
        ->and($result)->toBe('released');
});

it('uses the per-service release when the attribute release is not provided', function () {
    config([
        'fuse.default_release' => 10,
        'fuse.services.stripe.release' => 15,
    ]);

    $breaker = new CircuitBreaker('stripe');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    $job = new #[UseCircuitBreaker('stripe')] class
    {
        public int $releaseDelay = 0;

        public function release(int $delay): string
        {
            $this->releaseDelay = $delay;

            return 'released';
        }
    };

    $middleware = ResolvesCircuitBreakers::resolve($job);
    $result = $middleware[0]->handle($job, fn () => 'success');

    expect($job->releaseDelay)->toBe(15)
        ->and($result)->toBe('released');
});

it('uses the global default release when no explicit or per-service release is provided', function () {
    config([
        'fuse.default_release' => 12,
        'fuse.services.stripe' => [],
    ]);

    $breaker = new CircuitBreaker('stripe');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    $job = new #[UseCircuitBreaker('stripe')] class
    {
        public int $releaseDelay = 0;

        public function release(int $delay): string
        {
            $this->releaseDelay = $delay;

            return 'released';
        }
    };

    $middleware = ResolvesCircuitBreakers::resolve($job);
    $result = $middleware[0]->handle($job, fn () => 'success');

    expect($job->releaseDelay)->toBe(12)
        ->and($result)->toBe('released');
});

it('falls back to 10 seconds when no release is configured anywhere', function () {
    config([
        'fuse.default_release' => null,
        'fuse.services.stripe' => [],
    ]);

    $breaker = new CircuitBreaker('stripe');
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    $job = new #[UseCircuitBreaker('stripe')] class
    {
        public int $releaseDelay = 0;

        public function release(int $delay): string
        {
            $this->releaseDelay = $delay;

            return 'released';
        }
    };

    $middleware = ResolvesCircuitBreakers::resolve($job);
    $result = $middleware[0]->handle($job, fn () => 'success');

    expect($job->releaseDelay)->toBe(10)
        ->and($result)->toBe('released');
});

it('returns an empty array when the job has no fuse attributes', function () {
    $middleware = ResolvesCircuitBreakers::resolve(new class
    {
        public function release(int $delay): string
        {
            return (string) $delay;
        }
    });

    expect($middleware)->toBe([]);
});

it('resolves repeated circuit breaker attributes in declaration order', function () {
    $job = new #[UseCircuitBreaker('stripe')]
    #[UseCircuitBreaker('mailgun', release: 30)] class
    {
        public function release(int $delay): string
        {
            return (string) $delay;
        }
    };

    $middleware = ResolvesCircuitBreakers::resolve($job);

    expect($middleware)->toHaveCount(2)
        ->and($middleware[0])->toBeInstanceOf(CircuitBreakerMiddleware::class)
        ->and($middleware[1])->toBeInstanceOf(CircuitBreakerMiddleware::class);
});

it('prepends attribute middleware before manually supplied middleware', function () {
    $job = new #[UseCircuitBreaker('stripe')] class
    {
        public function release(int $delay): string
        {
            return (string) $delay;
        }
    };

    $customMiddleware = new class
    {
        public function handle(mixed $job, callable $next): mixed
        {
            return $next($job);
        }
    };

    $middleware = ResolvesCircuitBreakers::merge($job, [$customMiddleware]);

    expect($middleware)->toHaveCount(2)
        ->and($middleware[0])->toBeInstanceOf(CircuitBreakerMiddleware::class)
        ->and($middleware[1])->toBe($customMiddleware);
});
