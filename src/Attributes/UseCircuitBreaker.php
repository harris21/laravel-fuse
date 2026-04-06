<?php

namespace Harris21\Fuse\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class UseCircuitBreaker
{
    public function __construct(
        public readonly string $service,
        public readonly ?int $release = null,
    ) {}
}
