<?php

namespace Harris21\Fuse\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CircuitBreakerHalfOpen
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $service
    ) {}
}
