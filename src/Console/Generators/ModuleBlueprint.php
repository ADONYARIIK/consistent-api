<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Generators;

/**
 * Validated result of consistent:create options.
 *
 * Holds everything ModuleGenerator needs to render a module: names, paths, routes with their middleware
 * and the resources to generate. Only ModuleBlueprintFactory builds it, so the values are always
 * consistent with each other.
 *
 * @package Adonyarik\ConsistentApi\Console\Generators
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class ModuleBlueprint
{
    /**
     * Holds the validated values.
     *
     * Called by ModuleBlueprintFactory::make() only.
     *
     * @param  string  $model  Model class name in StudlyCase, such as Post
     * @param  string  $module  Module name, the plural of the model, such as Posts
     * @param  string  $namespace  Module namespace, such as App\Modules\Posts
     * @param  string  $path  Absolute module directory
     * @param  string  $table  Table name, the snake_case module name
     * @param  string  $uri  Route URI and route name prefix, the kebab-case module name
     * @param  string  $parameter  Route parameter, the snake_case model name
     * @param  array<string, array{action: RouteAction, methods: list<string>, accesses: list<string>, middleware: list<string>}>  $routes  Keyed by action name, in RouteAction order
     * @param  list<ResourceKind>  $resources  In ResourceKind order
     * @param  string|null  $uuidColumn  UUID route key column, null without --uuid
     */
    public function __construct(
        public readonly string $model,
        public readonly string $module,
        public readonly string $namespace,
        public readonly string $path,
        public readonly string $table,
        public readonly string $uri,
        public readonly string $parameter,
        public readonly array $routes,
        public readonly array $resources,
        public readonly ?string $uuidColumn,
    ) {}

    /**
     * Controller variable of the route-bound model: the camelCase route parameter.
     *
     * blog_post gives $blogPost; Laravel binds it to the {blog_post} parameter.
     *
     * @return string Variable name without the dollar sign
     */
    public function variable(): string
    {
        return lcfirst(str_replace('_', '', ucwords($this->parameter, '_')));
    }

    /**
     * Tells whether the module has the action.
     *
     * An action exists when --routes selected at least one of its HTTP methods.
     *
     * @param  RouteAction  $action  Action to check
     *
     * @return bool True when the action is generated
     */
    public function hasAction(RouteAction $action): bool
    {
        return isset($this->routes[$action->value]);
    }

    /**
     * Tells whether the module has the resource.
     *
     * A resource exists when --resources selected it, or it is the default standard one.
     *
     * @param  ResourceKind  $kind  Resource kind to check
     *
     * @return bool True when the resource is generated
     */
    public function hasResource(ResourceKind $kind): bool
    {
        return in_array($kind, $this->resources, true);
    }

    /**
     * Request prefixes (Search, Store, Update) of the generated actions.
     *
     * One form request is generated per prefix, in action order.
     *
     * @return list<string> Prefixes of the form requests to generate
     *
     * @see RouteAction::requestPrefix()
     */
    public function requestPrefixes(): array
    {
        $prefixes = [];

        foreach ($this->routes as $route) {
            $prefix = $route['action']->requestPrefix();

            if ($prefix !== null) {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    /**
     * Class name of a resource of this module.
     *
     * The model name with the kind's suffix: PostLightResource, PostResource, PostAdminResource.
     *
     * @param  ResourceKind  $kind  Resource kind
     *
     * @return string Short class name, in the module's Resources namespace
     *
     * @see ResourceKind::classSuffix()
     */
    public function resourceClass(ResourceKind $kind): string
    {
        return $this->model.$kind->classSuffix();
    }

    /**
     * Resource the given one extends.
     *
     * Of the generated resources, each heavier one extends the nearest lighter one
     * (light → standard → admin); the lightest extends JsonResource and returns the id.
     *
     * @param  ResourceKind  $kind  A resource kind generated for this module
     *
     * @return ResourceKind|null The resource to extend, null for JsonResource
     *
     * @see ModuleGenerator::resource()
     * @see ResourceKind
     */
    public function parentResource(ResourceKind $kind): ?ResourceKind
    {
        $index = array_search($kind, $this->resources, true);

        return is_int($index) && $index > 0 ? $this->resources[$index - 1] : null;
    }

    /**
     * Resource for $resourceClass: the first generated of Standard → Light → Admin.
     *
     * The controller's $resourceClass serves every action without its own entry in $resourceClasses.
     *
     * @return ResourceKind The default resource of the controller
     */
    public function defaultResource(): ResourceKind
    {
        foreach ([ResourceKind::Standard, ResourceKind::Light, ResourceKind::Admin] as $kind) {
            if ($this->hasResource($kind)) {
                return $kind;
            }
        }

        return ResourceKind::Standard;
    }

    /**
     * Entries for $resourceClasses: index gets the light resource when the standard one exists too.
     *
     * Without a standard resource the light one is already the default, so nothing is overridden. The
     * admin resource is never bound to a generated action.
     *
     * @return array<string, ResourceKind> Controller method => resource kind
     */
    public function resourceOverrides(): array
    {
        if ($this->hasAction(RouteAction::Index)
            && $this->hasResource(ResourceKind::Light)
            && $this->hasResource(ResourceKind::Standard)) {
            return [RouteAction::Index->value => ResourceKind::Light];
        }

        return [];
    }
}
