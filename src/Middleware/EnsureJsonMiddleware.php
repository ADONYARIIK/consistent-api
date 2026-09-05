<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EnsureJsonMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH']) && !$request->isJson()) {
            return response()->json(['error' => 'The request must have Content-Type: application/json header.'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        return $next($request);
    }
}
