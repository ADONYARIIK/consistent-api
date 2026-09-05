<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Providers;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MacroServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Route::macro('development', function (callable $routes): void {
            if (App::isLocal()) {
                $routes();
            }
        });
    }
}
