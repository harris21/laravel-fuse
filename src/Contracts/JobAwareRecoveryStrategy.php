<?php

namespace Harris21\Fuse\Contracts;

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\HeldJob;

/**
 * A recovery strategy that can tell jobs apart.
 *
 * The middleware prefers these methods over the plain RecoveryStrategy ones
 * whenever it can identify the job. When it cannot (sync driver, payloads
 * without a uuid), it falls back to the RecoveryStrategy methods.
 */
interface JobAwareRecoveryStrategy extends RecoveryStrategy
{
    /**
     * Called for every job the middleware turns away while the circuit is
     * open or half-open. Strategies use this to learn what is waiting.
     */
    public function observeHeldJob(CircuitBreaker $breaker, HeldJob $job): void;

    /**
     * Given the breaker in half-open, may THIS job proceed right now?
     */
    public function allowsAttemptFor(CircuitBreaker $breaker, HeldJob $job): bool;

    /**
     * The job currently elected to probe, if any.
     *
     * @return array{uuid: string, created_at: int, name: string}|null
     */
    public function candidate(CircuitBreaker $breaker): ?array;

    /**
     * Drop the elected job, if any, so the next held job can be elected.
     */
    public function forgetCandidate(CircuitBreaker $breaker): void;
}
