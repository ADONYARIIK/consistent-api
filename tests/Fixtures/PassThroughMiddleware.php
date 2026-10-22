<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stands in for auth / verified / can so access groups can be tested without real authorization.
 */
class PassThroughMiddleware
{
    public function handle(Request $request, Closure $next, string ...$parameters): Response
    {
        return $next($request);
    }
}
