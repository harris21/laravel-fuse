<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Enabled
    |--------------------------------------------------------------------------
    |
    | Global toggle for circuit breaker functionality. When disabled, all
    | jobs will pass through without circuit breaker protection.
    |
    */
    'enabled' => env('FUSE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Settings
    |--------------------------------------------------------------------------
    |
    | Default threshold, timeout, and minimum requests for circuit breakers.
    | These can be overridden per-service in the 'services' array below.
    |
    */
    'default_threshold' => 50,      // Failure rate percentage to trip circuit
    'default_timeout' => 60,        // Seconds before transitioning to half-open
    'default_min_requests' => 10,   // Minimum requests before evaluating threshold

    /*
    |--------------------------------------------------------------------------
    | Service-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Configure circuit breaker settings per external service. Each service
    | can have custom thresholds, timeouts, and minimum request counts.
    |
    | Available options:
    | - threshold: Failure rate percentage to trip the circuit (default: 50)
    | - timeout: Seconds before transitioning to half-open (default: 60)
    | - min_requests: Minimum requests before evaluating threshold (default: 10)
    | - peak_hours_threshold: Alternative threshold during peak hours (optional)
    | - peak_hours_start: Hour (0-23) when peak hours begin (optional)
    | - peak_hours_end: Hour (0-23) when peak hours end (optional)
    |
    */
    'services' => [
        // 'stripe' => [
        //     'threshold' => 50,
        //     'timeout' => 30,
        //     'min_requests' => 5,
        //     'peak_hours_threshold' => 60,
        //     'peak_hours_start' => 9,
        //     'peak_hours_end' => 17,
        // ],
    ],
];
