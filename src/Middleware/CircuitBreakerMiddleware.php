<?php

namespace Harris21\Fuse\Middleware;

use Harris21\Fuse\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CircuitBreakerMiddleware
{
    private readonly int $releaseDelay;

    public function __construct(
        private readonly string $service,
        public readonly ?int $release = null,
    ) {
        $config = config("fuse.services.{$this->service}", []);

        $this->releaseDelay = $this->release
            ?? ($config['release'] ?? null)
            ?? config('fuse.default_release')
            ?? 10;
    }

    public function handle(mixed $job, callable $next): mixed
    {
        if (! $this->isEnabled()) {
            return $next($job);
        }

        $breaker = new CircuitBreaker($this->service);

        if ($breaker->isOpen()) {
            return $job->release($this->releaseDelay);
        }

        if ($breaker->isHalfOpen()) {
            $lock = Cache::lock($breaker->key('probe'), 5);

            if ($lock->get()) {
                try {
                    $result = $next($job);
                    $breaker->recordSuccess();

                    return $result;
                } catch (Throwable $e) {
                    $breaker->recordFailure($e);
                    throw $e;
                } finally {
                    $lock->forceRelease();
                }
            }

            return $job->release($this->releaseDelay);
        }

        try {
            $result = $next($job);
            $breaker->recordSuccess();

            return $result;
        } catch (Throwable $e) {
            $breaker->recordFailure($e);
            throw $e;
        }
    }

    private function isEnabled(): bool
    {
        $prefix = config('fuse.cache.prefix', 'fuse');
        $cacheValue = Cache::get("{$prefix}:enabled");

        if ($cacheValue !== null) {
            return (bool) $cacheValue;
        }

        return config('fuse.enabled', true);
    }
}
