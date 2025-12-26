<?php

namespace Harris21\Fuse\Tests;

use Harris21\Fuse\FuseServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            FuseServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('fuse.enabled', true);
        $app['config']->set('fuse.default_threshold', 50);
        $app['config']->set('fuse.default_timeout', 60);
        $app['config']->set('fuse.default_min_requests', 10);
    }
}
