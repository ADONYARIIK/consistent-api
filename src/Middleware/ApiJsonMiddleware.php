<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiJsonMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is(config('consistentapi.api_url_prefix') . '/*')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
