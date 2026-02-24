<?php

namespace Harris21\Fuse\Commands;

use Harris21\Fuse\CircuitBreaker;
use Illuminate\Console\Command;

class FuseResetCommand extends Command
{
    protected $signature = 'fuse:reset {service?}';

    protected $description = 'Reset circuit breakers to closed state';

    public function handle(): int {
        $service = $this->argument('service');

        $breaker = new CircuitBreaker($service);
        $breaker->reset();

        $this->info("Circuit breaker {$service} reset to closed state");

        return self::SUCCESS;
    }
}