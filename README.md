<p align="center">
  <img src="art/logo.png" width="400" alt="Laravel Fuse">
</p>

<p align="center">
  <strong>Circuit breaker for Laravel queue jobs</strong>
</p>

<p align="center">
  Protect your queue workers from cascading failures when external services go down.
</p>

---

## The Problem

When Stripe goes down at 11 PM, your queue workers don't know. They keep trying to charge customers. Each job waits 30 seconds for a timeout. Then retries. Waits again. Your entire queue system freezes.

**Without Fuse:** 10,000 jobs × 30-second timeouts = 25+ hours to clear the queue.

**With Fuse:** Circuit opens after 5 failures. Queue clears in 10 seconds. Automatic recovery when the service returns.

---

## Features

- **Three-State Circuit Breaker** — CLOSED (normal), OPEN (protected), HALF-OPEN (testing recovery)
- **Intelligent Failure Classification** — 429 rate limits and 401 auth errors don't trip the circuit
- **Fixed Window Tracking** — Minute-based buckets with automatic expiration, no cleanup needed
- **Thundering Herd Prevention** — `Cache::lock()` ensures only one worker probes during recovery
- **Zero Data Loss** — Jobs are delayed with `release()`, not failed permanently
- **Automatic Recovery** — Circuit tests and heals itself when services return
- **Per-Service Circuits** — Separate breakers for Stripe, Mailgun, your microservices
- **Pure Laravel** — No external dependencies, uses Cache and native job middleware

---

## How It Works

<p align="center">
  <img src="art/circuit-states.png" width="700" alt="Circuit Breaker States">
</p>

**CLOSED** — Normal operations. All requests pass through. Failures are tracked in the background.

**OPEN** — Protection mode. After the failure threshold is exceeded, the circuit trips. Jobs fail instantly (1ms, not 30s) and are delayed for automatic retry. No API calls are made.

**HALF-OPEN** — Testing recovery. After the timeout period, one probe request tests if the service recovered. Success closes the circuit. Failure reopens it.

---

## Installation

```bash
composer require harris21/laravel-fuse
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=fuse-config
```

---

## Quick Start

Add the middleware to your job:

```php
use Harris21\Fuse\Middleware\CircuitBreakerMiddleware;

class ChargeCustomer implements ShouldQueue
{
    public $tries = 0;           // Unlimited releases
    public $maxExceptions = 3;   // Only real failures count

    public function middleware(): array
    {
        return [new CircuitBreakerMiddleware('stripe')];
    }

    public function handle(): void
    {
        // Your payment logic - unchanged
        Stripe::charges()->create([...]);
    }
}
```

That's it. Your job is now protected.

---

## Configuration

```php
// config/fuse.php

return [
    'default_threshold' => 50,      // Failure rate percentage to trip circuit
    'default_timeout' => 60,        // Seconds before testing recovery
    'default_min_requests' => 10,   // Minimum requests before evaluating

    'services' => [
        'stripe' => [
            'threshold' => 50,
            'timeout' => 30,
            'min_requests' => 5,
        ],
        'mailgun' => [
            'threshold' => 60,
            'timeout' => 120,
            'min_requests' => 10,
        ],
    ],
];
```

---

## Events

Fuse dispatches events on state transitions that you can listen to:

```php
use Harris21\Fuse\Events\CircuitBreakerOpened;
use Harris21\Fuse\Events\CircuitBreakerHalfOpen;
use Harris21\Fuse\Events\CircuitBreakerClosed;

// In your EventServiceProvider or listener
CircuitBreakerOpened::class => [
    SendSlackAlert::class,
],
```

The `CircuitBreakerOpened` event includes `$service`, `$failureRate`, `$attempts`, and `$failures`.

---

## Requirements

- PHP 8.3+
- Laravel 11+
- Redis (recommended) or any Laravel cache driver

---

## Credits

Built by [Harris Raftopoulos](https://x.com/harrisrafto) for [Laracon India 2026](https://laracon.in).

YouTube: [@harrisrafto](https://youtube.com/@harrisrafto)

Based on the circuit breaker pattern from Michael Nygard's *Release It!* and popularized by Martin Fowler.

---

## License

MIT
