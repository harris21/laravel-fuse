<?php

namespace Harris21\Fuse\Http\Controllers;

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Services\StateHistoryTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Throwable;

class StatusPageController
{
    public function index(): View
    {
        $status = $this->readStatus();

        return view('fuse::status', [
            'initialData' => $status['services'] ?? [],
            'circuitBreakerEnabled' => $status['enabled'] ?? false,
            'dataAvailable' => $status !== null,
            'pollingInterval' => config('fuse.status_page.polling_interval', 2),
        ]);
    }

    public function data(): JsonResponse
    {
        $status = $this->readStatus();

        if ($status === null) {
            return response()->json(['message' => 'Circuit data is unavailable.'], 503);
        }

        return response()->json([
            'services' => $status['services'],
            'circuit_breaker_enabled' => $status['enabled'],
            'timestamp' => now()->format('H:i:s'),
        ]);
    }

    /**
     * @return array{services: array<array-key, array<string, mixed>>, enabled: bool}|null
     */
    private function readStatus(): ?array
    {
        try {
            return [
                'services' => $this->buildServiceData(),
                'enabled' => $this->isEnabled(),
            ];
        } catch (Throwable $e) {
            $this->reportWithoutThrowing($e);

            return null;
        }
    }

    /**
     * A reporter that throws must not replace the outage response.
     */
    private function reportWithoutThrowing(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
        }
    }

    /**
     * @return array<array-key, array<string, mixed>>
     */
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
        $prefix = config('fuse.cache.prefix', 'fuse');
        $cacheValue = Cache::get("{$prefix}:enabled");

        if ($cacheValue !== null) {
            return (bool) $cacheValue;
        }

        return config('fuse.enabled', true);
    }
}
