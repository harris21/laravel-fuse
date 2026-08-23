<?php

namespace Harris21\Fuse\Strategies;

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Contracts\JobAwareRecoveryStrategy;
use Harris21\Fuse\HeldJob;
use Illuminate\Support\Facades\Cache;

/**
 * Elects the oldest held job as the probe and sticks with it until it
 * succeeds or fails for good.
 *
 * While the circuit is open, every held job passes through the middleware
 * once per release interval, so over one interval the strategy sees the
 * whole backlog and keeps the job with the oldest dispatch time. In
 * half-open, only that job is allowed to run; everyone else is released.
 * A failed probe keeps the same candidate across reopen cycles, regardless
 * of how many workers are running.
 */
class OldestJobProbe implements JobAwareRecoveryStrategy
{
    private const CANDIDATE_SUFFIX = 'probe-candidate';

    private SingleProbe $fallback;

    public function __construct()
    {
        $this->fallback = new SingleProbe;
    }

    public function allowsAttempt(CircuitBreaker $breaker): bool
    {
        return $this->fallback->allowsAttempt($breaker);
    }

    public function allowsAttemptFor(CircuitBreaker $breaker, HeldJob $job): bool
    {
        $candidate = $this->elect($breaker, $job);

        return $candidate !== null
            && $candidate['uuid'] === $job->uuid
            && $this->fallback->allowsAttempt($breaker);
    }

    public function observeHeldJob(CircuitBreaker $breaker, HeldJob $job): void
    {
        $this->elect($breaker, $job);
    }

    public function recordSuccess(CircuitBreaker $breaker): bool
    {
        return $this->fallback->recordSuccess($breaker);
    }

    public function forgetCandidate(CircuitBreaker $breaker): void
    {
        $candidate = $this->candidate($breaker);

        if ($candidate !== null) {
            self::forget(config('fuse.cache.prefix', 'fuse'), $breaker->serviceName(), $candidate['uuid']);
        }
    }

    public function recordFailure(CircuitBreaker $breaker): void
    {
        $this->fallback->recordFailure($breaker);
    }

    /**
     * @return array{uuid: string, created_at: int, name: string}|null
     */
    public function candidate(CircuitBreaker $breaker): ?array
    {
        return self::read($breaker->key(self::CANDIDATE_SUFFIX));
    }

    /**
     * Drop the candidate for a service, along with its reverse lookup.
     */
    public static function forget(string $prefix, string $service, string $uuid): void
    {
        $candidateKey = "{$prefix}:{$service}:".self::CANDIDATE_SUFFIX;
        $lockKey = "{$prefix}:{$service}:probe-candidate-lock";

        Cache::lock($lockKey, 2)->get(function () use ($candidateKey, $prefix, $uuid) {
            $candidate = self::read($candidateKey);

            if ($candidate !== null && $candidate['uuid'] === $uuid) {
                Cache::forget($candidateKey);
            }

            Cache::forget(self::reverseKey($prefix, $uuid));
        });
    }

    /**
     * The service a job uuid is currently the candidate for, if any.
     */
    public static function serviceForJob(string $prefix, string $uuid): ?string
    {
        return Cache::get(self::reverseKey($prefix, $uuid));
    }

    /**
     * Compare this job against the current candidate and keep the older
     * of the two. Returns the candidate that holds after the comparison.
     *
     * @return array{uuid: string, created_at: int, name: string}|null
     */
    private function elect(CircuitBreaker $breaker, HeldJob $job): ?array
    {
        $key = $breaker->key(self::CANDIDATE_SUFFIX);
        $ttl = $this->ttl($breaker);
        $prefix = config('fuse.cache.prefix', 'fuse');
        $service = $breaker->serviceName();

        $elected = Cache::lock($breaker->key('probe-candidate-lock'), 2)->get(function () use ($breaker, $key, $ttl, $prefix, $service, $job) {
            if ($breaker->isClosed()) {
                return null;
            }

            $current = self::read($key);

            if ($current !== null) {
                if ($current['uuid'] === $job->uuid) {
                    Cache::put($key, $current, $ttl);
                    Cache::put(self::reverseKey($prefix, $job->uuid), $service, $ttl);

                    return $current;
                }

                if ($current['created_at'] <= $job->createdAt) {
                    return $current;
                }

                Cache::forget(self::reverseKey($prefix, $current['uuid']));
            }

            $candidate = [
                'uuid' => $job->uuid,
                'created_at' => $job->createdAt,
                'name' => $job->name,
            ];

            Cache::put($key, $candidate, $ttl);
            Cache::put(self::reverseKey($prefix, $job->uuid), $service, $ttl);

            return $candidate;
        });

        return $elected === false ? self::read($key) : $elected;
    }

    private function ttl(CircuitBreaker $breaker): int
    {
        return $breaker->timeout() + (3 * $breaker->releaseDelay());
    }

    /**
     * @return array{uuid: string, created_at: int, name: string}|null
     */
    private static function read(string $key): ?array
    {
        $value = Cache::get($key);

        if (! is_array($value) || ! is_string($value['uuid'] ?? null)) {
            return null;
        }

        return [
            'uuid' => $value['uuid'],
            'created_at' => (int) ($value['created_at'] ?? 0),
            'name' => (string) ($value['name'] ?? 'unknown'),
        ];
    }

    private static function reverseKey(string $prefix, string $uuid): string
    {
        return "{$prefix}:".self::CANDIDATE_SUFFIX.":{$uuid}";
    }
}
