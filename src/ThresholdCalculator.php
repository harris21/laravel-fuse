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
        $config = config("fuse.services.{$service}");

        if (! $config) {
            return config('fuse.default_threshold', 50);
        }

        $hour = now()->hour;

        $peakStart = $config['peak_hours_start'] ?? 9;
        $peakEnd = $config['peak_hours_end'] ?? 17;

        $isPeakHours = $hour >= $peakStart && $hour <= $peakEnd;

        return $isPeakHours
            ? ($config['peak_hours_threshold'] ?? $config['threshold'] ?? 60)
            : ($config['threshold'] ?? 50);
    }

    /**
     * @return array{threshold: int, timeout: int, min_requests: int, is_peak_hours: bool}
     */
    public static function getConfig(string $service): array
    {
        $config = config("fuse.services.{$service}", []);
        $hour = now()->hour;

        $peakStart = $config['peak_hours_start'] ?? 9;
        $peakEnd = $config['peak_hours_end'] ?? 17;
        $isPeakHours = $hour >= $peakStart && $hour <= $peakEnd;

        return [
            'threshold' => self::for($service),
            'timeout' => $config['timeout'] ?? config('fuse.default_timeout', 60),
            'min_requests' => $config['min_requests'] ?? config('fuse.default_min_requests', 10),
            'is_peak_hours' => $isPeakHours,
        ];
    }
}
