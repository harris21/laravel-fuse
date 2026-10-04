<?php

namespace Harris21\Fuse\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStatusPageIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('fuse.status_page.enabled', false)) {
            abort(404);
        }

        return $next($request);
    }
}
