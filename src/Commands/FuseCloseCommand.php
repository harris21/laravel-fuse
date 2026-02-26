<?php

namespace Harris21\Fuse\Commands;

use Harris21\Fuse\CircuitBreaker;
use Illuminate\Console\Command;

class FuseCloseCommand extends Command
{
    protected $signature = 'fuse:close {service}';

    protected $description = 'Manually close circuit breaker';

    public function handle(): int
    {
        $service = $this->argument('service');

        (new CircuitBreaker($service))->forceClose();

        $this->info("Circuit breaker for {$service} has been manually closed.");

        return self::SUCCESS;
    }
}
