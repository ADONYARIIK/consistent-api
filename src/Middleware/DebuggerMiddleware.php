<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Middleware;

use Adonyarik\ConsistentApi\Providers\DebuggerServiceProvider;
use Closure;
use Illuminate\Http\Request;

class DebuggerMiddleware
{
    public function __construct(private DebuggerServiceProvider $debugger) {}

    public function handle(Request $request, Closure $next)
    {
        if (! (bool) config('consistentapi.debugger_enabled')) {
            return $next($request);
        }

        $this->debugger->boot();

        $response = $next($request);

        return $this->debugger->modifyResponse($request, $response) ?? $response;
    }
}
