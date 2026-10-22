<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers 404 outside the local environment.
 *
 * Route::development() puts its routes behind it. The macro already skips the routes outside local, but a
 * route cache built with APP_ENV=local (in a CI image, for example) still contains them; this middleware
 * keeps such cached routes from answering in production.
 *
 * @package Adonyarik\ConsistentApi\Middleware
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class DevelopmentOnlyMiddleware
{
    /**
     * Passes the request on in the local environment, answers 404 elsewhere.
     *
     * @param  Request  $request  Incoming request
     * @param  Closure  $next  Next middleware
     *
     * @return Response Response of the next middleware
     *
     * @throws NotFoundHttpException Outside the local environment
     */
    public function handle(Request $request, Closure $next)
    {
        if (! App::isLocal()) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
