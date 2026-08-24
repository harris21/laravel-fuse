<?php

namespace Harris21\Fuse;

use Illuminate\Contracts\Queue\Job;

/**
 * The identity of a queued job as seen by the circuit breaker middleware.
 *
 * Built from the underlying queue job's payload. The uuid and createdAt
 * are written once at dispatch and survive every release, which is what
 * lets a recovery strategy recognise the same job across cycles.
 */
final readonly class HeldJob
{
    public function __construct(
        public string $uuid,
        public int $createdAt,
        public string $name,
    ) {}

    public static function fromQueueJob(mixed $queueJob): ?self
    {
        if (! $queueJob instanceof Job) {
            return null;
        }

        $uuid = $queueJob->uuid();

        if ($uuid === null || $uuid === '') {
            return null;
        }

        $payload = $queueJob->payload();

        return new self(
            uuid: $uuid,
            createdAt: (int) ($payload['createdAt'] ?? time()),
            name: (string) ($payload['displayName'] ?? 'unknown'),
        );
    }
}
