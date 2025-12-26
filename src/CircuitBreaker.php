<?php

namespace Harris21\Fuse;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Harris21\Fuse\Events\CircuitBreakerClosed;
use Harris21\Fuse\Events\CircuitBreakerHalfOpen;
use Harris21\Fuse\Events\CircuitBreakerOpened;
use Illuminate\Http\Client\ConnectionException;
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
        // Load from config with fallback chain
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

    /**
     * Check if the circuit breaker is available for requests.
     * Handles state transitions from OPEN to HALF_OPEN when timeout elapses.
     */
    public function isAvailable(): bool
    {
        $state = $this->getState();

        // CLOSED: Always available
        if ($state === self::STATE_CLOSED) {
            return true;
        }

        // OPEN: Check if timeout elapsed
        if ($state === self::STATE_OPEN) {
            $openedAt = Cache::get($this->key('opened_at'));

            // Move to HALF-OPEN after timeout
            if ($openedAt && (time() - $openedAt) >= $this->timeout) {
                $this->transitionToHalfOpen();

                return true; // Allow test requests
            }

            return false; // Still protecting
        }

        // HALF-OPEN: Use lock for single probe
        if ($state === self::STATE_HALF_OPEN) {
            $lock = Cache::lock($this->key('probe'), 5);

            // Only one worker at a time gets to probe
            return $lock->get();
        }

        return true;
    }

    /**
     * Check if the circuit is currently open (blocking requests).
     */
    public function isOpen(): bool
    {
        $state = $this->getState();

        if ($state !== self::STATE_OPEN) {
            return false;
        }

        // Check if we should transition to half-open
        $openedAt = Cache::get($this->key('opened_at'));
        if ($openedAt && (time() - $openedAt) >= $this->timeout) {
            $this->transitionToHalfOpen();

            return false;
        }

        return true;
    }

    /**
     * Check if the circuit is in half-open state (testing recovery).
     */
    public function isHalfOpen(): bool
    {
        return $this->getState() === self::STATE_HALF_OPEN;
    }

    /**
     * Check if the circuit is closed (normal operation).
     */
    public function isClosed(): bool
    {
        return $this->getState() === self::STATE_CLOSED;
    }

    /**
     * Record a successful request. May close the circuit if in HALF_OPEN state.
     */
    public function recordSuccess(): void
    {
        $window = $this->getCurrentWindow();

        // Increment attempt counter
        Cache::increment($this->key("attempts:{$window}"));

        // Set TTL on the counter (2 minutes)
        $attempts = Cache::get($this->key("attempts:{$window}"));
        Cache::put($this->key("attempts:{$window}"), $attempts, 120);

        // If we're in HALF-OPEN state, close the circuit
        if ($this->getState() === self::STATE_HALF_OPEN) {
            $this->transitionToClosed();
        }

        // Release probe lock if we hold it
        Cache::lock($this->key('probe'))->forceRelease();
    }

    /**
     * Record a failed request. May open the circuit if threshold exceeded.
     * Optionally pass the exception for intelligent failure classification.
     */
    public function recordFailure(?Throwable $exception = null): void
    {
        // If an exception is provided, check if we should count it
        if ($exception !== null && ! $this->shouldCountFailure($exception)) {
            // Don't count this failure (e.g., rate limits, auth errors)
            // Still increment attempts for tracking purposes
            $window = $this->getCurrentWindow();
            Cache::increment($this->key("attempts:{$window}"));
            $attempts = Cache::get($this->key("attempts:{$window}"));
            Cache::put($this->key("attempts:{$window}"), $attempts, 120);

            return;
        }

        // If in HALF-OPEN state, failure means we go back to OPEN
        if ($this->getState() === self::STATE_HALF_OPEN) {
            $this->transitionToOpen(100, 1, 1); // 100% failure rate for the probe
            Cache::lock($this->key('probe'))->forceRelease();

            return;
        }

        // Fixed window approach - one minute buckets
        $window = $this->getCurrentWindow();

        // Increment counters for this minute
        Cache::increment($this->key("attempts:{$window}"));
        Cache::increment($this->key("failures:{$window}"));

        // Set expiration - old data disappears automatically
        $attempts = Cache::get($this->key("attempts:{$window}"));
        $failures = Cache::get($this->key("failures:{$window}"));

        Cache::put($this->key("attempts:{$window}"), $attempts, 120);
        Cache::put($this->key("failures:{$window}"), $failures, 120);

        // Calculate failure rate as percentage
        $failureRate = $attempts > 0 ? ($failures / $attempts) * 100 : 0;

        // Should we open the circuit?
        // Need minimum requests AND failure rate threshold
        if ($attempts >= $this->minRequests && $failureRate >= $this->failureThreshold) {
            $this->transitionToOpen($failureRate, $attempts, $failures);
        }
    }

    /**
     * Determine if an exception should be counted as a circuit breaker failure.
     *
     * This implements intelligent failure classification:
     * - DON'T count: Rate limits (429), Auth errors (401/403)
     * - DO count: Server errors (5xx), Timeouts, Connection failures
     */
    private function shouldCountFailure(Throwable $e): bool
    {
        // DON'T count rate limits - service is healthy, just rate limiting us
        if ($e instanceof TooManyRequestsHttpException) {
            return false;
        }

        // Check for Guzzle/HTTP client exceptions with match expression
        if ($e instanceof ClientException) {
            return match ($e->getResponse()?->getStatusCode()) {
                429 => false,       // Rate Limited: Service is healthy
                401, 403 => false,  // Auth errors: Configuration problem
                default => true,    // Other client errors: count them
            };
        }

        // DO count server errors (500, 502, 503, 504)
        if ($e instanceof ServerException) {
            return true;
        }

        // DO count connection failures
        if ($e instanceof ConnectionException || $e instanceof ConnectException) {
            return true;
        }

        // Default: count it as a failure
        return true;
    }

    /**
     * Get the current state of the circuit.
     */
    public function getState(): string
    {
        return Cache::get($this->key('state'), self::STATE_CLOSED);
    }

    /**
     * Get current statistics for the circuit.
     *
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

    /**
     * Force reset the circuit to closed state.
     */
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
     * Transition to OPEN state.
     */
    private function transitionToOpen(float $failureRate, int $attempts, int $failures): void
    {
        // Use lock to prevent race conditions
        $lock = Cache::lock($this->key('transition'), 5);

        $acquired = $lock->get(function () {
            // Double-check we're not already open
            if ($this->getState() === self::STATE_OPEN) {
                return false;
            }

            Cache::put($this->key('state'), self::STATE_OPEN);
            Cache::put($this->key('opened_at'), time());

            return true;
        });

        if ($acquired) {
            event(new CircuitBreakerOpened(
                $this->serviceName,
                $failureRate,
                $attempts,
                $failures
            ));
        }
    }

    /**
     * Transition to HALF_OPEN state.
     */
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

    /**
     * Transition to CLOSED state.
     */
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

    /**
     * Get the current window key (minute bucket).
     */
    private function getCurrentWindow(): string
    {
        return now()->format('YmdHi');
    }

    /**
     * Generate a cache key for this circuit breaker.
     */
    private function key(string $suffix): string
    {
        return "fuse:{$this->serviceName}:{$suffix}";
    }
}
