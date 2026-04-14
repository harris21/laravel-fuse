<?php

namespace Harris21\Fuse\Middleware;

use Harris21\Fuse\Attributes\UseCircuitBreaker;
use ReflectionClass;

class ResolvesCircuitBreakers
{
    public static function resolve(object $job): array
    {
        $reflection = new ReflectionClass($job);

        return array_map(
            static function ($attribute): CircuitBreakerMiddleware {
                $instance = $attribute->newInstance();

                return new CircuitBreakerMiddleware(
                    $instance->service,
                    release: $instance->release,
                );
            },
            $reflection->getAttributes(UseCircuitBreaker::class)
        );
    }

    public static function merge(object $job, array $middleware = []): array
    {
        return [
            ...static::resolve($job),
            ...$middleware,
        ];
    }
}
