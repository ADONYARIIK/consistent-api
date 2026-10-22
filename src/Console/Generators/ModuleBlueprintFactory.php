<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Generators;

use Adonyarik\ConsistentApi\Exceptions\InvalidModuleOptionsException;
use Adonyarik\ConsistentApi\Support\ModulesFolder;
use Illuminate\Support\Str;

/**
 * Parses and validates consistent:create options.
 *
 * Turns the raw command arguments into a ModuleBlueprint: normalizes option values, resolves access
 * groups to route middleware and derives the module names and paths. It collects every error before
 * failing, so the user sees all problems at once. Config comes in through the constructor, so the factory
 * works without a booted application.
 *
 * @package Adonyarik\ConsistentApi\Console\Generators
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class ModuleBlueprintFactory
{
    /**
     * HTTP methods --routes accepts besides all, in the order the routes are generated.
     */
    private const ROUTE_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * Words PHP does not accept as a class name, compared case-insensitively.
     *
     * The keywords Laravel's own generators reject, plus the reserved type names.
     */
    private const RESERVED_NAMES = [
        '__halt_compiler', 'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class',
        'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty',
        'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit',
        'extends', 'false', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
        'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset', 'list',
        'match', 'namespace', 'new', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly',
        'require', 'require_once', 'return', 'self', 'static', 'switch', 'throw', 'trait', 'true', 'try',
        'unset', 'use', 'var', 'while', 'xor', 'yield', 'int', 'float', 'bool', 'string', 'null', 'void',
        'iterable', 'object', 'mixed', 'never',
    ];

    /**
     * Short names the generated files import, per file, next to the class the file declares.
     *
     * A generated class with one of these names would clash with the import: two classes of the same name
     * in one file are a fatal error.
     */
    private const MODEL_IMPORTS = ['ConsistentModel', 'Fillable', 'Hidden', 'Table', 'Filterable', 'Sortable', 'HasUuids', 'RouteKey'];

    private const CONTROLLER_IMPORTS = ['ConsistentController', 'JsonResponse', 'JsonResource'];

    private const SEARCH_REQUEST_IMPORTS = ['BaseSearchRequest'];

    private const RESOURCE_IMPORTS = ['Request', 'JsonResource'];

    /**
     * Controller variable names the generated actions already use.
     */
    private const RESERVED_VARIABLES = ['this', 'request'];

    /**
     * Takes the application paths and config the options are checked against.
     *
     * CreateModuleCommand passes app_path() and the config values; tests pass their own.
     *
     * @param  string  $appPath  Absolute path of the app/ directory
     * @param  string  $modulesFolder  consistentapi.modules_folder, relative to app/
     * @param  array<mixed>  $accessGroups  config('consistentapi.accesses')
     * @param  bool  $uuidEnabled  consistentapi.uuid: whether --uuid is allowed
     * @param  string  $uuidColumn  consistentapi.uuid_column: the UUID route key column
     * @param  string  $rootNamespace  Application namespace, App unless composer.json maps app/ to another one
     */
    public function __construct(
        private readonly string $appPath,
        private readonly string $modulesFolder,
        private readonly array $accessGroups,
        private readonly bool $uuidEnabled,
        private readonly string $uuidColumn,
        private readonly string $rootNamespace = 'App',
    ) {}

    /**
     * Validates the options and builds the blueprint.
     *
     * The model name is converted to StudlyCase and must be a class name PHP accepts: not a reserved word,
     * and not a name that would clash with a class the generated files import (Fillable, JsonResource,
     * ...). Its module must not be Middleware, a folder ModuleServiceProvider skips. The modules folder
     * must be valid. Routes, resources and accesses are parsed; put and patch must end up with the same
     * middleware, since both route to update(); --uuid needs consistentapi.uuid and a valid uuid_column.
     * The module is the plural of the model; its table is snake_case, its URI and route names kebab-case,
     * its route parameter snake_case and the controller variable camelCase, with acronyms kept as one word
     * (APIKey: api_keys, api-keys, api_key, $apiKey).
     *
     * @param  string  $model  Model name argument
     * @param  array<int, mixed>  $routes  Raw --routes values
     * @param  array<int, mixed>  $accesses  Raw --accesses values
     * @param  array<int, mixed>  $resources  Raw --resources values
     * @param  bool  $uuid  Whether --uuid was given
     *
     * @return ModuleBlueprint The validated module options
     *
     * @throws InvalidModuleOptionsException With every option error found
     *
     * @see ModuleBlueprintFactory::parseRoutes()
     * @see ModuleBlueprintFactory::parseResources()
     * @see ModuleBlueprintFactory::parseAccesses()
     * @see ModuleBlueprintFactory::nameErrors()
     * @see ModuleBlueprint
     */
    public function make(string $model, array $routes, array $accesses, array $resources, bool $uuid): ModuleBlueprint
    {
        $errors = [];

        $model = Str::studly(trim($model));

        $folderError = ModulesFolder::errorFor($this->modulesFolder);

        if ($folderError !== null) {
            $errors[] = $folderError;
        }

        $methods = $this->parseRoutes($routes, $errors);
        $resourceKinds = $this->parseResources($resources, $errors);
        $methodAccesses = $this->parseAccesses($accesses, $methods, $errors);

        array_push($errors, ...$this->nameErrors($model));

        $methodMiddleware = [];

        foreach ($methodAccesses as $method => $names) {
            $methodMiddleware[$method] = $this->middlewareOf($names);
        }

        if (isset($methodMiddleware['put'], $methodMiddleware['patch'])
            && $methodMiddleware['put'] !== $methodMiddleware['patch']) {
            $errors[] = sprintf(
                'put and patch both route to update() and must have the same middleware, got [%s] for put and [%s] for patch.',
                implode(', ', $methodMiddleware['put']),
                implode(', ', $methodMiddleware['patch']),
            );
        }

        if ($uuid && ! $this->uuidEnabled) {
            $errors[] = '--uuid requires consistentapi.uuid to be true.';
        } elseif ($uuid && ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $this->uuidColumn)) {
            $errors[] = '--uuid requires consistentapi.uuid_column to be a column name.';
        }

        if ($errors !== []) {
            throw new InvalidModuleOptionsException(array_values(array_unique($errors)));
        }

        $routeMap = [];

        foreach (RouteAction::cases() as $action) {
            $actionMethods = array_values(array_intersect($action->httpMethods(), $methods));

            if ($actionMethods === []) {
                continue;
            }

            $routeMap[$action->value] = [
                'action' => $action,
                'methods' => $actionMethods,
                'accesses' => $methodAccesses[$actionMethods[0]],
                'middleware' => $methodMiddleware[$actionMethods[0]],
            ];
        }

        $module = Str::pluralStudly($model);
        $folder = ModulesFolder::fromConfig($this->modulesFolder);

        return new ModuleBlueprint(
            model: $model,
            module: $module,
            namespace: $folder->namespace($this->rootNamespace).'\\'.$module,
            path: $folder->path($this->appPath).'/'.$module,
            table: implode('_', $this->words($module)),
            uri: implode('-', $this->words($module)),
            parameter: implode('_', $this->words($model)),
            routes: $routeMap,
            resources: $resourceKinds,
            uuidColumn: $uuid ? $this->uuidColumn : null,
        );
    }

    /**
     * Problems with the model name, empty when it can be generated.
     *
     * The name must be a StudlyCase identifier, not a reserved word, must not clash with what the generated
     * files import (the model with Fillable, the controller with JsonResource, a resource with Request,
     * ...), must not make the controller variable $this or $request, and its module must not be Middleware.
     *
     * @param  string  $model  Model name in StudlyCase
     *
     * @return list<string> Error messages
     */
    private function nameErrors(string $model): array
    {
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/D', $model)) {
            return ['Model name must be a valid StudlyCase class name (e.g. Post).'];
        }

        if (in_array(strtolower($model), self::RESERVED_NAMES, true)) {
            return [sprintf('Model name "%s" is a reserved word in PHP and cannot be a class name.', $model)];
        }

        $files = [
            'model' => [[$model], self::MODEL_IMPORTS],
            'controller' => [[$model, $model.'Controller'], self::CONTROLLER_IMPORTS],
            'search request' => [[$model], self::SEARCH_REQUEST_IMPORTS],
            'resource' => [array_map(fn (ResourceKind $kind) => $model.$kind->classSuffix(), ResourceKind::cases()), self::RESOURCE_IMPORTS],
        ];

        $errors = [];

        foreach ($files as $file => [$classes, $imports]) {
            foreach ($classes as $class) {
                foreach ($imports as $import) {
                    if (strcasecmp($class, $import) === 0) {
                        $errors[] = sprintf('Model name "%s" makes the class %s, which clashes with %s imported by the generated %s; choose another name.', $model, $class, $import, $file);
                    }
                }
            }
        }

        $variable = lcfirst(str_replace('_', '', ucwords(implode('_', $this->words($model)), '_')));

        if (in_array($variable, self::RESERVED_VARIABLES, true)) {
            $errors[] = sprintf('Model name "%s" makes the controller variable $%s, which PHP or the generated actions already use; choose another name.', $model, $variable);
        }

        if (Str::pluralStudly($model) === 'Middleware') {
            $errors[] = sprintf('Model name "%s" makes the module Middleware, a folder whose routes are never loaded; choose another name.', $model);
        }

        return $errors;
    }

    /**
     * Words of a StudlyCase name, lowercased.
     *
     * Acronyms stay one word, including a plural s: APIKeys gives api, keys and APIs gives apis, where
     * Str::snake() would split every capital letter (a_p_i_keys).
     *
     * @param  string  $studly  StudlyCase name
     *
     * @return list<string> Lowercase words
     */
    private function words(string $studly): array
    {
        preg_match_all('/[A-Z]+s(?=[A-Z]|$)|[A-Z]+(?=[A-Z][a-z])|[A-Z]*[a-z0-9]+|[A-Z]+/', $studly, $matches);

        return array_map('strtolower', $matches[0]);
    }

    /**
     * Parses --routes into HTTP methods.
     *
     * No option means all methods; all next to other values means all too. Unknown values are reported,
     * and a list without any value (--routes=,) is an error.
     *
     * @param  array<int, mixed>  $raw  Raw --routes values
     * @param  list<string>  $errors  Collected errors, appended to
     *
     * @return list<string> Selected HTTP methods in ROUTE_METHODS order
     *
     * @see ModuleBlueprintFactory::normalize()
     */
    private function parseRoutes(array $raw, array &$errors): array
    {
        $values = $this->normalize($raw);

        if ($raw === []) {
            return self::ROUTE_METHODS;
        }

        if ($values === []) {
            $errors[] = '--routes must list at least one of: '.implode(', ', self::ROUTE_METHODS).', all.';

            return [];
        }

        foreach ($values as $value) {
            if ($value !== 'all' && ! in_array($value, self::ROUTE_METHODS, true)) {
                $errors[] = sprintf('Unknown --routes value "%s", expected: %s, all.', $value, implode(', ', self::ROUTE_METHODS));
            }
        }

        if (in_array('all', $values, true)) {
            return self::ROUTE_METHODS;
        }

        return array_values(array_intersect(self::ROUTE_METHODS, $values));
    }

    /**
     * Parses --resources into resource kinds.
     *
     * No option means the standard resource only; all means every kind. Short and full codes are accepted,
     * unknown values are reported, next to all too. The result is in ResourceKind order whatever the
     * option order, which is the order the inheritance chain relies on.
     *
     * @param  array<int, mixed>  $raw  Raw --resources values
     * @param  list<string>  $errors  Collected errors, appended to
     *
     * @return list<ResourceKind> Selected kinds in ResourceKind order
     *
     * @see ResourceKind::fromCode()
     */
    private function parseResources(array $raw, array &$errors): array
    {
        $values = $this->normalize($raw);

        if ($raw === []) {
            return [ResourceKind::Standard];
        }

        if ($values === []) {
            $errors[] = '--resources must list at least one of: l, s, a, all.';

            return [];
        }

        $selected = [];

        foreach ($values as $value) {
            if ($value === 'all') {
                continue;
            }

            $kind = ResourceKind::fromCode($value);

            if ($kind === null) {
                $errors[] = sprintf('Unknown --resources value "%s", expected: l|light, s|standard, a|admin, all.', $value);

                continue;
            }

            $selected[] = $kind;
        }

        if (in_array('all', $values, true)) {
            return ResourceKind::cases();
        }

        return array_values(array_filter(ResourceKind::cases(), fn (ResourceKind $kind) => in_array($kind, $selected, true)));
    }

    /**
     * Parses --accesses into access groups per HTTP method.
     *
     * Each entry is method-access and is split at the first dash, so access names may contain dashes; the
     * method is case-insensitive, the access is a config key taken as is and may contain only letters,
     * digits and _ . : - (it ends up in a comment of Routes.php). An entry for a specific method
     * replaces "all" for that method; entries for one method are merged. Unknown methods, methods missing
     * from --routes, undefined groups and groups that are not lists of middleware names are reported.
     *
     * @param  array<int, mixed>  $raw  Raw --accesses values
     * @param  list<string>  $methods  Selected HTTP methods
     * @param  list<string>  $errors  Collected errors, appended to
     *
     * @return array<string, list<string>> Access names per selected method, in config order
     *
     * @see ModuleBlueprintFactory::inConfigOrder()
     */
    private function parseAccesses(array $raw, array $methods, array &$errors): array
    {
        $forAll = [];
        $forMethod = [];

        // Access names are config keys, so only the method part is lowercased
        foreach ($this->normalize($raw, lowercase: false) as $entry) {
            $dash = strpos($entry, '-');

            if ($dash === false || $dash === 0 || $dash === strlen($entry) - 1) {
                $errors[] = sprintf('Invalid --accesses value "%s", expected method-access (e.g. delete-admin).', $entry);

                continue;
            }

            $method = strtolower(substr($entry, 0, $dash));
            $access = substr($entry, $dash + 1);

            if ($method !== 'all' && ! in_array($method, self::ROUTE_METHODS, true)) {
                $errors[] = sprintf('Unknown method "%s" in --accesses value "%s", expected: %s, all.', $method, $entry, implode(', ', self::ROUTE_METHODS));

                continue;
            }

            if ($method !== 'all' && ! in_array($method, $methods, true)) {
                $errors[] = sprintf('Method "%s" in --accesses is not selected in --routes.', $method);

                continue;
            }

            if (preg_match('/^[A-Za-z0-9_.:-]+$/D', $access) !== 1) {
                $errors[] = sprintf('Access name "%s" in --accesses may contain only letters, digits and _ . : -', $access);

                continue;
            }

            if (! array_key_exists($access, $this->accessGroups)) {
                $errors[] = sprintf(
                    'Access "%s" is not defined in consistentapi.accesses (config/consistentapi.php). Publish it with `php artisan vendor:publish --tag=consistent-api-config` and declare the group.',
                    $access,
                );

                continue;
            }

            if (! $this->isMiddlewareList($this->accessGroups[$access])) {
                $errors[] = sprintf('Access group "%s" in consistentapi.accesses must be an array of middleware names.', $access);

                continue;
            }

            if ($method === 'all') {
                $forAll[] = $access;
            } else {
                $forMethod[$method][] = $access;
            }
        }

        $result = [];

        foreach ($methods as $method) {
            $result[$method] = $this->inConfigOrder($forMethod[$method] ?? $forAll);
        }

        return $result;
    }

    /**
     * Union of the groups' middleware, without duplicates.
     *
     * Keeps the order of first appearance, so verified + admin gives auth:sanctum, verified, can:admin.
     *
     * @param  list<string>  $accesses  Access group names, already validated
     *
     * @return list<string> Middleware of the route
     */
    private function middlewareOf(array $accesses): array
    {
        $middleware = [];

        foreach ($accesses as $access) {
            foreach ($this->accessGroups[$access] as $item) {
                if (! in_array($item, $middleware, true)) {
                    $middleware[] = $item;
                }
            }
        }

        return $middleware;
    }

    /**
     * Sorts access group names in the order of consistentapi.accesses.
     *
     * Makes the middleware order and the group comments in Routes.php independent of the option order.
     *
     * @param  list<string>  $names  Access group names
     *
     * @return list<string> The same names in config order
     */
    private function inConfigOrder(array $names): array
    {
        $ordered = [];

        foreach (array_keys($this->accessGroups) as $name) {
            if (in_array((string) $name, $names, true)) {
                $ordered[] = (string) $name;
            }
        }

        return $ordered;
    }

    /**
     * Tells whether an access group is a list of middleware names.
     *
     * An empty array is valid (a group without middleware, such as guest); every item must be a non-empty
     * string.
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

    /**
     * Flattens repeated and comma-separated option values, trims them and drops empty ones.
     *
     * --routes=get,post --routes=delete gives get, post, delete; duplicates are dropped and values
     * lowercased unless $lowercase is false.
     *
     * @param  array<int, mixed>  $raw  Raw option values
     * @param  bool  $lowercase  Whether to lowercase the values
     *
     * @return list<string> Distinct values in input order
     */
    private function normalize(array $raw, bool $lowercase = true): array
    {
        $values = [];

        foreach ($raw as $item) {
            foreach (explode(',', (string) $item) as $value) {
                $value = trim($value);

                if ($lowercase) {
                    $value = strtolower($value);
                }

                if ($value !== '' && ! in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }
}
