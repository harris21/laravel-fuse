<?php

namespace Harris21\Fuse\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class FuseOpenCommand extends Command
{
    protected $signature = 'fuse:open {service?} {--duration=300 : Duration in seconds to keep circuit open}';

    protected $description = 'Open circuit breaker';

    public function handle(): int
    {
        $service = $this->argument('service');

        $duration = (int) $this->option('duration');

        Cache::put("fuse:{$service}:open", true, $duration);

        $this->info("Circuit breaker for {$service} opened for {$duration} seconds");

        return self::SUCCESS;
    }
}