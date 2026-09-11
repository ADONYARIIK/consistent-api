<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi;

use Adonyarik\ConsistentApi\Console\Commands\CreateCrudCommand;
use Adonyarik\ConsistentApi\Console\Commands\RebuildCommand;
use Adonyarik\ConsistentApi\Middleware\ApiJsonMiddleware;
use Adonyarik\ConsistentApi\Middleware\DebuggerMiddleware;
use Adonyarik\ConsistentApi\Middleware\EnsureJsonMiddleware;
use Adonyarik\ConsistentApi\Middleware\EnsureMultipartMiddleware;
use Adonyarik\ConsistentApi\Providers\MacroServiceProvider;
use Adonyarik\ConsistentApi\Providers\ModuleServiceProvider;
use Adonyarik\ConsistentApi\Providers\PostgresEnumServiceProvider;
use Adonyarik\ConsistentApi\Support\FilterableValidator;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class ConsistentApiProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/consistentapi.php',
            'consistentapi'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../config/pagination.php',
            'pagination'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../config/filters.php',
            'filters'
        );

        $this->app->register(ModuleServiceProvider::class);
        $this->app->register(MacroServiceProvider::class);
        $this->app->register(PostgresEnumServiceProvider::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/consistentapi.php' => config_path('consistentapi.php'),
            __DIR__.'/../config/pagination.php' => config_path('pagination.php'),
            __DIR__.'/../config/filters.php' => config_path('filters.php'),
        ], 'consistent-api-config');

        if (config('filters.validate_on_boot', true) && $this->app->environment(['local', 'testing'])) {
            (new FilterableValidator)->validateAll();
        }

        $router = $this->app['router'];
        $router->aliasMiddleware('consistent.api-json', ApiJsonMiddleware::class);
        $router->aliasMiddleware('consistent.ensure-json', EnsureJsonMiddleware::class);
        $router->aliasMiddleware('consistent.ensure-multipart', EnsureMultipartMiddleware::class);
        $router->aliasMiddleware('consistent.debugger', DebuggerMiddleware::class);

        JsonResource::withoutWrapping();

        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildCommand::class,
                CreateCrudCommand::class,
            ]);
        }
    }
}
