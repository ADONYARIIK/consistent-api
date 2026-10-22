<?php

declare(strict_types=1);

namespace Tests\Feature;

use Adonyarik\ConsistentApi\Support\AccessResolver;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccessResolverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('consistentapi.accesses', [
            'guest' => [],
            'api-user' => ['api', 'auth'],
            'odd' => ['auth', 42, null],
            'admin' => ['auth:sanctum', 'can:admin'],
        ]);
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $echo = fn () => app(AccessResolver::class)->current();

        $router->get('open', $echo)->name('open');
        $router->middleware(['api', 'auth'])->get('user', $echo)->name('user');
        $router->middleware(['auth:sanctum', 'can:admin', 'throttle:60,1'])->get('admin', $echo)->name('admin');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubMiddlewareAliases('auth', 'can');
    }

    public function test_grants_groups_whose_middleware_the_route_runs(): void
    {
        $this->getJson('/open')->assertExactJson(['guest']);
        // A group may list a middleware group (api); entries that are not names are ignored
        $this->getJson('/user')->assertExactJson(['guest', 'api-user', 'odd']);
        // Parameters must match and extra middleware does not matter
        $this->getJson('/admin')->assertExactJson(['guest', 'admin']);
    }

    public function test_outside_a_request_there_are_no_accesses(): void
    {
        $this->assertSame([], app(AccessResolver::class)->current());
        $this->assertSame([], app(AccessResolver::class)->forRoute(null));
    }

    public function test_caches_the_result_per_route(): void
    {
        $resolver = app(AccessResolver::class);
        $route = Route::getRoutes()->getByName('admin');

        $this->assertSame(['guest', 'admin'], $resolver->forRoute($route));

        config(['consistentapi.accesses' => []]);

        $this->assertSame(['guest', 'admin'], $resolver->forRoute($route));
        $this->assertSame([], (new AccessResolver($this->app['router'], $this->app['config']))->forRoute($route));
    }
}
