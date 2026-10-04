<?php

use Carbon\Carbon;
use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Contracts\JobAwareRecoveryStrategy;
use Harris21\Fuse\HeldJob;
use Harris21\Fuse\Middleware\CircuitBreakerMiddleware;
use Harris21\Fuse\Strategies\OldestJobProbe;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Exceptions;

class CandidateStoreThatThrows extends OldestJobProbe
{
    public function observeHeldJob(CircuitBreaker $breaker, HeldJob $job): void
    {
        throw new RuntimeException('candidate store down');
    }
}

beforeEach(function () {
    Cache::flush();
    config(['fuse.enabled' => true]);
    config(['fuse.default_threshold' => 50]);
    config(['fuse.default_timeout' => 60]);
    config(['fuse.default_min_requests' => 5]);
    config(['fuse.default_release' => 10]);
    config(['fuse.services.test-service.recovery_strategy' => OldestJobProbe::class]);
});

function makeQueuedJob(?string $uuid, int $createdAt = 1000, ?int $timeout = null): object
{
    $job = makeJob();

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('uuid')->andReturn($uuid);
    $queueJob->shouldReceive('timeout')->andReturn($timeout);
    $queueJob->shouldReceive('payload')->andReturn([
        'uuid' => $uuid,
        'createdAt' => $createdAt,
        'displayName' => 'App\\Jobs\\ChargeCustomer',
    ]);

    $job->job = $queueJob;

    return $job;
}

function tripOpen(string $service = 'test-service'): CircuitBreaker
{
    $breaker = new CircuitBreaker($service);
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    return $breaker;
}

function candidateFor(string $service = 'test-service'): ?array
{
    return (new CircuitBreaker($service))->getStats()['probe_candidate'];
}

it('elects the oldest held job while the circuit is open', function () {
    tripOpen();
    $middleware = new CircuitBreakerMiddleware('test-service');

    $middleware->handle(makeQueuedJob('newer', 2000), fn () => 'success');
    $middleware->handle(makeQueuedJob('older', 1000), fn () => 'success');
    $middleware->handle(makeQueuedJob('newest', 3000), fn () => 'success');

    expect(candidateFor()['uuid'])->toBe('older');
});

it('keeps the first-seen job on a same-second tie', function () {
    tripOpen();
    $middleware = new CircuitBreakerMiddleware('test-service');

    $middleware->handle(makeQueuedJob('first', 1000), fn () => 'success');
    $middleware->handle(makeQueuedJob('second', 1000), fn () => 'success');

    expect(candidateFor()['uuid'])->toBe('first');
});

it('releases a held job when the strategy cannot record it', function () {
    Exceptions::fake();
    config(['fuse.services.test-service.recovery_strategy' => CandidateStoreThatThrows::class]);
    tripOpen();
    $job = makeQueuedJob('older');

    $result = (new CircuitBreakerMiddleware('test-service'))->handle($job, fn () => 'charged');

    expect($result)->toBe('released')
        ->and($job->released)->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'candidate store down');
});

it('dates a held job without a createdAt by the Carbon clock', function () {
    Carbon::setTestNow('2026-01-01 12:00:00');
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('uuid')->andReturn('legacy');
    $queueJob->shouldReceive('payload')->andReturn(['uuid' => 'legacy', 'displayName' => 'App\\Jobs\\ChargeCustomer']);

    expect(HeldJob::fromQueueJob($queueJob)->createdAt)->toBe(now()->getTimestamp());
});

