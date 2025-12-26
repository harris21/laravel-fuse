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

    /**
     * Process the job through the circuit breaker.
     */
    public function handle(mixed $job, callable $next): mixed
    {
        // Check if circuit breaker is enabled
        if (! $this->isEnabled()) {
            return $next($job);
        }

        $breaker = new CircuitBreaker($this->service);

        // Circuit OPEN: Delay the job, don't fail it!
        if ($breaker->isOpen()) {
            // Job will retry in 10 seconds
            // Appears as "Delayed" in Horizon, not "Failed"
            return $job->release(10);
        }

        // Circuit HALF-OPEN: Single probe logic
        if ($breaker->isHalfOpen()) {
            $lock = Cache::lock("fuse:{$this->service}:probe", 5);

            if ($lock->get()) {
                // This worker gets to probe
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

            // Other workers: delay and try later
            return $job->release(10);
        }

        // Circuit CLOSED: Normal operation
        try {
            $result = $next($job);
            $breaker->recordSuccess();

            return $result;
        } catch (Throwable $e) {
            $breaker->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Check if circuit breaker is enabled.
     * Cache value overrides config for runtime toggling.
     */
    private function isEnabled(): bool
    {
        // Check cache first (for runtime toggling)
        $cacheValue = Cache::get('fuse:enabled');

        if ($cacheValue !== null) {
            return (bool) $cacheValue;
        }

        // Fall back to config
        return config('fuse.enabled', true);
    }
}
