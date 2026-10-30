<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Support;

use Adonyarik\ConsistentApi\Exceptions\InvalidAccessException;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Arr;

/**
 * Route registrar returned by Route::access(): middleware are added, never replaced.
 *
 * Laravel's RouteRegistrar replaces its middleware on every middleware() call, so
 * Route::access('admin')->middleware('throttle:10,1') would drop the middleware of the admin group and
 * leave the routes without protection. Here middleware() and access() append to the middleware already
 * set, skipping duplicates, so the middleware of a group survive any chain. Everything else (prefix(),
 * name(), group(), get(), …) behaves as in RouteRegistrar.
 *
 * @package Adonyarik\ConsistentApi\Support
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class AccessRouteRegistrar extends RouteRegistrar
{
    /**
     * Copies a registrar with its attributes, so Route::prefix('admin')->access('admin') keeps the prefix.
     *
     * @param  RouteRegistrar  $registrar  Registrar to copy; it is not changed
     *
     * @return self Registrar with the same router and attributes
     */
    public static function from(RouteRegistrar $registrar): self
    {
        $copy = new self($registrar->router);
        $copy->attributes = $registrar->attributes;

        return $copy;
    }

    /**
     * Adds the middleware of access groups from consistentapi.accesses.
     *
     * Takes names or lists of names: access('verified', 'admin') and access(['verified', 'admin']) are the
     * same. Groups are added in config order, and middleware already set are skipped.
     *
     * @param  string|list<string>  ...$accesses  Access group names
     *
     * @return $this
     *
     * @throws InvalidAccessException When no group is given, a group is not defined, or a group is not a list of middleware names
     *
     * @see AccessResolver::middlewareFor()
     */
    public function access(string|array ...$accesses): self
    {
        $names = array_map(strval(...), Arr::flatten($accesses));

        return $this->attribute('middleware', app(AccessResolver::class)->middlewareFor($names));
    }

    /**
     * Sets an attribute; middleware are appended to the ones already set instead of replacing them.
     *
     * middleware() reaches this method through RouteRegistrar::__call(), so it appends too.
     *
     * @param  string  $key  Attribute name
     * @param  mixed  $value  Attribute value
     *
     * @return $this
     */
    public function attribute($key, $value)
    {
        if ($key === 'middleware') {
            $current = (array) ($this->attributes['middleware'] ?? []);
            $added = array_map(strval(...), array_filter(Arr::wrap($value)));

            $value = [...$current, ...array_values(array_unique(array_diff($added, $current)))];
        }

        return parent::attribute($key, $value);
    }
}
