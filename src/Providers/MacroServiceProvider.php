<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Providers;

use Adonyarik\ConsistentApi\Middleware\DevelopmentOnlyMiddleware;
use Closure;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the route macros of the package.
 *
 * Currently the Route::development() macro for routes that exist only in the local environment.
 * ConsistentApiProvider registers it.
 *
 * @package Adonyarik\ConsistentApi\Providers
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class MacroServiceProvider extends ServiceProvider
{
    /**
     * Adds the Route::development() macro.
     *
     * The macro is declared while registering, not while booting: route files run while the providers boot
     * (Laravel loads them right after its own RouteServiceProvider boots), and a macro declared in boot()
     * might not exist yet. Macros are static on the Router class, so nothing has to be resolved here.
     *
     * Route::development(callable $routes): void calls $routes, which declares routes as usual, only when
     * the application environment is local; elsewhere the routes do not exist. Inside a route file it is
     * used like a group: Route::development(function () { Route::get(...); }). The routes also run
     * DevelopmentOnlyMiddleware, so a route cache built in the local environment does not serve them
     * elsewhere.
     *
     * @see DevelopmentOnlyMiddleware
     */
    public function register(): void
    {
        Router::macro('development', function (callable $routes): void {
            if (! App::isLocal()) {
                return;
            }

            /** @var Router $this */
            $this->middleware(DevelopmentOnlyMiddleware::class)->group(Closure::fromCallable($routes));
        });
    }
}
