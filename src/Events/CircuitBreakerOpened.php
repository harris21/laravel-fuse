<?php

namespace Harris21\Fuse\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CircuitBreakerOpened
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $service,
        public readonly float $failureRate = 0,
        public readonly int $attempts = 0,
        public readonly int $failures = 0
    ) {}
}
