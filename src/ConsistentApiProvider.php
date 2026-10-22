<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi;

use Adonyarik\ConsistentApi\Console\Commands\CreateModuleCommand;
use Adonyarik\ConsistentApi\Console\Commands\RebuildCommand;
use Adonyarik\ConsistentApi\Exceptions\InvalidAccessException;
use Adonyarik\ConsistentApi\Exceptions\InvalidFilterableOperatorException;
use Adonyarik\ConsistentApi\Exceptions\InvalidRelationFilterException;
use Adonyarik\ConsistentApi\Exceptions\InvalidRelationSortException;
use Adonyarik\ConsistentApi\Middleware\ApiJsonMiddleware;
use Adonyarik\ConsistentApi\Middleware\EnsureJsonMiddleware;
use Adonyarik\ConsistentApi\Middleware\EnsureMultipartMiddleware;
use Adonyarik\ConsistentApi\Providers\MacroServiceProvider;
use Adonyarik\ConsistentApi\Providers\ModuleServiceProvider;
use Adonyarik\ConsistentApi\Support\AccessResolver;
use Adonyarik\ConsistentApi\Support\FilterableSortableValidator;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Service provider of the package, registered through Laravel package discovery.
 *
 * Wires the package into the application: the config file, the access resolver, the module routes, the
 * macros, the middleware aliases, the global ApiJsonMiddleware and the Artisan commands. It also validates
 * the #[Filterable] / #[Sortable] declarations of the application's models while booting in local and
 * testing environments.
 *
 * @package Adonyarik\ConsistentApi
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class ConsistentApiProvider extends ServiceProvider
{
    /**
     * Sections of config/consistentapi.php merged key by key with a published file.
     *
     * A published file that predates a new key of these sections still gets the package default for it.
     * Lists (operators, per_page, middlewares) and accesses are taken from the published file as a whole.
     */
    private const MERGED_SECTIONS = ['pagination', 'filters'];

    /**
     * Merges the package config and registers the services and nested providers.
     *
     * Every setting lives under the consistentapi key; a published config/consistentapi.php overrides
     * the defaults key by key at the top level and inside the pagination and filters sections.
     * AccessResolver is bound as a scoped singleton. MacroServiceProvider and ModuleServiceProvider are
     * registered here, so the application does not list them.
     *
     * @see ModuleServiceProvider
     * @see MacroServiceProvider
     * @see AccessResolver
     */
    public function register(): void
    {
        $this->mergePackageConfig();

        $this->app->scoped(AccessResolver::class);

        $this->app->register(MacroServiceProvider::class);
        $this->app->register(ModuleServiceProvider::class);
    }

    /**
     * Publishes the config, validates the model declarations and registers aliases and commands.
     *
     * The config file is published with the consistent-api-config tag. With
     * consistentapi.filters.validate_on_boot on, in local and testing environments, every model in
     * consistentapi.filters.model_paths is validated and the first invalid declaration stops the boot with
     * an exception. Registers the consistent.api-json, consistent.ensure-json and
     * consistent.ensure-multipart middleware aliases and, in the console, consistent:create and
     * consistent:rebuild. With consistentapi.api_json_enabled on, ApiJsonMiddleware is prepended to the
     * global middleware of the HTTP kernel once the kernel is built: it runs before routing and before the
     * other global middleware, so 404, 405, 413 and maintenance-mode errors under the API prefix are JSON
     * too. With consistentapi.without_wrapping on, JsonResource::withoutWrapping() is called: it applies
     * to every resource of the application, not only to the package's.
     *
     * @throws InvalidFilterableOperatorException When a #[Filterable] operator is not enabled in config
     * @throws InvalidRelationFilterException When a RelationFilter has an unknown operator or points to a missing relation
     * @throws InvalidRelationSortException When a RelationSort points to a missing or unsupported relation
     * @throws InvalidAccessException When an access group is unknown or a column is declared twice
     *
     * @see FilterableSortableValidator::validateAll()
     * @see ConsistentApiProvider::hideModelNames()
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/consistentapi.php' => config_path('consistentapi.php'),
        ], 'consistent-api-config');

        if (config('consistentapi.filters.validate_on_boot', true) && $this->app->environment(['local', 'testing'])) {
            (new FilterableSortableValidator)->validateAll();
        }

        $router = $this->app['router'];
        $router->aliasMiddleware('consistent.api-json', ApiJsonMiddleware::class);
        $router->aliasMiddleware('consistent.ensure-json', EnsureJsonMiddleware::class);
        $router->aliasMiddleware('consistent.ensure-multipart', EnsureMultipartMiddleware::class);

        if (config('consistentapi.api_json_enabled', true)) {
            $this->callAfterResolving(Kernel::class, function (Kernel $kernel) {
                if ($kernel instanceof HttpKernel) {
                    $kernel->prependMiddleware(ApiJsonMiddleware::class);
                }
            });
        }

        if (config('consistentapi.without_wrapping', true)) {
            JsonResource::withoutWrapping();
        }

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            if ($handler instanceof Handler) {
                $this->hideModelNames($handler);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildCommand::class,
                CreateModuleCommand::class,
            ]);
        }
    }

    /**
     * Merges config/consistentapi.php of the package into the application config.
     *
     * Top-level keys missing from the application config get the package defaults, and so do the keys of
     * the MERGED_SECTIONS. Nothing happens when the configuration is cached: the cache already holds the
     * merged values.
     */
    private function mergePackageConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $defaults = require __DIR__.'/../config/consistentapi.php';
        $config = $this->app->make('config');
        $current = (array) $config->get('consistentapi', []);
        $merged = array_merge($defaults, $current);

        foreach (self::MERGED_SECTIONS as $section) {
            $merged[$section] = array_merge($defaults[$section], (array) ($current[$section] ?? []));
        }

        $config->set('consistentapi', $merged);
    }

    /**
     * Answers {"message": "Not found."} for missing route-bound models under the API prefix.
     *
     * Laravel's 404 for a missing model reads "No query results for model [App\...\Post] 1" even with
     * app.debug off, which tells clients the class names of the application. With
     * consistentapi.hide_model_not_found on and app.debug off, such a 404 under the API prefix gets the
     * generic message instead; other 404 responses are left to the application.
     *
     * @param  Handler  $handler  Exception handler of the application
     *
     * @see ApiJsonMiddleware::isApiRequest()
     */
    private function hideModelNames(Handler $handler): void
    {
        $handler->renderable(function (NotFoundHttpException $exception, Request $request) {
            if (! config('consistentapi.hide_model_not_found', true)
                || config('app.debug')
                || ! $exception->getPrevious() instanceof ModelNotFoundException
                || ! ApiJsonMiddleware::isApiRequest($request)) {
                return null;
            }

            return new JsonResponse(['message' => 'Not found.'], JsonResponse::HTTP_NOT_FOUND);
        });
    }
}