it('uses the middleware release override for the candidate ttl', function () {
    Carbon::setTestNow(Carbon::now());
    config(['fuse.default_timeout' => 1]);
    config(['fuse.default_release' => 1]);

    try {
        tripOpen();
        (new CircuitBreakerMiddleware('test-service', release: 10))
            ->handle(makeQueuedJob('oldest'), fn () => 'success');

        Carbon::setTestNow(now()->addSeconds(5));

        expect(candidateFor())->not->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

it('admits only the elected job in half-open and releases the rest', function () {
    tripToHalfOpen();
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'older', 'created_at' => 1000, 'name' => 'A'], 60);

    $middleware = new CircuitBreakerMiddleware('test-service');

    $other = makeQueuedJob('newer', 2000);
    $result = $middleware->handle($other, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($other->handled)->toBeFalse();
    expect($other->released)->toBeTrue();
    expect($result)->toBe('released');

    $elected = makeQueuedJob('older', 1000);
    $middleware->handle($elected, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($elected->handled)->toBeTrue();
});

it('releases a duplicate delivery of the elected job while its probe is running', function () {
    tripToHalfOpen();
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'older', 'created_at' => 1000, 'name' => 'A'], 60);

    $middleware = new CircuitBreakerMiddleware('test-service');
    $duplicate = makeQueuedJob('older');

    $middleware->handle(makeQueuedJob('older'), function () use ($middleware, $duplicate) {
        $result = $middleware->handle($duplicate, function ($job) {
            $job->handled = true;

            return 'success';
        });

        expect($result)->toBe('released');

        return 'success';
    });

    expect($duplicate->handled)->toBeFalse()
        ->and($duplicate->released)->toBeTrue();
});

it('keeps the probe lock beyond the configured ttl when the job timeout is longer', function () {
    Carbon::setTestNow(Carbon::now());
    config(['fuse.default_probe_lock_ttl' => 5]);

    try {
        tripToHalfOpen();
        Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'older', 'created_at' => 1000, 'name' => 'A'], 60);

        $middleware = new CircuitBreakerMiddleware('test-service');
        $duplicate = makeQueuedJob('older', timeout: 20);

        $middleware->handle(makeQueuedJob('older', timeout: 20), function () use ($middleware, $duplicate) {
            Carbon::setTestNow(now()->addSeconds(6));

            $result = $middleware->handle($duplicate, fn () => 'success');

            expect($result)->toBe('released');

            return 'success';
        });

        expect($duplicate->released)->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('keeps the same job as the probe after a failed probe', function () {
    tripOpen();
    $middleware = new CircuitBreakerMiddleware('test-service');
    $middleware->handle(makeQueuedJob('older', 1000), fn () => 'success');

    Carbon::setTestNow(now()->addSeconds(2));
    config(['fuse.default_timeout' => 1]);
    (new CircuitBreaker('test-service'))->isOpen();
    expect((new CircuitBreaker('test-service'))->isHalfOpen())->toBeTrue();

    try {
        $middleware->handle(makeQueuedJob('older', 1000), function () {
            throw new Exception('still down');
        });
    } catch (Exception) {
    }

    expect((new CircuitBreaker('test-service'))->isOpen())->toBeTrue();
    expect(candidateFor()['uuid'])->toBe('older');
});

it('clears the candidate and closes the circuit when the probe succeeds', function () {
    tripToHalfOpen();
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'older', 'created_at' => 1000, 'name' => 'A'], 60);
    Cache::put('fuse:probe-candidate:older', 'test-service', 60);

    $middleware = new CircuitBreakerMiddleware('test-service');
    $middleware->handle(makeQueuedJob('older', 1000), fn () => 'success');

    expect((new CircuitBreaker('test-service'))->isClosed())->toBeTrue();
    expect(candidateFor())->toBeNull();
    expect(Cache::get('fuse:probe-candidate:older'))->toBeNull();
});

it('clears the candidate and closes the circuit when counting the successful probe fails', function () {
    Exceptions::fake();
    useCacheThatCannotIncrement();
    forceHalfOpen();
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'older', 'created_at' => 1000, 'name' => 'A'], 60);
    Cache::put('fuse:probe-candidate:older', 'test-service', 60);

    $result = (new CircuitBreakerMiddleware('test-service'))->handle(makeQueuedJob('older', 1000), fn () => 'success');

    expect($result)->toBe('success');
    expect((new CircuitBreaker('test-service'))->isClosed())->toBeTrue();
    expect(candidateFor())->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'cache increment failed');
});

