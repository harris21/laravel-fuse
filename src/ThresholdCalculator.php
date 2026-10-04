<?php

namespace Harris21\Fuse;

class ThresholdCalculator
{
    /**
     * Calculate the appropriate failure threshold for a service based on
     * time of day (peak hours vs off-peak).
     */
    public static function for(string $service): int
    {
        $config = config('fuse.services', [])[$service] ?? [];
        $threshold = $config['threshold'] ?? config('fuse.default_threshold') ?? 50;

        if (! isset($config['peak_hours_threshold'])) {
            return $threshold;
        }

        return self::isPeakHours($config) ? $config['peak_hours_threshold'] : $threshold;
    }

    /**
     * @return array{threshold: int, timeout: int, min_requests: int, is_peak_hours: bool}
     */
    public static function getConfig(string $service): array
    {
        $config = config('fuse.services', [])[$service] ?? [];

        return [
            'threshold' => self::for($service),
            'timeout' => $config['timeout'] ?? config('fuse.default_timeout', 60),
            'min_requests' => $config['min_requests'] ?? config('fuse.default_min_requests', 10),
            'is_peak_hours' => self::isPeakHours(is_array($config) ? $config : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function isPeakHours(array $config): bool
    {
        $hour = now()->hour;
        $start = $config['peak_hours_start'] ?? 9;
        $end = $config['peak_hours_end'] ?? 17;

        if ($start <= $end) {
            return $hour >= $start && $hour <= $end;
        }

        return $hour >= $start || $hour <= $end;
    }
}
