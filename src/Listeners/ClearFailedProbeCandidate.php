<?php

namespace Harris21\Fuse\Listeners;

use Harris21\Fuse\Strategies\OldestJobProbe;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

/**
 * When a job fails for good (maxExceptions, retryUntil, manual fail), it
 * will never come back through the middleware. If it was the elected
 * probe, drop the election so the next held job can take over instead of
 * waiting for the candidate TTL to expire.
 *
 * Laravel records the failed job from its own JobFailed listener, which
 * runs after this one, so nothing here is allowed to throw. Apps without
 * OldestJobProbe configured return before touching the cache.
 */
class ClearFailedProbeCandidate
{
    public function handle(JobFailed $event): void
    {
        try {
            if (! $this->usesOldestJobProbe()) {
                return;
            }

            $this->forgetIfCandidate($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function forgetIfCandidate(JobFailed $event): void
    {
        $uuid = $event->job->uuid();

        if ($uuid === null) {
            return;
        }

        $prefix = config('fuse.cache.prefix', 'fuse');
        $service = OldestJobProbe::serviceForJob($prefix, $uuid);

        if ($service === null) {
            return;
        }

        OldestJobProbe::forget($prefix, $service, $uuid);
    }

    private function usesOldestJobProbe(): bool
    {
        foreach ((array) config('fuse.services', []) as $service) {
            $strategy = is_array($service) ? ($service['recovery_strategy'] ?? null) : null;

            if (is_string($strategy) && is_a($strategy, OldestJobProbe::class, true)) {
                return true;
            }
        }

        return false;
    }
}
