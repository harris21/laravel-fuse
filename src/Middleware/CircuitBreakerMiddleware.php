<?php

namespace Harris21\Fuse\Middleware;

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Contracts\JobAwareRecoveryStrategy;
use Harris21\Fuse\HeldJob;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CircuitBreakerMiddleware
{
    public function __construct(
        private readonly string $service,
        private readonly ?int $release = null,
        private readonly ?int $window = null,
    ) {}

    public function handle(mixed $job, callable $next): mixed
    {
        if (! $this->isEnabled()) {
            return $next($job);
        }

        $queueJob = $job->job ?? null;
        $breaker = new CircuitBreaker(
            $this->service,
            $this->window,
            $this->release,
            $this->jobTimeout($queueJob),
        );
        $held = HeldJob::fromQueueJob($queueJob);

        if ($breaker->isOpen()) {
            $this->observeHeldJob($breaker, $held);

            return $job->release($breaker->releaseDelay());
        }

        if ($breaker->isHalfOpen()) {
            if (! $this->allowsAttempt($breaker, $held)) {
                return $job->release($breaker->releaseDelay());
            }

            try {
                $result = $next($job);
                $breaker->recordSuccess();

                return $result;
            } catch (Throwable $e) {
                $before = $breaker->getState();
                $breaker->recordFailure($e);

                if ($breaker->getState() === $before) {
                    $breaker->recoveryStrategy()->recordFailure($breaker);
                }

                throw $e;
            }
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

    private function observeHeldJob(CircuitBreaker $breaker, ?HeldJob $held): void
    {
        $strategy = $breaker->recoveryStrategy();

        if ($held !== null && $strategy instanceof JobAwareRecoveryStrategy) {
            $strategy->observeHeldJob($breaker, $held);
        }
    }

    private function allowsAttempt(CircuitBreaker $breaker, ?HeldJob $held): bool
    {
        $strategy = $breaker->recoveryStrategy();

        if ($held !== null && $strategy instanceof JobAwareRecoveryStrategy) {
            return $strategy->allowsAttemptFor($breaker, $held);
        }

        return $strategy->allowsAttempt($breaker);
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

    private function jobTimeout(mixed $queueJob): ?int
    {
        if (! $queueJob instanceof QueueJob) {
            return null;
        }

        return $queueJob->timeout();
    }
}
