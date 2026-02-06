<?php

use Harris21\Fuse\Http\Controllers\StatusPageController;
use Harris21\Fuse\Http\Middleware\StatusPageMiddleware;
use Illuminate\Support\Facades\Route;

$prefix = config('fuse.status_page.prefix', 'fuse');

$configuredMiddleware = config('fuse.status_page.middleware', []);
$middleware = ['web'];

if (empty($configuredMiddleware)) {
    $middleware[] = StatusPageMiddleware::class;
} else {
    $middleware = array_merge($middleware, $configuredMiddleware);
}

Route::middleware($middleware)
    ->prefix($prefix)
    ->group(function () {
        Route::get('/', [StatusPageController::class, 'index'])->name('fuse.status');
        Route::get('/data', [StatusPageController::class, 'data'])->name('fuse.status.data');
    });
