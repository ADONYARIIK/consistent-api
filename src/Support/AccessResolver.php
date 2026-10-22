<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Tells which consistentapi.accesses groups a route has.
 *
 * A route has access "name" when it runs every middleware of that group, after resolving aliases and
 * groups and removing excluded middleware; extra middleware on the route does not matter, and an empty
 * group belongs to every route. CanFilter, CanSort, BaseSearchRequest and ConsistentController use it to
 * decide which restricted #[Filterable] / #[Sortable] columns a request may use. ConsistentApiProvider
 * registers it as a scoped singleton, so results are cached per request or job.
 *
 * @package Adonyarik\ConsistentApi\Support
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class AccessResolver
{
    /**
     * Granted accesses per route, keyed by spl_object_id() of the route.
     *
     * @var array<int, list<string>>
     */
    private array $cache = [];

    /**
     * Creates the resolver; the container injects the dependencies.
     *
     * The router supplies the route middleware and the alias and group maps, the config supplies the
     * access groups.
     *
     * @param  Router  $router  Application router
     * @param  Repository  $config  Application config with the accesses key
     */
    public function __construct(
        private readonly Router $router,
        private readonly Repository $config,
    ) {}

    /**
     * Accesses of the route handling the current request; none outside HTTP (console, queues).
     *
     * CanFilter and CanSort call it when no accesses are passed explicitly.
     *
     * @return list<string> Names of the granted groups, in config order
     *
     * @see AccessResolver::forRoute()
     */
    public function current(): array
    {
        return $this->forRoute($this->router->current());
    }

    /**
     * Accesses of the given route.
     *
     * A null route has none. The result is cached per route object for the lifetime of the resolver.
     *
     * @param  Route|null  $route  Route to check, usually $request->route()
     *
     * @return list<string> Names of the granted groups, in config order
     *
     * @see AccessResolver::resolve()
     */
    public function forRoute(?Route $route): array
    {
        if ($route === null) {
            return [];
        }

        return $this->cache[spl_object_id($route)] ??= $this->resolve($route);
    }

    /**
     * Computes the accesses of a route.
     *
     * Compares the route's gathered middleware (class names with parameters, excluded middleware removed,
     * closures dropped) with each group expanded by expand(); a group whose every middleware is present is
     * granted.
     *
     * @param  Route  $route  Route to check
     *
     * @return list<string> Names of the granted groups, in config order
     *
     * @see AccessResolver::expand()
     */
    private function resolve(Route $route): array
    {
        // Resolved class names with excluded middleware removed; closures dropped for array_diff
        $routeMiddleware = array_filter($this->router->gatherRouteMiddleware($route), 'is_string');
        $granted = [];

        foreach ((array) $this->config->get('consistentapi.accesses', []) as $name => $middleware) {
            if (array_diff($this->expand((array) $middleware), $routeMiddleware) === []) {
                $granted[] = (string) $name;
            }
        }

        return $granted;
    }

    /**
     * Resolves the middleware names of a group to what the router runs.
     *
     * Aliases become class names with their parameters (auth:sanctum becomes the Authenticate class with
     * :sanctum), middleware groups expand to their members, and non-string entries are ignored.
     *
     * @param  array<mixed>  $middleware  Middleware names of an access group
     *
     * @return list<string> Resolved middleware, comparable with Router::gatherRouteMiddleware()
     *
     * @see MiddlewareNameResolver::resolve()
     */
    private function expand(array $middleware): array
    {
        $expanded = [];

        foreach ($middleware as $name) {
            if (! is_string($name)) {
                continue;
            }

            $resolved = (array) MiddlewareNameResolver::resolve(
                $name,
                $this->router->getMiddleware(),
                $this->router->getMiddlewareGroups(),
            );

            foreach ($resolved as $item) {
                if (is_string($item)) {
                    $expanded[] = $item;
                }
            }
        }

        return $expanded;
    }
}
