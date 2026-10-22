<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Generators;

/**
 * Controller actions consistent:create can generate.
 *
 * Each case is a controller method and its route: index and show for get, store for post, update for put
 * and patch, destroy for delete. The order of the cases is the order of the methods in the controller and
 * of the routes in Routes.php.
 *
 * @package Adonyarik\ConsistentApi\Console\Generators
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
enum RouteAction: string
{
    case Index = 'index';
    case Show = 'show';
    case Store = 'store';
    case Update = 'update';
    case Destroy = 'destroy';

    /**
     * HTTP methods (as accepted by --routes) served by this action.
     *
     * get adds two actions, index and show; put and patch both route to update.
     *
     * @return list<string> Lowercase HTTP methods
     */
    public function httpMethods(): array
    {
        return match ($this) {
            self::Index, self::Show => ['get'],
            self::Store => ['post'],
            self::Update => ['put', 'patch'],
            self::Destroy => ['delete'],
        };
    }

    /**
     * Tells whether the route has the {model} parameter.
     *
     * show, update and destroy work on one model bound by the route parameter, which gets a whereNumber()
     * or whereUuid() constraint.
     *
     * @return bool True for show, update and destroy
     */
    public function hasParameter(): bool
    {
        return in_array($this, [self::Show, self::Update, self::Destroy], true);
    }

    /**
     * Prefix of the form request the action takes (Search → SearchPostRequest), null when it takes none.
     *
     * The prefix also selects the stub: request.search.stub, request.store.stub or request.update.stub.
     *
     * @return string|null Search, Store or Update; null for show and destroy
     */
    public function requestPrefix(): ?string
    {
        return match ($this) {
            self::Index => 'Search',
            self::Store => 'Store',
            self::Update => 'Update',
            self::Show, self::Destroy => null,
        };
    }
}