it('clears the candidate when the circuit is force closed', function () {
    $breaker = tripOpen();
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'older', 'created_at' => 1000, 'name' => 'A'], 60);
    Cache::put('fuse:probe-candidate:older', 'test-service', 60);

    expect($breaker->forceClose())->toBeTrue();

    expect($breaker->isClosed())->toBeTrue()
        ->and(Cache::get('fuse:test-service:probe-candidate'))->toBeNull()
        ->and(Cache::get('fuse:probe-candidate:older'))->toBeNull();
});

it('repairs a stale candidate when force closing an already closed circuit', function () {
    $breaker = new CircuitBreaker('test-service');
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'older', 'created_at' => 1000, 'name' => 'A'], 60);
    Cache::put('fuse:probe-candidate:older', 'test-service', 60);

    expect($breaker->forceClose())->toBeFalse();

    expect(Cache::get('fuse:test-service:probe-candidate'))->toBeNull()
        ->and(Cache::get('fuse:probe-candidate:older'))->toBeNull();
});

it('does not elect a held job after the circuit has closed', function () {
    $breaker = new CircuitBreaker('test-service');
    $strategy = $breaker->recoveryStrategy();

    expect($strategy)->toBeInstanceOf(OldestJobProbe::class);
    assert($strategy instanceof OldestJobProbe);

    $strategy->observeHeldJob($breaker, new HeldJob('late', 1000, 'A'));

    expect(Cache::get('fuse:test-service:probe-candidate'))->toBeNull()
        ->and(Cache::get('fuse:probe-candidate:late'))->toBeNull();
});

it('starts a fresh election when a closed circuit opens again', function () {
    $breaker = new CircuitBreaker('test-service');
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'stale', 'created_at' => 1000, 'name' => 'A'], 60);
    Cache::put('fuse:probe-candidate:stale', 'test-service', 60);

    expect($breaker->forceOpen())->toBeTrue();

    expect(Cache::get('fuse:test-service:probe-candidate'))->toBeNull()
        ->and(Cache::get('fuse:probe-candidate:stale'))->toBeNull();
});

it('elects the first job seen in half-open when no candidate exists', function () {
    tripToHalfOpen();
    $middleware = new CircuitBreakerMiddleware('test-service');

    $first = makeQueuedJob('first', 1000);
    $middleware->handle($first, function ($job) {
        $job->handled = true;

        return 'success';
    });

    expect($first->handled)->toBeTrue();
});

it('clears the candidate when that job fails for good', function () {
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'older', 'created_at' => 1000, 'name' => 'A'], 60);
    Cache::put('fuse:probe-candidate:older', 'test-service', 60);

    $queueJob = makeQueuedJob('older')->job;
    event(new JobFailed('redis', $queueJob, new Exception('boom')));

    expect(candidateFor())->toBeNull();
    expect(Cache::get('fuse:probe-candidate:older'))->toBeNull();
});

it('does not let stale failure cleanup delete a replacement candidate', function () {
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'replacement', 'created_at' => 500, 'name' => 'B'], 60);
    Cache::put('fuse:probe-candidate:stale', 'test-service', 60);
    Cache::put('fuse:probe-candidate:replacement', 'test-service', 60);

    OldestJobProbe::forget('fuse', 'test-service', 'stale');

    expect(Cache::get('fuse:test-service:probe-candidate')['uuid'])->toBe('replacement')
        ->and(Cache::get('fuse:probe-candidate:stale'))->toBeNull()
        ->and(Cache::get('fuse:probe-candidate:replacement'))->toBe('test-service');
});

it('leaves candidate keys for ttl cleanup when the election lock is unavailable', function () {
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'older', 'created_at' => 1000, 'name' => 'A'], 60);
    Cache::put('fuse:probe-candidate:older', 'test-service', 60);

    $lock = Cache::lock('fuse:test-service:probe-candidate-lock', 2);
    expect($lock->get())->toBeTrue();

    try {
        OldestJobProbe::forget('fuse', 'test-service', 'older');

        expect(Cache::get('fuse:test-service:probe-candidate')['uuid'])->toBe('older')
            ->and(Cache::get('fuse:probe-candidate:older'))->toBe('test-service');
    } finally {
        $lock->forceRelease();
    }
});

