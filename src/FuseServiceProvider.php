<?php

namespace Harris21\Fuse;

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
            ->hasRoute('web');
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
