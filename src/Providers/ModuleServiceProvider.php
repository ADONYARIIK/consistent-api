<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Providers;

use Adonyarik\ConsistentApi\Support\ModulesFolder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Loads the routes of the modules and defines the api rate limiter.
 *
 * Every module keeps its routes in app/{modules_folder}/{Module}/Routes.php; this provider registers them
 * with the API prefix and middleware from config/consistentapi.php, so routes/api.php does not list
 * them. ConsistentApiProvider registers it.
 *
 * It is a plain ServiceProvider on purpose: a subclass of Laravel's RouteServiceProvider would also load
 * the routes that bootstrap/app.php passes to withRouting(), so routes/web.php and routes/api.php would be
 * loaded twice.
 *
 * @package Adonyarik\ConsistentApi\Providers
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Defines the rate limiter and schedules the module routes.
     *
     * The routes are loaded once the application has booted, after every provider (and so after the
     * Route::development() macro and the application's own routes). Nothing is loaded when the routes are
     * cached: the cache already holds the module routes.
     *
     * @throws InvalidArgumentException When consistentapi.modules_folder is not a valid folder
     *
     * @see ModuleServiceProvider::configureRateLimiting()
     * @see ModuleServiceProvider::loadModuleRoutes()
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        if (! $this->app->routesAreCached()) {
            $this->app->booted(fn () => $this->loadModuleRoutes());
        }
    }

    /**
     * Loads the module route files.
     *
     * Walks the direct subfolders of app/{consistentapi.modules_folder} in name order, so routes register
     * in the same order on every machine, skips one named Middleware, and loads each {Module}/Routes.php,
     * then the folder's own Routes.php, all with the consistentapi.middlewares middleware and the
     * consistentapi.api_url_prefix prefix. Nothing happens when the folder does not exist. Name and action
     * lookups are refreshed afterwards, since route names are set after the routes are added.
     *
     * @throws InvalidArgumentException When consistentapi.modules_folder is not a valid folder
     *
     * @see ModulesFolder
     */
    protected function loadModuleRoutes(): void
    {
        $modulesPath = ModulesFolder::fromConfig(config('consistentapi.modules_folder', 'Modules'))->path($this->app->path());

        if (! is_dir($modulesPath)) {
            return;
        }

        $routeFiles = [];

        foreach (new \DirectoryIterator($modulesPath) as $file) {
            if ($file->isDot() || ! $file->isDir() || $file->getFilename() === 'Middleware') {
                continue;
            }

            if (is_file($file->getPathname().'/Routes.php')) {
                $routeFiles[$file->getFilename()] = $file->getPathname().'/Routes.php';
            }
        }

        ksort($routeFiles, SORT_STRING);

        if (is_file($modulesPath.'/Routes.php')) {
            $routeFiles[] = $modulesPath.'/Routes.php';
        }

        /** @var Router $router */
        $router = $this->app['router'];
        $prefix = config('consistentapi.api_url_prefix', '');
        $middlewares = config('consistentapi.middlewares', []);

        foreach ($routeFiles as $routeFile) {
            $router->middleware($middlewares)->prefix($prefix)->group($routeFile);
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * Defines the rate limiter named api.
     *
     * Allows config('consistentapi.request_limit') requests per minute per authenticated user, or per IP
     * for guests. The last definition of a limiter name wins, so an api limiter the application defines
     * after the package providers boot replaces this one, and vice versa. The limiter takes effect on
     * routes that run throttle:api, which the api middleware group gets with throttleApi() in
     * bootstrap/app.php.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(config('consistentapi.request_limit', 60))
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
