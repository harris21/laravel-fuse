<?php

namespace Harris21\Fuse;

use GuzzleHttp\Exception\ClientException;
use Harris21\Fuse\Enums\CircuitState;
use Harris21\Fuse\Events\CircuitBreakerClosed;
use Harris21\Fuse\Events\CircuitBreakerHalfOpen;
use Harris21\Fuse\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class CircuitBreaker
{
    private readonly int $failureThreshold;

    private readonly int $timeout;

    private readonly int $minRequests;

    public function __construct(private readonly string $serviceName)
    {
        $config = config("fuse.services.{$serviceName}", []);

        $this->failureThreshold = ThresholdCalculator::for($serviceName);

        $this->timeout = $config['timeout']
            ?? config('fuse.default_timeout', 60);

        $this->minRequests = $config['min_requests']
            ?? config('fuse.default_min_requests', 10);
    }

    public function isOpen(): bool
    {
        if ($this->getState() !== CircuitState::Open) {
            return false;
        }

        $openedAt = Cache::get($this->key('opened_at'));

        if ($openedAt && (time() - $openedAt) >= $this->timeout) {
            $this->transitionTo(CircuitState::HalfOpen);

            return false;
        }

        return true;
    }

    public function isHalfOpen(): bool
    {
        return $this->getState() === CircuitState::HalfOpen;
    }

    public function isClosed(): bool
    {
        return $this->getState() === CircuitState::Closed;
    }

    public function recordSuccess(): void
    {
        $this->incrementAttempts();

        if ($this->getState() === CircuitState::HalfOpen) {
            $this->transitionTo(CircuitState::Closed);
        }

        Cache::lock($this->key('probe'))->forceRelease();
    }

    public function recordFailure(?Throwable $exception = null): void
    {
        if ($exception !== null && ! $this->shouldCountFailure($exception)) {
            $this->incrementAttempts();

            return;
        }

        if ($this->getState() === CircuitState::HalfOpen) {
            $this->transitionTo(CircuitState::Open, 100, 1, 1);
            Cache::lock($this->key('probe'))->forceRelease();

            return;
        }

        $window = $this->getCurrentWindow();
        $attemptsKey = $this->key("attempts:{$window}");
        $failuresKey = $this->key("failures:{$window}");

        $attempts = (int) Cache::increment($attemptsKey);
        $failures = (int) Cache::increment($failuresKey);

        Cache::put($attemptsKey, $attempts, 120);
        Cache::put($failuresKey, $failures, 120);

        $failureRate = $attempts > 0 ? ($failures / $attempts) * 100 : 0;

        if ($attempts >= $this->minRequests && $failureRate >= $this->failureThreshold) {
            $this->transitionTo(CircuitState::Open, $failureRate, $attempts, $failures);
        }
    }

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

    public function getState(): CircuitState
    {
        $state = Cache::get($this->key('state'), CircuitState::Closed->value);

        return CircuitState::from($state);
    }

    /**
     * @return array{state: string, attempts: int, failures: int, failure_rate: float, opened_at: ?int, recovery_at: ?int, timeout: int, threshold: int, min_requests: int}
     */
    public function getStats(): array
    {
        $window = $this->getCurrentWindow();
        $attempts = (int) Cache::get($this->key("attempts:{$window}"), 0);
        $failures = (int) Cache::get($this->key("failures:{$window}"), 0);
        $openedAt = Cache::get($this->key('opened_at'));
        $state = $this->getState();

        return [
            'state' => $state->value,
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

    private function transitionTo(
        CircuitState $newState,
        float $failureRate = 0,
        int $attempts = 0,
        int $failures = 0
    ): void {
        $lock = Cache::lock($this->key('transition'), 5);

        $acquired = $lock->get(function () use ($newState) {
            if ($this->getState() === $newState) {
                return false;
            }

            Cache::put($this->key('state'), $newState->value);

            if ($newState === CircuitState::Open) {
                Cache::put($this->key('opened_at'), time());
            }

            if ($newState === CircuitState::Closed) {
                Cache::forget($this->key('opened_at'));
            }

            return true;
        });

        if ($acquired) {
            match ($newState) {
                CircuitState::Open => event(new CircuitBreakerOpened(
                    $this->serviceName,
                    $failureRate,
                    $attempts,
                    $failures
                )),
                CircuitState::HalfOpen => event(new CircuitBreakerHalfOpen($this->serviceName)),
                CircuitState::Closed => event(new CircuitBreakerClosed($this->serviceName)),
            };
        }
    }

    private function incrementAttempts(): void
    {
        $window = $this->getCurrentWindow();
        $key = $this->key("attempts:{$window}");

        $attempts = (int) Cache::increment($key);
        Cache::put($key, $attempts, 120);
    }

    private function getCurrentWindow(): string
    {
        return now()->format('YmdHi');
    }

    public function key(string $suffix): string
    {
        return "fuse:{$this->serviceName}:{$suffix}";
    }
}
