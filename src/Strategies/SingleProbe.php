<?php

namespace Harris21\Fuse\Strategies;

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Contracts\RecoveryStrategy;
use Illuminate\Support\Facades\Cache;

class SingleProbe implements RecoveryStrategy
{
    public function allowsAttempt(CircuitBreaker $breaker): bool
    {
        return Cache::lock($breaker->key('probe'), $breaker->probeLockTtl())->get();
    }

    public function recordSuccess(CircuitBreaker $breaker): bool
    {
        Cache::lock($breaker->key('probe'))->forceRelease();

        return true;
    }

    public function recordFailure(CircuitBreaker $breaker): void
    {
        Cache::lock($breaker->key('probe'))->forceRelease();
    }
}
