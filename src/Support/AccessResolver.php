<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Support;

use Adonyarik\ConsistentApi\Exceptions\InvalidAccessException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Tells which consistentapi.accesses groups a route has, and which middleware give a route its groups.
 *
 * A route has access "name" when it runs every middleware of that group, after resolving aliases and
 * groups and removing excluded middleware; extra middleware on the route does not matter, and an empty
 * group belongs to every route. CanFilter, CanSort, BaseSearchRequest and ConsistentController use it to
 * decide which restricted #[Filterable] / #[Sortable] columns a request may use; the Route::access() macro
 * uses middlewareFor() to put the middleware of groups on routes. ConsistentApiProvider registers it as a
 * scoped singleton, so results are cached per request or job.
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
     * Middleware a route needs to have the given accesses.
     *
     * The inverse of forRoute(): the union of the groups' middleware as written in consistentapi.accesses,
     * without duplicates. Groups are taken in config order whatever the order of the names, so verified and
     * admin give auth:sanctum, verified, can:admin, as consistent:create writes them. A route running the
     * result has every given access. Route::access() attaches it to routes.
     *
     * @param  list<string>  $accesses  Access group names
     *
     * @return list<string> Middleware names, aliases and parameters as in the config
     *
     * @throws InvalidAccessException When no group is given, a group is not defined, or a group is not a list of middleware names
     */
    public function middlewareFor(array $accesses): array
    {
        if ($accesses === []) {
            throw InvalidAccessException::noRouteAccess();
        }

        $groups = (array) $this->config->get('consistentapi.accesses', []);

        foreach ($accesses as $access) {
            if (! array_key_exists($access, $groups)) {
                throw InvalidAccessException::unknownRouteAccess($access);
            }

            if (! $this->isMiddlewareList($groups[$access])) {
                throw InvalidAccessException::invalidRouteAccess($access);
            }
        }

        $middleware = [];

        foreach ($groups as $name => $group) {
            if (! in_array((string) $name, $accesses, true)) {
                continue;
            }

            foreach ($group as $item) {
                if (! in_array($item, $middleware, true)) {
                    $middleware[] = $item;
                }
            }
        }

        return $middleware;
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

    /**
     * Tells whether an access group is a list of middleware names.
     *
     * An empty array is valid (a group without middleware, such as guest); every item must be a non-empty
     * string. The same check as consistent:create applies to the groups it writes into Routes.php.
     *
     * @param  mixed  $value  Value of the group in consistentapi.accesses
     *
     * @return bool True for an array of non-empty strings
     */
    private function isMiddlewareList(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                return false;
            }
        }

        return true;
    }
}
