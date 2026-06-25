<?php

namespace Harris21\Fuse\Commands;

use Harris21\Fuse\CircuitBreaker;
use Illuminate\Console\Command;

class FuseStatusCommand extends Command
{
    protected $signature = 'fuse:status {service?}
		{--json : JSON Output}';

    protected $description = 'Display the status of circuit breakers';

    public function handle(): int
    {
        $services = $this->resolveServices();

        if ($services === null) {
            return self::SUCCESS;
        }

        $payload = $this->buildPayload($services);

        if ($this->option('json')) {
            $json = json_encode(
                ['services' => $payload],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                512,
            );

            $this->line($json);

            return self::SUCCESS;
        }

        $this->renderTable($payload);

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
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
            : array_map(
                static fn (
                    mixed $configuredService,
                ): string => (string) $configuredService,
                array_keys(config('fuse.services', [])),
            );

        if (empty($services)) {
            $this->warn('No services configured in config/fuse.php');

            return null;
        }

        return $services;
    }

    /**
     * @param  list<string>  $services
     * @return list<array{
     *     service: string,
     *     state: string,
     *     failure_rate: float|int,
     *     attempts: int,
     *     failures: int,
     *     threshold: int,
     *     min_requests: int,
     *     timeout: int,
     *     window: int,
     *     opened_at: int|null,
     *     recovery_at: int|null
     * }>
     */
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

    /**
     * @param  list<array{
     *     service: string,
     *     state: string,
     *     failure_rate: float|int,
     *     attempts: int,
     *     failures: int,
     *     threshold: int,
     *     min_requests: int,
     *     timeout: int,
     *     window: int,
     *     opened_at: int|null,
     *     recovery_at: int|null
     * }>  $payload
     */
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

        $this->table(
            [
                'Service',
                'State',
                'Failure Rate',
                'Requests',
                'Failures',
                'Threshold',
                'Timeout',
                'Window',
            ],
            $rows,
        );
    }
}
