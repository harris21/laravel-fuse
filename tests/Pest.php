<?php

use Carbon\Carbon;
use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;

uses(TestCase::class)->in('Feature');

function forceHalfOpen(string $service = 'test-service'): CircuitBreaker
{
    $breaker = new CircuitBreaker($service);
    $breaker->forceOpen();
    Cache::put($breaker->key('opened_at'), now()->getTimestamp() - $breaker->timeout() - 1);
    $breaker->isOpen();

    return $breaker;
}

function useCacheThatCannotIncrement(): void
{
    Cache::extend('cannot-increment', fn () => Cache::repository(new class extends ArrayStore
    {
        public function increment($key, $value = 1)
        {
            throw new RuntimeException('cache increment failed');
        }
    }));

    config([
        'cache.stores.cannot-increment' => ['driver' => 'cannot-increment'],
        'cache.default' => 'cannot-increment',
    ]);
}

function tripToHalfOpen(string $service = 'test-service'): CircuitBreaker
{
    config(['fuse.default_timeout' => 1]);

    $breaker = new CircuitBreaker($service);
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();

    Carbon::setTestNow(now()->addSeconds(2));
    $breaker->isOpen();
    expect($breaker->isHalfOpen())->toBeTrue();

    return $breaker;
}

function makeJob(): object
{
    return new class
    {
        public bool $handled = false;

        public bool $released = false;

        public int $releaseDelay = 0;

        public ?object $job = null;

        public function release(int $delay): string
        {
            $this->released = true;
            $this->releaseDelay = $delay;

            return 'released';
        }
    };
}
