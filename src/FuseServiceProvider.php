<?php

namespace Harris21\Fuse;

use Harris21\Fuse\Commands\FuseCloseCommand;
use Harris21\Fuse\Commands\FuseOpenCommand;
use Harris21\Fuse\Commands\FuseResetCommand;
use Harris21\Fuse\Commands\FuseStatusCommand;
use Harris21\Fuse\Listeners\ClearFailedProbeCandidate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FuseServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('fuse')
            ->hasConfigFile()
            ->hasViews()
            ->hasRoute('web')
            ->hasCommands([
                FuseStatusCommand::class,
                FuseResetCommand::class,
                FuseOpenCommand::class,
                FuseCloseCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        Event::listen(JobFailed::class, ClearFailedProbeCandidate::class);

        $this->callAfterResolving(GateContract::class, function (GateContract $gate) {
            if (! $gate->has('viewFuse')) {
                $gate->define('viewFuse', fn ($user = null) => $this->app->environment('local'));
            }
        });
    }
}
