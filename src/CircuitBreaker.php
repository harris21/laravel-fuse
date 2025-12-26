<?php

namespace Harris21\Fuse;

use GuzzleHttp\Exception\ClientException;
use Harris21\Fuse\Events\CircuitBreakerClosed;
use Harris21\Fuse\Events\CircuitBreakerHalfOpen;
use Harris21\Fuse\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class CircuitBreaker
{
    private const string STATE_CLOSED = 'closed';

    private const string STATE_OPEN = 'open';

    private const string STATE_HALF_OPEN = 'half_open';

    private readonly int $failureThreshold;

    private readonly int $timeout;

    private readonly int $minRequests;

    public function __construct(
        private readonly string $serviceName,
        ?int $failureThreshold = null,
        ?int $timeout = null,
        ?int $minRequests = null
    ) {
        $config = config("fuse.services.{$serviceName}", []);

        $this->failureThreshold = $failureThreshold
            ?? $config['threshold']
            ?? config('fuse.default_threshold', 50);

        $this->timeout = $timeout
            ?? $config['timeout']
            ?? config('fuse.default_timeout', 60);

        $this->minRequests = $minRequests
            ?? $config['min_requests']
            ?? config('fuse.default_min_requests', 10);
    }

    public function isAvailable(): bool
    {
        $state = $this->getState();

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            $openedAt = Cache::get($this->key('opened_at'));

            if ($openedAt && (time() - $openedAt) >= $this->timeout) {
                $this->transitionToHalfOpen();

                return true;
            }

            return false;
        }

        if ($state === self::STATE_HALF_OPEN) {
            return Cache::lock($this->key('probe'), 5)->get();
        }

        return true;
    }

    public function isOpen(): bool
    {
        $state = $this->getState();

        if ($state !== self::STATE_OPEN) {
            return false;
        }

        $openedAt = Cache::get($this->key('opened_at'));
        if ($openedAt && (time() - $openedAt) >= $this->timeout) {
            $this->transitionToHalfOpen();

            return false;
        }

        return true;
    }

    public function isHalfOpen(): bool
    {
        return $this->getState() === self::STATE_HALF_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->getState() === self::STATE_CLOSED;
    }

    public function recordSuccess(): void
    {
        $window = $this->getCurrentWindow();
        $this->incrementCounter($this->key("attempts:{$window}"));

        if ($this->getState() === self::STATE_HALF_OPEN) {
            $this->transitionToClosed();
        }

        Cache::lock($this->key('probe'))->forceRelease();
    }

    public function recordFailure(?Throwable $exception = null): void
    {
        $window = $this->getCurrentWindow();
        $attemptsKey = $this->key("attempts:{$window}");
        $failuresKey = $this->key("failures:{$window}");

        if ($exception !== null && ! $this->shouldCountFailure($exception)) {
            $this->incrementCounter($attemptsKey);

            return;
        }

        if ($this->getState() === self::STATE_HALF_OPEN) {
            $this->transitionToOpen(100, 1, 1);
            Cache::lock($this->key('probe'))->forceRelease();

            return;
        }

        $attempts = $this->incrementCounter($attemptsKey);
        $failures = $this->incrementCounter($failuresKey);
        $failureRate = $attempts > 0 ? ($failures / $attempts) * 100 : 0;

        if ($attempts >= $this->minRequests && $failureRate >= $this->failureThreshold) {
            $this->transitionToOpen($failureRate, $attempts, $failures);
        }
    }

    /**
     * Intelligent failure classification:
     * - DON'T count: Rate limits (429), Auth errors (401/403) - service is healthy
     * - DO count: Server errors (5xx), Timeouts, Connection failures
     */
    private function shouldCountFailure(Throwable $e): bool
    {
        if ($e instanceof TooManyRequestsHttpException) {
            return false;
        }

        if ($e instanceof ClientException) {
            return match ($e->getResponse()?->getStatusCode()) {
                429, 401, 403 => false,
                default => true,
            };
        }

        return true;
    }

    public function getState(): string
    {
        return Cache::get($this->key('state'), self::STATE_CLOSED);
    }

    /**
     * @return array{state: string, attempts: int, failures: int, failure_rate: float, opened_at: ?int, recovery_at: ?int}
     */
    public function getStats(): array
    {
        $window = $this->getCurrentWindow();
        $attempts = (int) Cache::get($this->key("attempts:{$window}"), 0);
        $failures = (int) Cache::get($this->key("failures:{$window}"), 0);
        $openedAt = Cache::get($this->key('opened_at'));
        $state = $this->getState();

        return [
            'state' => $state,
            'attempts' => $attempts,
            'failures' => $failures,
            'failure_rate' => $attempts > 0 ? round(($failures / $attempts) * 100, 1) : 0,
            'opened_at' => $openedAt,
            'recovery_at' => $openedAt ? $openedAt + $this->timeout : null,
            'timeout' => $this->timeout,
            'threshold' => $this->failureThreshold,
            'min_requests' => $this->minRequests,
        ];
    }

    public function reset(): void
    {
        $window = $this->getCurrentWindow();

        Cache::forget($this->key('state'));
        Cache::forget($this->key('opened_at'));
        Cache::forget($this->key("attempts:{$window}"));
        Cache::forget($this->key("failures:{$window}"));
        Cache::lock($this->key('probe'))->forceRelease();
        Cache::lock($this->key('transition'))->forceRelease();
    }

    private function transitionToOpen(float $failureRate, int $attempts, int $failures): void
    {
        $lock = Cache::lock($this->key('transition'), 5);

        $acquired = $lock->get(function () {
            if ($this->getState() === self::STATE_OPEN) {
                return false;
            }

            Cache::put($this->key('state'), self::STATE_OPEN);
            Cache::put($this->key('opened_at'), time());

            return true;
        });

        if ($acquired) {
            event(new CircuitBreakerOpened($this->serviceName, $failureRate, $attempts, $failures));
        }
    }

    private function transitionToHalfOpen(): void
    {
        $lock = Cache::lock($this->key('transition'), 5);

        $acquired = $lock->get(function () {
            if ($this->getState() === self::STATE_HALF_OPEN) {
                return false;
            }

            Cache::put($this->key('state'), self::STATE_HALF_OPEN);

            return true;
        });

        if ($acquired) {
            event(new CircuitBreakerHalfOpen($this->serviceName));
        }
    }

    private function transitionToClosed(): void
    {
        $lock = Cache::lock($this->key('transition'), 5);

        $acquired = $lock->get(function () {
            if ($this->getState() === self::STATE_CLOSED) {
                return false;
            }

            Cache::put($this->key('state'), self::STATE_CLOSED);
            Cache::forget($this->key('opened_at'));

            return true;
        });

        if ($acquired) {
            event(new CircuitBreakerClosed($this->serviceName));
        }
    }

    private function getCurrentWindow(): string
    {
        return now()->format('YmdHi');
    }

    /**
     * Increment counter with support for all cache drivers (database/file don't auto-create on increment).
     */
    private function incrementCounter(string $key, int $ttl = 120): int
    {
        Cache::add($key, 0, $ttl);
        $result = Cache::increment($key);

        if ($result !== false) {
            Cache::put($key, (int) $result, $ttl);

            return (int) $result;
        }

        return (int) Cache::get($key, 1);
    }

    private function key(string $suffix): string
    {
        return "fuse:{$this->serviceName}:{$suffix}";
    }
}
