<?php

namespace Harris21\Fuse\Commands;

use Harris21\Fuse\CircuitBreaker;
use Illuminate\Console\Command;

class FuseResetCommand extends Command
{
    protected $signature = 'fuse:reset {service?}';

    protected $description = 'Reset circuit breakers to closed state';

    public function handle(): int
    {
        $service = $this->argument('service');

        if ($service && ! array_key_exists($service, config('fuse.services', []))) {
            $this->warn("Service '{$service}' is not configured in config/fuse.php");

            return self::SUCCESS;
        }

        $services = $service
            ? [$service]
            : array_keys(config('fuse.services', []));

        if (empty($services)) {
            $this->warn('No services configured in config/fuse.php');

            return self::SUCCESS;
        }

        foreach ($services as $service) {
            (new CircuitBreaker($service))->reset();

            $this->info("Circuit breaker {$service} has been reset to closed state");
        }

        return self::SUCCESS;
    }
}
