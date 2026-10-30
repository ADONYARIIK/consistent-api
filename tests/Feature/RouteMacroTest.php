<?php

declare(strict_types=1);

namespace Tests\Feature;

use Adonyarik\ConsistentApi\Exceptions\InvalidAccessException;
use Adonyarik\ConsistentApi\Providers\MacroServiceProvider;
use Adonyarik\ConsistentApi\Support\AccessResolver;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RouteMacroTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('consistentapi.accesses', [
            'guest' => [],
            'verified' => ['auth:sanctum', 'verified'],
            'admin' => ['auth:sanctum', 'verified', 'can:admin'],
        ]);
    }

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
        $this->assertTrue(Router::hasMacro('access'));

        // Route files run while the providers boot: the macro must come from register()
        Router::flushMacros();
        (new MacroServiceProvider($this->app))->register();

        $this->assertTrue(Router::hasMacro('development'));
        $this->assertTrue(Router::hasMacro('access'));
    }

    public function test_access_puts_the_middleware_of_the_groups_on_routes(): void
    {
        $grouped = null;

        Route::access('admin')->group(function () use (&$grouped) {
            $grouped = Route::get('grouped', fn () => 'ok');
        });

        $this->assertSame(['auth:sanctum', 'verified', 'can:admin'], $grouped?->middleware());

        // Groups follow the config order, middleware shared by groups appear once
        $this->assertSame(['auth:sanctum', 'verified', 'can:admin'], Route::access('admin', 'verified')->get('both', fn () => 'ok')->middleware());
        $this->assertSame(['auth:sanctum', 'verified', 'can:admin'], Route::access(['admin', 'verified'])->get('list', fn () => 'ok')->middleware());

        $this->assertSame([], Route::access('guest')->get('open', fn () => 'ok')->middleware());
        $this->assertSame(['auth:sanctum', 'verified'], Route::get('single', fn () => 'ok')->access('verified')->middleware());
    }

    public function test_access_middleware_survive_other_middleware_calls(): void
    {
        // Laravel's registrar replaces its middleware on every middleware() call; the access registrar adds
        $after = Route::access('verified')->middleware('throttle:60,1')->get('after', fn () => 'ok');
        $before = Route::middleware(['throttle:60,1', 'auth:sanctum'])->access('verified')->get('before', fn () => 'ok');
        $chained = Route::access('verified')->access('admin')->middleware('verified', 'throttle:60,1')->get('chained', fn () => 'ok');

        $this->assertSame(['auth:sanctum', 'verified', 'throttle:60,1'], $after->middleware());
        $this->assertSame(['throttle:60,1', 'auth:sanctum', 'verified'], $before->middleware());
        $this->assertSame(['auth:sanctum', 'verified', 'can:admin', 'throttle:60,1'], $chained->middleware());

        // Attributes set before access() are kept
        $prefixed = Route::prefix('admin')->name('admin.')->access('admin')->get('posts', fn () => 'ok')->name('posts');

        $this->assertSame('admin/posts', $prefixed->uri());
        $this->assertSame('admin.posts', $prefixed->getName());
        $this->assertSame(['auth:sanctum', 'verified', 'can:admin'], $prefixed->middleware());
    }

    public function test_access_on_a_route_adds_only_what_its_groups_lack(): void
    {
        $route = null;

        Route::access('verified')->group(function () use (&$route) {
            $route = Route::delete('posts/{post}', fn () => 'ok')->access('admin');
        });

        $this->assertInstanceOf(RoutingRoute::class, $route);
        $this->assertSame(['auth:sanctum', 'verified', 'can:admin'], $route->middleware());
    }

    public function test_routes_with_access_have_that_access(): void
    {
        $this->stubMiddlewareAliases('auth', 'verified', 'can');

        Route::access('admin')->get('echo', fn () => app(AccessResolver::class)->current());

        $this->getJson('/echo')->assertOk()->assertExactJson(['guest', 'verified', 'admin']);
    }

    /**
     * @return array<string, array{\Closure(): mixed, string}>
     */
    public static function invalidAccesses(): array
    {
        return [
            'typo' => [fn () => Route::access('amdin'), 'Route::access() names access "amdin"'],
            'typo on a registrar' => [fn () => Route::prefix('x')->access('verified', 'amdin'), 'names access "amdin"'],
            'typo on a route' => [fn () => Route::get('x', fn () => 'ok')->access('amdin'), 'names access "amdin"'],
            'not a list' => [fn () => Route::access('broken'), 'Access group "broken" in consistentapi.accesses must be an array of middleware names.'],
            'empty name' => [fn () => Route::access('blank'), 'Access group "blank" in consistentapi.accesses must be an array of middleware names.'],
            'no group' => [fn () => Route::access(), 'Route::access() needs at least one access group'],
        ];
    }

    /**
     * @param  \Closure(): mixed  $register
     */
    #[DataProvider('invalidAccesses')]
    public function test_access_rejects_unknown_and_invalid_groups(\Closure $register, string $message): void
    {
        config([
            'consistentapi.accesses.broken' => 'auth:sanctum',
            'consistentapi.accesses.blank' => ['auth:sanctum', ''],
        ]);

        $this->expectException(InvalidAccessException::class);
        $this->expectExceptionMessageToContain($message);

        $register();
    }
}
