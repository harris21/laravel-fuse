<p align="center">
  <img src="art/logo.png" width="400" alt="Fuse for Laravel">
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
- **Intelligent Failure Classification** — 429 rate limits and auth errors don't trip the circuit
- **Peak Hours Support** — Different thresholds for business hours vs. off-peak
- **Configurable Window Tracking** — Tumbling time buckets (default 60s, tunable per service) with automatic expiration, no cleanup needed
- **Thundering Herd Prevention** — `Cache::lock()` ensures only one worker probes during recovery
- **Zero Data Loss** — Jobs are delayed with `release()`, not failed permanently
- **Automatic Recovery** — Circuit tests and heals itself when services return
- **Optional Recovery Strategies** — Single-probe works out of the box; plug in your own strategy if a service needs to warm back up differently
- **Per-Service Circuits** — Separate breakers for Stripe, Mailgun, your microservices
- **Laravel Events** — Get notified on state transitions for alerting and monitoring
- **Real-Time Status Page** — Built-in monitoring dashboard with live state updates
- **Pure Laravel** — No external dependencies, uses Cache and native job middleware

---

## How It Works

<p align="center">
  <img src="art/circuit-states.png" width="700" alt="Circuit Breaker States">
</p>

**CLOSED** — Normal operations. All requests pass through. Failures are tracked in the background.

**OPEN** — Protection mode. After the failure threshold is exceeded, the circuit trips. Jobs fail instantly (1ms, not 30s) and are delayed for automatic retry. No API calls are made.

