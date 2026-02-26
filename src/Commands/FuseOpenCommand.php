<?php

namespace Harris21\Fuse\Commands;

use Harris21\Fuse\CircuitBreaker;
use Illuminate\Console\Command;

class FuseOpenCommand extends Command
{
    protected $signature = 'fuse:open {service}';

    protected $description = 'Manually open circuit breaker';

    public function handle(): int
    {
        $service = $this->argument('service');

        if (! array_key_exists($service, config('fuse.services', []))) {
            $this->warn("Service '{$service}' is not configured in config/fuse.php");

            return self::SUCCESS;
        }

        (new CircuitBreaker($service))->forceOpen();

        $this->info("Circuit breaker for {$service} has been manually opened.");

        return self::SUCCESS;
    }
}
