<?php

namespace Harris21\Fuse;

use Harris21\Fuse\Classifiers\DefaultFailureClassifier;
use Harris21\Fuse\Contracts\FailureClassifier;
use Harris21\Fuse\Contracts\RecoveryStrategy;
use Harris21\Fuse\Enums\CircuitState;
use Harris21\Fuse\Events\CircuitBreakerClosed;
use Harris21\Fuse\Events\CircuitBreakerHalfOpen;
use Harris21\Fuse\Events\CircuitBreakerOpened;
use Harris21\Fuse\Strategies\SingleProbe;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

class CircuitBreaker
{
    private readonly int $failureThreshold;

    private readonly int $timeout;

    private readonly int $minRequests;

    private readonly int $windowSeconds;

    private readonly string $cachePrefix;

    private readonly FailureClassifier $failureClassifier;

    private readonly RecoveryStrategy $recoveryStrategy;

    public function __construct(private readonly string $serviceName, ?int $window = null)
    {
        $config = config("fuse.services.{$serviceName}", []);

        $this->failureThreshold = ThresholdCalculator::for($serviceName);

        $this->timeout = $config['timeout']
            ?? config('fuse.default_timeout', 60);

        $this->minRequests = $config['min_requests']
            ?? config('fuse.default_min_requests', 10);

        $this->windowSeconds = max(1, (int) (
            $window
            ?? $config['window']
            ?? config('fuse.default_window', 60)
        ));

        $this->cachePrefix = config('fuse.cache.prefix', 'fuse');

        $this->failureClassifier = $this->resolveFailureClassifier($config);

        $this->recoveryStrategy = $this->resolveRecoveryStrategy($config);
    }

    public function recoveryStrategy(): RecoveryStrategy
    {
        return $this->recoveryStrategy;
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
            if ($this->recoveryStrategy->recordSuccess($this)) {
                $this->transitionTo(CircuitState::Closed);
            }
        }
    }

    public function recordFailure(?Throwable $exception = null): void
    {
        if ($exception !== null && ! $this->failureClassifier->shouldCount($exception)) {
            $this->incrementAttempts();

            return;
        }

        if ($this->getState() === CircuitState::HalfOpen) {
            $this->recoveryStrategy->recordFailure($this);
            $this->transitionTo(CircuitState::Open, 100, 1, 1);

            return;
        }

        $window = $this->getCurrentWindow();
        $attemptsKey = $this->key("attempts:{$window}");
        $failuresKey = $this->key("failures:{$window}");

        $attempts = (int) Cache::increment($attemptsKey);
        $failures = (int) Cache::increment($failuresKey);

        Cache::put($attemptsKey, $attempts, $this->windowTtl());
        Cache::put($failuresKey, $failures, $this->windowTtl());

        $failureRate = $attempts > 0 ? ($failures / $attempts) * 100 : 0;

        if ($attempts >= $this->minRequests && $failureRate >= $this->failureThreshold) {
            $this->transitionTo(CircuitState::Open, $failureRate, $attempts, $failures);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveFailureClassifier(array $config): FailureClassifier
    {
        if (! isset($config['failure_classifier'])) {
            return new DefaultFailureClassifier;
        }

        $classifier = app($config['failure_classifier']);

        if (! $classifier instanceof FailureClassifier) {
            throw new InvalidArgumentException(
                "Class [{$config['failure_classifier']}] must implement ".FailureClassifier::class
            );
        }

        return $classifier;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveRecoveryStrategy(array $config): RecoveryStrategy
    {
        if (! isset($config['recovery_strategy'])) {
            return new SingleProbe;
        }

        $strategy = app($config['recovery_strategy']);

        if (! $strategy instanceof RecoveryStrategy) {
            throw new InvalidArgumentException(
                "Class [{$config['recovery_strategy']}] must implement ".RecoveryStrategy::class
            );
        }

        return $strategy;
    }

    public function getState(): CircuitState
    {
        $state = Cache::get($this->key('state'), CircuitState::Closed->value);

        return CircuitState::from($state);
    }

    /**
     * @return array{state: string, attempts: int, failures: int, failure_rate: float, opened_at: ?int, recovery_at: ?int, timeout: int, threshold: int, min_requests: int, window: int}
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
            'recovery_at' => $openedAt ? (int) $openedAt + $this->timeout : null,
            'timeout' => $this->timeout,
            'threshold' => $this->failureThreshold,
            'min_requests' => $this->minRequests,
            'window' => $this->windowSeconds,
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

    /**
     * @return bool Whether the circuit actually changed state.
     */
    public function forceOpen(): bool
    {
        return $this->transitionTo(CircuitState::Open);
    }

    /**
     * @return bool Whether the circuit actually changed state.
     */
    public function forceClose(): bool
    {
        return $this->transitionTo(CircuitState::Closed);
    }

    private function transitionTo(
        CircuitState $newState,
        float $failureRate = 0,
        int $attempts = 0,
        int $failures = 0
    ): bool {
        $lock = Cache::lock($this->key('transition'), 5);

        $changed = (bool) $lock->get(function () use ($newState) {
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

        if ($changed) {
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

        return $changed;
    }

    private function incrementAttempts(): void
    {
        $window = $this->getCurrentWindow();
        $key = $this->key("attempts:{$window}");

        $attempts = (int) Cache::increment($key);
        Cache::put($key, $attempts, $this->windowTtl());
    }

    private function windowTtl(): int
    {
        return $this->windowSeconds * 2;
    }

    private function getCurrentWindow(): string
    {
        return (string) intdiv(now()->getTimestamp(), $this->windowSeconds);
    }

    public function key(string $suffix): string
    {
        return "{$this->cachePrefix}:{$this->serviceName}:{$suffix}";
    }
}
