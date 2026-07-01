<?php

namespace Harris21\Fuse\Contracts;

use Harris21\Fuse\CircuitBreaker;

interface RecoveryStrategy
{
    /**
     * Given the breaker in half-open, may THIS job proceed right now?
     */
    public function allowsAttempt(CircuitBreaker $breaker): bool;

    /**
     * Called after a half-open job succeeds; return true if the circuit
     * should now fully close, false to stay half-open (still ramping).
     */
    public function recordSuccess(CircuitBreaker $breaker): bool;

    /**
     * Called after a half-open job fails (a counted failure).
     */
    public function recordFailure(CircuitBreaker $breaker): void;
}
