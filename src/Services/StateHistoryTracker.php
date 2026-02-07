<?php

namespace Harris21\Fuse\Services;

use Illuminate\Support\Facades\Cache;

class StateHistoryTracker
{
    public function track(string $service, string $currentState): void
    {
        $lastStateKey = "fuse:status:last_state:{$service}";
        $historyKey = "fuse:status:history:{$service}";

        $lastState = Cache::get($lastStateKey);

        if ($lastState !== null && $lastState !== $currentState) {
            $history = Cache::get($historyKey, []);

            $history[] = [
                'from' => $lastState,
                'to' => $currentState,
                'time' => now()->format('H:i:s'),
            ];

            $history = array_slice($history, -20);

            Cache::put($historyKey, $history, now()->addHours(24));
        }

        Cache::put($lastStateKey, $currentState, now()->addHours(24));
    }

    public function getHistory(string $service): array
    {
        return Cache::get("fuse:status:history:{$service}", []);
    }
}
