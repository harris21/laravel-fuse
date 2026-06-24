<?php

namespace Harris21\Fuse\Commands;

use Harris21\Fuse\CircuitBreaker;
use Illuminate\Console\Command;

class FuseStatusCommand extends Command
{
    protected $signature = 'fuse:status {service?}
		{--json : JSON Output}
		{--watch : Refresh continuously}
		{--interval=5 : Refresh interval in seconds for watch mode}
		{--iterations=0 : Number of refresh cycles before stopping in watch mode (0 = infinite)}';

    protected $description = 'Display the status of circuit breakers';

    public function handle(): int
    {
        $services = $this->resolveServices();

        if ($services === null) {
            return self::SUCCESS;
        }

        if ($this->option('watch') && $this->option('json')) {
            $this->warn('The --watch and --json options cannot be used together.');

            return self::INVALID;
        }

        if ($this->option('watch')) {
            return $this->watch($services);
        }

        $payload = $this->buildPayload($services);

        if ($this->option('json')) {
            $this->line(
                json_encode([
                    'services' => $payload,
                ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ));

            return self::SUCCESS;
        }

        $this->renderTable($payload);

        return self::SUCCESS;
    }

    private function resolveServices(): ?array
    {
        $service = $this->argument('service');

        if (
            $service &&
            ! array_key_exists($service, config('fuse.services', []))
        ) {
            $this->warn(
                "Service '{$service}' is not configured in config/fuse.php",
            );

            return null;
        }

        $services = $service
            ? [$service]
            : array_keys(config('fuse.services', []));

        if (empty($services)) {
            $this->warn('No services configured in config/fuse.php');

            return null;
        }

        return $services;
    }

    private function buildPayload(array $services): array
    {
        $payload = [];
        foreach ($services as $service) {
            $breaker = new CircuitBreaker((string) $service);
            $stats = $breaker->getStats();

            $payload[] = [
                'service' => $service,
                'state' => $stats['state'],
                'failure_rate' => $stats['failure_rate'],
                'attempts' => $stats['attempts'],
                'failures' => $stats['failures'],
                'threshold' => $stats['threshold'],
                'min_requests' => $stats['min_requests'],
                'timeout' => $stats['timeout'],
                'window' => $stats['window'],
                'opened_at' => $stats['opened_at'],
                'recovery_at' => $stats['recovery_at'],
            ];
        }

        return $payload;
    }

    private function renderTable(array $payload): void
    {
        $rows = array_map(function (array $service): array {
            $state = match ($service['state']) {
                'open' => '<fg=red>OPEN</>',
                'half_open' => '<fg=yellow>HALF-OPEN</>',
                default => '<fg=green>CLOSED</>',
            };

            return [
                $service['service'],
                $state,
                number_format((float) $service['failure_rate'], 1).'%',
                $service['attempts'],
                $service['failures'],
                $service['threshold'].'%',
                $service['timeout'].'s',
                $service['window'].'s',
            ];
        }, $payload);

        $this->table(['Service', 'State', 'Failure Rate',
            'Requests', 'Failures', 'Threshold',
            'Timeout', 'Window',
        ],
            $rows,
        );
    }

    private function watch(array $services): int
    {
        $interval = max(1, (int) $this->option('interval'));
        $iterations = max(0, (int) $this->option('iterations'));
        $runCount = 0;

        while (true) {
            $payload = $this->buildPayload($services);

            $this->clearScreen();
            $this->line('Fuse status - '.now()->toDateTimeString());
            $this->newLine();

            if ($this->option('json')) {
                $this->line(
                    json_encode(
                        [
                            'services' => $payload,
                            'generated_at' => now()->toIso8601String(),
                        ],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    ),
                );
            } else {
                $this->renderTable($payload);
            }

            $runCount++;

            if ($iterations > 0 && $runCount >= $iterations) {
                return self::SUCCESS;
            }

            sleep($interval);
        }
    }

    private function clearScreen(): void
    {
        $this->output->write("\033[2J\033[H");
    }
}
