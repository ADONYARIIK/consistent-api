<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi;

use Adonyarik\ConsistentApi\Console\Commands\RebuildCommand;
use Adonyarik\ConsistentApi\Middleware\ApiJsonMiddleware;
use Adonyarik\ConsistentApi\Middleware\DebuggerMiddleware;
use Adonyarik\ConsistentApi\Middleware\EnsureJsonMiddleware;
use Adonyarik\ConsistentApi\Middleware\EnsureMultipartMiddleware;
use Adonyarik\ConsistentApi\Providers\MacroServiceProvider;
use Adonyarik\ConsistentApi\Providers\ModuleServiceProvider;
use Adonyarik\ConsistentApi\Providers\PgEnumServiceProvider;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsistentApiProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/consistentapi.php',
            'consistentapi'
        );
        $this->mergeConfigFrom(
            __DIR__ . '/../config/pagination.php',
            'pagination'
        );

        $this->app->register(ModuleServiceProvider::class);
        $this->app->register(MacroServiceProvider::class);
        $this->app->register(PgEnumServiceProvider::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/consistentapi.php' => config_path('consistentapi.php'),
            __DIR__ . '/../config/pagination.php' => config_path('pagination.php'),
        ], 'consistent-api-config');

        $router = $this->app['router'];
        $router->aliasMiddleware('consistent.api-json', ApiJsonMiddleware::class);
        $router->aliasMiddleware('consistent.ensure-json', EnsureJsonMiddleware::class);
        $router->aliasMiddleware('consistent.ensure-multipart', EnsureMultipartMiddleware::class);
        $router->aliasMiddleware('consistent.debugger', DebuggerMiddleware::class);

        JsonResource::withoutWrapping();

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildCommand::class]);
        }
    }
}
