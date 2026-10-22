<?php

declare(strict_types=1);

namespace Tests\Feature;

use Adonyarik\ConsistentApi\Providers\MacroServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteMacroTest extends TestCase
{
    public function test_development_routes_exist_only_in_the_local_environment(): void
    {
        Route::development(function () {
            Route::get('dev/ping', fn () => 'pong');
        });

        $this->get('/dev/ping')->assertNotFound();

        $this->app['env'] = 'local';

        Route::development(function () {
            Route::get('dev/ping', fn () => 'pong');
        });

        $this->get('/dev/ping')->assertOk()->assertSee('pong');
    }

    public function test_development_routes_registered_in_local_do_not_answer_elsewhere(): void
    {
        // As in a route cache built with APP_ENV=local and deployed to production
        $this->app['env'] = 'local';

        Route::development(function () {
            Route::get('dev/cached', fn () => 'cached');
        });

        $this->app['env'] = 'production';

        $this->get('/dev/cached')->assertNotFound();
    }

    public function test_the_macro_exists_before_the_providers_boot(): void
    {
        $this->assertTrue(Router::hasMacro('development'));

        // Route files run while the providers boot: the macro must come from register()
        Router::flushMacros();
        (new MacroServiceProvider($this->app))->register();

        $this->assertTrue(Router::hasMacro('development'));
    }
}
