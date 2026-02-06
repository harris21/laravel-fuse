<?php

namespace Harris21\Fuse\Http\Controllers;

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Services\StateHistoryTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class StatusPageController
{
    public function index(): View
    {
        return view('fuse::status', [
            'initialData' => $this->buildServiceData(),
            'pollingInterval' => config('fuse.status_page.polling_interval', 2),
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json([
            'services' => $this->buildServiceData(),
            'circuit_breaker_enabled' => $this->isEnabled(),
            'timestamp' => now()->format('H:i:s'),
        ]);
    }

    private function buildServiceData(): array
    {
        $services = config('fuse.services', []);
        $tracker = new StateHistoryTracker;
        $data = [];

        foreach ($services as $name => $config) {
            $breaker = new CircuitBreaker($name);
            $breaker->isOpen();
            $stats = $breaker->getStats();

            $tracker->track($name, $stats['state']);

            $data[$name] = array_merge($stats, [
                'state_history' => $tracker->getHistory($name),
            ]);
        }

        return $data;
    }

    private function isEnabled(): bool
    {
        $cacheValue = Cache::get('fuse:enabled');

        if ($cacheValue !== null) {
            return (bool) $cacheValue;
        }

        return config('fuse.enabled', true);
    }
}
