<?php

namespace Harris21\Fuse\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class StatusPageMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('fuse.status_page.enabled', false)) {
            abort(404);
        }

        Gate::authorize('viewFuse');

        return $next($request);
    }
}
