<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Providers;

use Adonyarik\ConsistentApi\Exceptions\InvalidAccessException;
use Adonyarik\ConsistentApi\Middleware\DevelopmentOnlyMiddleware;
use Adonyarik\ConsistentApi\Support\AccessResolver;
use Adonyarik\ConsistentApi\Support\AccessRouteRegistrar;
use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the route macros of the package.
 *
 * Route::development() for routes that exist only in the local environment, and Route::access() for
 * routes protected by the access groups of consistentapi.accesses. ConsistentApiProvider registers it.
 *
 * @package Adonyarik\ConsistentApi\Providers
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class MacroServiceProvider extends ServiceProvider
{
    /**
     * Adds the Route::development() and Route::access() macros.
     *
     * The macros are declared while registering, not while booting: route files run while the providers
     * boot (Laravel loads them right after its own RouteServiceProvider boots), and a macro declared in
     * boot() might not exist yet. Macros are static on their classes, so nothing has to be resolved here.
     *
     * @see MacroServiceProvider::registerDevelopmentMacro()
     * @see MacroServiceProvider::registerAccessMacros()
     */
    public function register(): void
    {
        $this->registerDevelopmentMacro();
        $this->registerAccessMacros();
    }

    /**
     * Adds the Route::development() macro.
     *
     * Route::development(callable $routes): void calls $routes, which declares routes as usual, only when
     * the application environment is local; elsewhere the routes do not exist. Inside a route file it is
     * used like a group: Route::development(function () { Route::get(...); }). The routes also run
     * DevelopmentOnlyMiddleware, so a route cache built in the local environment does not serve them
     * elsewhere.
     *
     * @see DevelopmentOnlyMiddleware
     */
    private function registerDevelopmentMacro(): void
    {
        Router::macro('development', function (callable $routes): void {
            if (! App::isLocal()) {
                return;
            }

            /** @var Router $this */
            $this->middleware(DevelopmentOnlyMiddleware::class)->group(Closure::fromCallable($routes));
        });
    }

    /**
     * Adds the access() macro to the router, to route registrars and to routes.
     *
     * access(string|array ...$accesses) puts the middleware of access groups from consistentapi.accesses
     * on routes, so a route file names the group instead of repeating its middleware: a typo in a group
     * name or a forgotten middleware cannot slip through, and a route with access('admin') always has the
     * admin access that #[Filterable] / #[Sortable] restrictions check. The middleware are read from the
     * config while the routes register, as if they were written in the route file.
     *
     * - Route::access('admin') returns an AccessRouteRegistrar to chain group(), get(), prefix(), … on.
     *   Its middleware() adds to the group's middleware instead of replacing them, as Laravel's would.
     * - Route::prefix('admin')->access('admin') continues any registrar as an AccessRouteRegistrar with
     *   the attributes set so far.
     * - Route::get(...)->access('admin') adds the middleware to a single route, skipping those it already
     *   has from its groups.
     *
     * Groups are added in config order whatever the order of the names, and an unknown group, a group that
     * is not a list of middleware names or a call without groups throws while the routes register.
     *
     * @throws InvalidAccessException From the macros, for an unknown or invalid group or no group at all
     *
     * @see AccessResolver::middlewareFor()
     * @see AccessRouteRegistrar
     */
    private function registerAccessMacros(): void
    {
        Router::macro('access', function (string|array ...$accesses): AccessRouteRegistrar {
            /** @var Router $this */
            return (new AccessRouteRegistrar($this))->access(...$accesses);
        });

        RouteRegistrar::macro('access', function (string|array ...$accesses): AccessRouteRegistrar {
            /** @var RouteRegistrar $this */
            return AccessRouteRegistrar::from($this)->access(...$accesses);
        });

        Route::macro('access', function (string|array ...$accesses): Route {
            /** @var Route $this */
            $middleware = app(AccessResolver::class)->middlewareFor(array_map(strval(...), Arr::flatten($accesses)));

            // Closures are middleware too, but cannot be compared as names
            $present = array_filter($this->middleware(), 'is_string');

            return $this->middleware(array_values(array_diff($middleware, $present)));
        });
    }
}
