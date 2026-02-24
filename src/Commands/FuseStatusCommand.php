<?php

namespace Harris21\Fuse\Commands;

use Harris21\Fuse\CircuitBreaker;
use Illuminate\Console\Command;

class FuseStatusCommand extends Command
{
    protected $signature = 'fuse:status {service?}';
    protected $description = 'Display the status of circuit breakers';

    public function handle(): int
    {
        $services = $this->argument('service')
            ? [$this->argument('service')]
            : array_keys(config('fuse.services', []));

        if (empty($services)) {
            $this->warn('No services configured in config/fuse.php');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($services as $service) {
            $breaker = new CircuitBreaker($service);
            $stats = $breaker->getStats();

            $state = match(true) {
                $breaker->isOpen()     => '<fg=red>OPEN</>',
                $breaker->isHalfOpen() => '<fg=yellow>HALF-OPEN</>',
                default                => '<fg=green>CLOSED</>',
            };

            $rows[] = [
                $service,
                $state,
                number_format($stats['failure_rate'], 1) . '%',
                $stats['attempts'],
                $stats['failures'],
                $stats['threshold'] . '%',
            ];
        }

        $this->table(['Service', 'State', 'Failure Rate', 'Requests', 'Failures', 'Threshold'], $rows);

        return self::SUCCESS;
    }
}
