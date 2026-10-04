<?php

namespace Harris21\Fuse\Middleware;

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Contracts\JobAwareRecoveryStrategy;
use Harris21\Fuse\HeldJob;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Queue\Jobs\SyncJob;
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

        try {
            $admission = $this->admit($breaker, $held);
        } catch (Throwable $e) {
            if (! $this->canBeReleased($queueJob)) {
                throw $e;
            }

            $this->reportWithoutThrowing($e);
            $admission = 'hold';
        }

        return match ($admission) {
            'hold' => $job->release($breaker->releaseDelay()),
            'probe' => $this->run($job, $next, $breaker, fn (Throwable $e) => $this->recordProbeFailure($breaker, $e)),
            'run' => $this->run($job, $next, $breaker, fn (Throwable $e) => $breaker->recordFailure($e)),
        };
    }

    /**
     * The sync driver cannot put a released job back, so releasing it would drop it.
     */
    private function canBeReleased(mixed $queueJob): bool
    {
        return ! $queueJob instanceof SyncJob;
    }

    /**
     * @return 'hold'|'probe'|'run'
     */
    private function admit(CircuitBreaker $breaker, ?HeldJob $held): string
    {
        if ($breaker->isOpen()) {
            $this->observeHeldJob($breaker, $held);

            return 'hold';
        }

        if ($breaker->isHalfOpen()) {
            return $this->allowsAttempt($breaker, $held) ? 'probe' : 'hold';
        }

        return 'run';
    }

    /**
     * @param  callable(Throwable): void  $recordFailure
     */
    private function run(mixed $job, callable $next, CircuitBreaker $breaker, callable $recordFailure): mixed
    {
        try {
            $result = $next($job);
        } catch (Throwable $e) {
            try {
                $recordFailure($e);
            } catch (Throwable $bookkeepingError) {
                $this->reportWithoutThrowing($bookkeepingError);
            }

            throw $e;
        }

        try {
            $breaker->recordSuccess();
        } catch (Throwable $e) {
            $this->reportWithoutThrowing($e);
        }

        return $result;
    }

    /**
     * A reporter that throws must not change what happens to the job.
     */
    private function reportWithoutThrowing(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
        }
    }

    private function recordProbeFailure(CircuitBreaker $breaker, Throwable $e): void
    {
        $before = $breaker->getState();
        $breaker->recordFailure($e);

        if ($breaker->getState() === $before) {
            $breaker->recoveryStrategy()->recordFailure($breaker);
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

    /**
     * An unreadable kill switch is reported, and the config value decides.
     */
    private function isEnabled(): bool
    {
        $prefix = config('fuse.cache.prefix', 'fuse');

        try {
            $cacheValue = Cache::get("{$prefix}:enabled");
        } catch (Throwable $e) {
            $this->reportWithoutThrowing($e);
            $cacheValue = null;
        }

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
