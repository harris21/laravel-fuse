<?php

namespace Harris21\Fuse\Middleware;

use Harris21\Fuse\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CircuitBreakerMiddleware
{
    public function __construct(
        private readonly string $service
    ) {}

    public function handle(mixed $job, callable $next): mixed
    {
        if (! $this->isEnabled()) {
            return $next($job);
        }

        $breaker = new CircuitBreaker($this->service);

        if ($breaker->isOpen()) {
            return $job->release(10);
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

            return $job->release(10);
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
        $cacheValue = Cache::get('fuse:enabled');

        if ($cacheValue !== null) {
            return (bool) $cacheValue;
        }

        return config('fuse.enabled', true);
    }
}
