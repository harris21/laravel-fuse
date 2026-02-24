<?php

namespace Harris21\Fuse;

use Harris21\Fuse\Commands\FuseOpenCommand;
use Harris21\Fuse\Commands\FuseResetCommand;
use Harris21\Fuse\Commands\FuseStatusCommand;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
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
            ]);
    }

    public function packageBooted(): void
    {
        $this->callAfterResolving(GateContract::class, function (GateContract $gate) {
            if (! $gate->has('viewFuse')) {
                $gate->define('viewFuse', fn ($user = null) => $this->app->environment('local'));
            }
        });
    }
}