it('falls back to single-probe behavior when the job has no uuid', function () {
    tripToHalfOpen();

    $prefix = config('fuse.cache.prefix');
    $probeLock = Cache::lock("{$prefix}:test-service:probe", 5);
    expect($probeLock->get())->toBeTrue();

    $middleware = new CircuitBreakerMiddleware('test-service');
    $job = makeQueuedJob(null);

    $result = $middleware->handle($job, fn () => 'success');

    expect($job->handled)->toBeFalse();
    expect($job->released)->toBeTrue();
    expect($result)->toBe('released');

    $probeLock->forceRelease();
});

it('exposes the candidate in the breaker stats', function () {
    tripOpen();
    (new CircuitBreakerMiddleware('test-service'))->handle(makeQueuedJob('older', 1000), fn () => 'success');

    $stats = (new CircuitBreaker('test-service'))->getStats();

    expect($stats['probe_candidate'])->toBe([
        'uuid' => 'older',
        'created_at' => 1000,
        'name' => 'App\\Jobs\\ChargeCustomer',
    ]);
});

it('reports no candidate while the circuit is closed', function () {
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'stale', 'created_at' => 1000, 'name' => 'A'], 60);

    expect(candidateFor())->toBeNull();
});

it('reports no candidate under the default strategy', function () {
    config(['fuse.services.test-service.recovery_strategy' => null]);
    tripOpen();
    (new CircuitBreakerMiddleware('test-service'))->handle(makeQueuedJob('older', 1000), fn () => 'success');

    expect(candidateFor())->toBeNull();
});

it('leaves the cache alone on JobFailed when no service elects probes', function () {
    config(['fuse.services.test-service.recovery_strategy' => null]);
    Cache::put('fuse:probe-candidate:older', 'test-service', 60);
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'older', 'created_at' => 1000, 'name' => 'A'], 60);

    event(new JobFailed('redis', makeQueuedJob('older')->job, new Exception('boom')));

    expect(Cache::get('fuse:probe-candidate:older'))->toBe('test-service');
});

it('does not apply OldestJobProbe cleanup to another job-aware strategy', function () {
    $strategy = new class implements JobAwareRecoveryStrategy
    {
        public function observeHeldJob(CircuitBreaker $breaker, HeldJob $job): void {}

        public function allowsAttemptFor(CircuitBreaker $breaker, HeldJob $job): bool
        {
            return false;
        }

        public function candidate(CircuitBreaker $breaker): ?array
        {
            return null;
        }

        public function forgetCandidate(CircuitBreaker $breaker): void {}

        public function allowsAttempt(CircuitBreaker $breaker): bool
        {
            return false;
        }

        public function recordSuccess(CircuitBreaker $breaker): bool
        {
            return false;
        }

        public function recordFailure(CircuitBreaker $breaker): void {}
    };

    config(['fuse.services.test-service.recovery_strategy' => $strategy::class]);
    Cache::put('fuse:probe-candidate:older', 'test-service', 60);
    Cache::put('fuse:test-service:probe-candidate', ['uuid' => 'older', 'created_at' => 1000, 'name' => 'A'], 60);

    event(new JobFailed('redis', makeQueuedJob('older')->job, new Exception('boom')));

    expect(Cache::get('fuse:probe-candidate:older'))->toBe('test-service')
        ->and(Cache::get('fuse:test-service:probe-candidate'))->not->toBeNull();
});

it('never lets a configuration failure escape the JobFailed listener', function () {
    Config::partialMock()
        ->shouldReceive('get')
        ->with('fuse.services', [])
        ->andThrow(new RuntimeException('config unavailable'));

    event(new JobFailed('redis', makeQueuedJob('older')->job, new Exception('boom')));
})->throwsNoExceptions();

it('never lets a cache failure escape the JobFailed listener', function () {
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));

    event(new JobFailed('redis', makeQueuedJob('older')->job, new Exception('boom')));
})->throwsNoExceptions();