**HALF-OPEN** — Testing recovery. After the timeout period, one probe request tests if the service recovered. Success closes the circuit. Failure reopens it. If you need to, you may [customize the probe request](#recovery-strategies).

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
        return [new CircuitBreakerMiddleware('stripe', release: 20)];
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

## Attributes

Instead of building the middleware array yourself, declare protection with attributes:

```php
use Harris21\Fuse\Attributes\UseCircuitBreaker;
use Harris21\Fuse\Middleware\ResolvesCircuitBreakers;

#[UseCircuitBreaker('stripe')]
class ChargeCustomer implements ShouldQueue
{
    public function middleware(): array
    {
        return ResolvesCircuitBreakers::resolve($this);
    }
}
```

Jobs that talk to multiple services can stack them:

```php
#[UseCircuitBreaker('stripe')]
#[UseCircuitBreaker('mailgun', release: 30)]
class ChargeAndNotify implements ShouldQueue
{
    public function middleware(): array
    {
        return ResolvesCircuitBreakers::resolve($this);
    }
}
```

Both `release:` and `window:` can be set per attribute. `window:` overrides the failure-tracking window (in seconds) for that service, taking precedence over config:

```php
#[UseCircuitBreaker(service: 'reports', window: 600)]
```

If you have other middleware to include, use `merge()` to prepend the circuit breakers:

```php
public function middleware(): array
{
    return ResolvesCircuitBreakers::merge($this, [
        new RateLimited('payments'),
    ]);
}
```

---

## Configuration

```php
// config/fuse.php

return [
    'enabled' => env('FUSE_ENABLED', true),

    'default_threshold' => 50,      // Failure rate percentage to trip circuit
    'default_timeout' => 60,        // Seconds before testing recovery
    'default_min_requests' => 10,   // Minimum requests before evaluating
    'default_window' => 60,         // Seconds per failure-tracking window

    'services' => [
        'stripe' => [
            'threshold' => 50,
            'timeout' => 30,
            'min_requests' => 5,
            'release' => 15,

            // Peak hours: more tolerant during business hours
            'peak_hours_threshold' => 60,
            'peak_hours_start' => 9,   // 9 AM
            'peak_hours_end' => 17,    // 5 PM
        ],
        'mailgun' => [
            'threshold' => 60,
            'timeout' => 120,
            'min_requests' => 10,
            'window' => 300,        // 5-minute window for this lower-traffic service
        ],
    ],

    // Cache prefix — change if multiple apps share the same Redis instance
    'cache' => [
        'prefix' => env('FUSE_CACHE_PREFIX', 'fuse'),
    ],
];
```

---

## Peak Hours

Configure different thresholds for business hours when every transaction matters:

```php
'stripe' => [
    'threshold' => 40,              // Off-peak: more sensitive (40%)
    'peak_hours_threshold' => 60,   // Peak hours: more tolerant (60%)
    'peak_hours_start' => 9,        // 9 AM
    'peak_hours_end' => 17,         // 5 PM
],
```

During peak hours (9 AM - 5 PM), the circuit uses the higher threshold to maximize successful transactions. Outside peak hours, it uses the lower threshold for earlier protection.

---

## Tracking Window

Failures are counted in fixed time windows (tumbling buckets), and the circuit only evaluates the failure rate once a window has gathered `min_requests` attempts. This matters for low-volume services: if a service doesn't see `min_requests` attempts within a single window, the failure rate is never checked and the circuit can't trip. The default 60-second window fits busy services — quieter ones need a wider window so enough attempts accumulate.

Widen the window so enough samples accumulate before the bucket rolls over:

```php
'reports' => [
    'min_requests' => 10,
    'window' => 600,   // 10 minutes — long enough to gather 10 samples
],
```

Set it globally with `default_window`, per-service as above, or inline on the attribute (`#[UseCircuitBreaker(service: 'reports', window: 600)]`). Counters auto-expire after twice the window, so there's still nothing to clean up.

Trade-offs to keep in mind: a longer window reacts more slowly and keeps the circuit eligible to stay open longer, and because buckets are tumbling, a failure burst that straddles a boundary is split across two windows — so worst-case detection can lag by up to one window. If you ever need smoother behaviour, a future weighted "current + previous bucket" counter could remove that boundary effect.

---

## Intelligent Failure Classification

Not all errors indicate a service is down. Fuse only counts real outages:

| Error Type | Counted as Failure? | Reason |
|------------|---------------------|--------|
| 500, 502, 503 | Yes | Server errors indicate service problems |
| Connection timeout | Yes | Service is unreachable |
| Connection refused | Yes | Service is unreachable |
| 429 Too Many Requests | No | Service is healthy, just rate limiting |
| 401 Unauthorized | No | Your API key is wrong, not a service issue |
| 403 Forbidden | No | Permission issue, not a service outage |
| 400 Bad Request | Yes | Could indicate API issues |
| 404 Not Found | Yes | Could indicate API changes |

This prevents false positives. A rate limit doesn't mean Stripe is down - it means you're sending too many requests.

---

## Custom Failure Classification

The default behavior works well for most APIs, but some services deviate from HTTP standards. For example, Stripe returns `500` for idempotency errors that are actually client-side issues — not outages.

You can override the failure classification logic per service by setting the `failure_classifier` option in your service config:

```php
// config/fuse.php

'services' => [
    'stripe' => [
        'threshold' => 50,
        'timeout' => 30,
        'min_requests' => 5,
        'failure_classifier' => \App\Fuse\StripeFailureClassifier::class,
    ],
],
```

### Extending the Default Classifier

The easiest approach is to extend `DefaultFailureClassifier` and override specific cases:

```php
namespace App\Fuse;

use GuzzleHttp\Exception\ServerException;
use Harris21\Fuse\Classifiers\DefaultFailureClassifier;
use Throwable;

class StripeFailureClassifier extends DefaultFailureClassifier
{
    public function shouldCount(Throwable $e): bool
    {
        // Stripe returns 500 for idempotency errors — not a real outage
        if ($e instanceof ServerException) {
            $body = (string) $e->getResponse()?->getBody();

            if (str_contains($body, 'idempotency')) {
                return false;
            }
        }

        return parent::shouldCount($e);
    }
}
```

### Implementing the Interface from Scratch

For full control, implement `FailureClassifier` directly:

```php
namespace App\Fuse;

use Harris21\Fuse\Contracts\FailureClassifier;
use Throwable;

class CustomFailureClassifier implements FailureClassifier
{
    public function shouldCount(Throwable $e): bool
    {
        // Your classification logic
    }
}
```

When no `failure_classifier` is configured, Fuse uses `DefaultFailureClassifier` which preserves the behavior described in the table above.

---

## Recovery strategies

By default, when a circuit is half-open, Fuse lets a single probe request through to test whether the service has recovered. If it succeeds, the circuit closes and traffic returns to full speed; if it fails, the circuit reopens. You don't need to configure anything for this — it's how Fuse works out of the box.

If you would like more control over how a recovered service is tested, you may provide your own recovery strategy by implementing the `RecoveryStrategy` contract. This is entirely optional.

For example, you might warm a slow service back up gradually instead of returning to full speed on the first success:

```php
namespace App\Fuse;

use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Contracts\RecoveryStrategy;

class CustomRecoveryStrategy implements RecoveryStrategy
{
    public function allowsAttempt(CircuitBreaker $breaker): bool
    {
        // Return true to let this half-open job through, false to release it for a later retry.
    }

    public function recordSuccess(CircuitBreaker $breaker): bool
    {
        // Return true to fully close the circuit, false to keep testing.
    }

    public function recordFailure(CircuitBreaker $breaker): void
    {
        // Called when a half-open job fails. The circuit reopens around this call.
    }
}
```

Then reference your class via the `recovery_strategy` option on your service configuration. It is resolved through the container, just like `failure_classifier`:

```php
'services' => [
    'stripe' => [
        'recovery_strategy' => \App\Fuse\CustomRecoveryStrategy::class,
    ],
],
```

Use `$breaker->key('your-suffix')` to namespace any cache keys your strategy needs.

---

## Events

Fuse dispatches Laravel events on every state transition:

```php
use Harris21\Fuse\Events\CircuitBreakerOpened;
use Harris21\Fuse\Events\CircuitBreakerHalfOpen;
use Harris21\Fuse\Events\CircuitBreakerClosed;
```

### Listening to Events

```php
// app/Listeners/AlertOnCircuitOpen.php

class AlertOnCircuitOpen
{
    public function handle(CircuitBreakerOpened $event): void
    {
        Log::critical("Circuit breaker opened for {$event->service}", [
            'failure_rate' => $event->failureRate,
            'attempts' => $event->attempts,
            'failures' => $event->failures,
        ]);

        // Send Slack notification, page on-call, etc.
    }
}
```

### Event Properties

**CircuitBreakerOpened:**
- `$service` — The service name (e.g., "stripe")
- `$failureRate` — Current failure percentage
- `$attempts` — Total requests in the window
- `$failures` — Failed requests in the window

**CircuitBreakerHalfOpen:**
- `$service` — The service name

**CircuitBreakerClosed:**
- `$service` — The service name

### Wiring Events to Monitoring

Fuse only emits events — it deliberately doesn't log, page, or export metrics itself, so nothing about your monitoring stack needs to be known by the package. Point a listener at whichever tool you already use.

**Slack (or any webhook-based alert):**

```php
class NotifySlackOnCircuitOpen
{
    public function handle(CircuitBreakerOpened $event): void
    {
        Http::post(config('services.slack.webhook_url'), [
            'text' => sprintf(
                ':rotating_light: Circuit breaker OPEN for *%s* — %.1f%% failure rate (%d/%d requests)',
                $event->service,
                $event->failureRate,
                $event->failures,
                $event->attempts,
            ),
        ]);
    }
}
```

**Prometheus** (via whichever client you've wired into your app, e.g. `promphp/prometheus_client_php`):

```php
class RecordCircuitBreakerMetrics
{
    public function __construct(private CollectorRegistry $registry) {}

    private function gauge(): \Prometheus\Gauge
    {
        return $this->registry->getOrRegisterGauge(
            'fuse', 'circuit_open', 'Circuit breaker state (1 = open, 0 = closed)', ['service']
        );
    }

    public function handleOpened(CircuitBreakerOpened $event): void
    {
        $this->gauge()->set(1, [$event->service]);
    }

    public function handleClosed(CircuitBreakerClosed $event): void
    {
        $this->gauge()->set(0, [$event->service]);
    }
}
```

Map both methods to their respective events in your `EventServiceProvider` (or listener attributes), same as any other multi-event listener.

**Laravel Pulse** (custom recorder — check your installed Pulse version's docs for the exact recorder signature):

```php
namespace App\Recorders;

use Harris21\Fuse\Events\CircuitBreakerOpened;
use Laravel\Pulse\Facades\Pulse;

class CircuitBreakerRecorder
{
    public array $listen = [CircuitBreakerOpened::class];

    public function record(CircuitBreakerOpened $event): void
    {
        Pulse::record('fuse_circuit_open', $event->service)->count();
    }
}
```

Register it in `config/pulse.php` under `recorders`, and it'll show up alongside your other Pulse cards.

---

## Real-World Scenarios

### Stripe goes down at 11 PM

```php
// config/fuse.php
'services' => [
    'stripe' => [
        'threshold' => 50,     // trip at 50% failure rate
        'timeout' => 30,       // wait 30s before probing again
        'min_requests' => 5,   // need at least 5 requests to evaluate
    ],
],
```

```php
class ChargeCustomer implements ShouldQueue
{
    public $tries = 0;
    public $maxExceptions = 3;

    public function middleware(): array
    {
        return [new CircuitBreakerMiddleware('stripe', release: 20)];
    }

    public function handle(): void
    {
        Stripe::charges()->create([...]);
    }
}
```

What actually happens, second by second:

1. **11:00:00 PM** — Stripe starts returning `503`s. Jobs still run at full speed; each one waits out a real timeout before failing, and every failure is tallied in the current window.
2. **11:00:04 PM** — The 5th failure lands and the failure rate crosses 50%. The circuit trips: `CircuitBreakerOpened` fires with the failure rate, attempts, and failure count for your listeners to log or alert on.
3. **11:00:04 PM onward** — Every new `ChargeCustomer` job fails in the middleware in about 1ms, no HTTP call made, and is released back onto the queue with a 20s delay (`release: 20`). Your workers stay free to process jobs for other services instead of blocking on Stripe timeouts.
4. **11:00:34 PM** — 30 seconds after opening, the next job to run is let through as a single probe. The circuit is now half-open; `CircuitBreakerHalfOpen` fires. Every other released job stays parked — the recovery strategy only ever admits one probe at a time (see [Recovery strategies](#recovery-strategies)).
5. **Two outcomes:**
   - **Stripe recovered:** the probe succeeds, the circuit closes, `CircuitBreakerClosed` fires, and the backlog of released jobs drains at full speed as their delays expire.
   - **Stripe is still down:** the probe fails, the circuit reopens immediately (no need to re-accumulate 5 failures), and the cycle repeats from step 4 after another 30s.

Without Fuse, all of those queued jobs would each burn a full HTTP timeout retrying Stripe directly. With it, the queue stops hammering a dead endpoint within one failure window and resumes automatically the moment Stripe answers again.

### Isolating one customer's broken webhook endpoint

A common queue pattern is delivering outbound webhooks to URLs your customers configure themselves — you don't control their uptime, and one customer's misconfigured or down endpoint shouldn't slow down deliveries to everyone else.

Fuse's circuits are just keyed by the string you pass in, so naming the circuit per-recipient gets you per-recipient isolation for free — no extra config needed, since an unconfigured circuit name simply falls back to your `default_*` settings:

```php
class DeliverWebhook implements ShouldQueue
{
    public $tries = 0;
    public $maxExceptions = 3;

    public function __construct(
        private WebhookEndpoint $endpoint,
        private array $payload,
    ) {}

    public function middleware(): array
    {
        return [new CircuitBreakerMiddleware("webhook:{$this->endpoint->id}", release: 30)];
    }

    public function handle(): void
    {
        Http::timeout(5)->post($this->endpoint->url, $this->payload)->throw();
    }
}
```

If endpoint `#42` starts timing out, only the `webhook:42` circuit trips. Deliveries to endpoints `#7`, `#118`, and everyone else keep flowing at full speed through the same queue, same workers, same job class — they're each tracked under their own service name and never see endpoint 42's failures. Once endpoint 42 recovers, its circuit closes independently, exactly like the Stripe scenario above.

**Caveat:** `fuse:status`, `fuse:open`/`fuse:close`, and the status page dashboard all only know about service names listed under `fuse.services` in your config — they can't discover or display dynamically-named circuits like `webhook:42`. If you need CLI/dashboard visibility into a specific high-traffic endpoint, add it to `config/fuse.php` explicitly; otherwise, inspect it programmatically with `(new CircuitBreaker("webhook:{$id}"))->getStats()`.

---

## Status Page

Fuse includes a real-time monitoring dashboard that shows the state of all your circuit breakers.

<p align="center">
  <img src="art/status-page.png" width="900" alt="Fuse Status Page">
</p>

### Enable the Status Page

Add to your `.env`:

```env
FUSE_STATUS_PAGE_ENABLED=true
```

The status page is available at `/fuse` (configurable via `FUSE_STATUS_PAGE_PREFIX`).

### Authorization

Access is controlled by a `viewFuse` gate. By default, only the `local` environment is allowed. Override it in your `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewFuse', function ($user = null) {
    return $user?->isAdmin();
});
```

### Configuration

```php
// config/fuse.php

'status_page' => [
    'enabled' => env('FUSE_STATUS_PAGE_ENABLED', false),
    'prefix' => env('FUSE_STATUS_PAGE_PREFIX', 'fuse'),
    'middleware' => [],          // Custom middleware (replaces default)
    'polling_interval' => 2,    // Frontend refresh interval in seconds
],
```

### What It Shows

- **Circuit state** for each configured service (CLOSED, OPEN, HALF-OPEN)
- **State history** with timestamped transitions
- **Live stats** — attempts, failures, failure rate per window
- **Recovery info** — when the circuit opened and when it will test recovery
- **Auto-refresh** — polls the backend every 2 seconds (configurable)

---

## Artisan Commands

Fuse includes CLI commands for inspecting and manually controlling circuit breakers.

### Check circuit status

```bash
php artisan fuse:status           # all services
php artisan fuse:status stripe    # single service
```

Outputs a table with the current state, failure rate, request counts, and threshold for each circuit.

### Reset a circuit

```bash
php artisan fuse:reset            # all services
php artisan fuse:reset stripe     # single service
```

Resets the circuit to CLOSED state and clears all stats for the current window.

### Manually open a circuit

```bash
php artisan fuse:open stripe
```

Forces the circuit OPEN immediately. Useful when you know a service is down and want to protect your queue before failures accumulate. The circuit will recover automatically after the configured `timeout`.

### Manually close a circuit

```bash
php artisan fuse:close stripe
```

Forces the circuit CLOSED immediately. Useful when a service has recovered but the circuit hasn't timed out yet.

---

## Fallback Strategies

When the circuit opens, your application needs a plan. Here are common strategies:

**Return cached data** — Show last known prices, cached shipping rates, or stale product info. Slightly stale data beats an error page.

**Use a fallback service** — Switch to a backup payment provider, or show "payment pending" and queue it for later.

**Queue for later** — Fuse already does this with `release()`. For synchronous requests, dispatch a job to retry when the circuit closes.

**Graceful degradation** — Hide the feature entirely. Can't load recommendations? Don't show that section. The page still works.

---

## Direct Usage

Use the circuit breaker directly outside of jobs:

```php
use Harris21\Fuse\CircuitBreaker;

$breaker = new CircuitBreaker('stripe');

if (!$breaker->isOpen()) {
    try {
        $result = Stripe::charges()->create([...]);
        $breaker->recordSuccess();
        return $result;
    } catch (Exception $e) {
        $breaker->recordFailure($e);
        throw $e;
    }
} else {
    // Circuit is open - use fallback
    return $this->fallbackResponse();
}
```

### Check Circuit State

```php
$breaker = new CircuitBreaker('stripe');

$breaker->isClosed();    // Normal operations
$breaker->isOpen();      // Protected, failing fast
$breaker->isHalfOpen();  // Testing recovery

$breaker->getStats();    // Get full statistics
$breaker->reset();       // Manually reset to closed
```

---

## Requirements

- PHP 8.3+
- Laravel 11+
- Redis recommended for production, file cache may have race conditions during recovery probing

---

## Credits

Built by [Harris Raftopoulos](https://x.com/harrisrafto) for [Laracon India 2026](https://laracon.in).

YouTube: [@harrisrafto](https://youtube.com/@harrisrafto)

Video walkthrough: [Watch on YouTube](https://www.youtube.com/watch?v=w-QKqTPbcqs)

Based on the circuit breaker pattern from Michael Nygard's *Release It!* and popularized by Martin Fowler.

---

## License

MIT
