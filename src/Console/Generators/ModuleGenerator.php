<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Generators;

use Adonyarik\ConsistentApi\Console\Support\PhpImports;
use RuntimeException;

/**
 * Renders module files from a blueprint; writing them is up to the caller.
 *
 * Fills the stubs/consistent/ whole-file templates and the stubs/module/ fragments with the blueprint's
 * names, imports, routes and resources. The output passes php -l and Pint with the package pint.json, so
 * generated modules match the application's code style. CreateModuleCommand writes the files.
 *
 * @package Adonyarik\ConsistentApi\Console\Generators
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class ModuleGenerator
{
    /**
     * Directory with the consistent/ whole-file templates and the module/ fragments.
     */
    private readonly string $stubPath;

    /**
     * Sets the stub directory.
     *
     * Defaults to the package's stubs/ directory; tests can pass another one.
     *
     * @param  string|null  $stubPath  Holds consistent/ (whole-file templates) and module/ (fragments)
     */
    public function __construct(?string $stubPath = null)
    {
        $this->stubPath = $stubPath ?? dirname(__DIR__, 3).'/stubs';
    }

    /**
     * Renders every file of the module.
     *
     * Model, controller, one form request per request prefix, the selected resources and Routes.php, keyed
     * by absolute path under the blueprint's module directory. Nothing is written to disk.
     *
     * @param  ModuleBlueprint  $blueprint  Validated module options
     *
     * @return array<string, string> path => contents
     *
     * @throws RuntimeException When a stub file is missing
     *
     * @see ModuleGenerator::render()
     */
    public function generate(ModuleBlueprint $blueprint): array
    {
        $path = $blueprint->path;
        $files = [
            $path.'/Models/'.$blueprint->model.'.php' => $this->model($blueprint),
            $path.'/Controllers/'.$blueprint->model.'Controller.php' => $this->controller($blueprint),
        ];

        foreach ($blueprint->requestPrefixes() as $prefix) {
            $files[$path.'/Requests/'.$prefix.$blueprint->model.'Request.php'] = $this->render(
                'consistent/request.'.strtolower($prefix).'.stub',
                $this->baseReplacements($blueprint),
            );
        }

        foreach ($blueprint->resources as $kind) {
            $files[$path.'/Resources/'.$blueprint->resourceClass($kind).'.php'] = $this->resource($blueprint, $kind);
        }

        $files[$path.'/Routes.php'] = $this->routes($blueprint);

        return $files;
    }

    /**
     * Renders the model class.
     *
     * A ConsistentModel declared with Eloquent attributes only: #[Table] with the module's table and empty
     * #[Fillable], #[Hidden], #[Filterable] and #[Sortable]. With --uuid it adds #[RouteKey] for the UUID
     * column, HasUuids and the uniqueIds() method that fills that column on insert.
     *
     * @param  ModuleBlueprint  $blueprint  Validated module options
     *
     * @return string PHP source of the model
     *
     * @throws RuntimeException When a stub file is missing
     */
    private function model(ModuleBlueprint $blueprint): string
    {
        $imports = [
            'Adonyarik\\ConsistentApi\\Attributes\\Filterable',
            'Adonyarik\\ConsistentApi\\Attributes\\Sortable',
            'Adonyarik\\ConsistentApi\\Models\\ConsistentModel',
            'Illuminate\\Database\\Eloquent\\Attributes\\Fillable',
            'Illuminate\\Database\\Eloquent\\Attributes\\Hidden',
            'Illuminate\\Database\\Eloquent\\Attributes\\Table',
        ];
        $routeKey = '';
        // Pint writes an empty class body on the declaration line
        $body = ' {}';

        if ($blueprint->uuidColumn !== null) {
            $imports[] = 'Illuminate\\Database\\Eloquent\\Attributes\\RouteKey';
            $imports[] = 'Illuminate\\Database\\Eloquent\\Concerns\\HasUuids';
            $routeKey = sprintf("#[RouteKey('%s')]", $blueprint->uuidColumn);
            $body = "\n".rtrim($this->render('module/model.uuid.stub', ['{{ uuidColumn }}' => $blueprint->uuidColumn]));
        }

        return $this->render('consistent/model.stub', $this->baseReplacements($blueprint) + [
            '{{ imports }}' => $this->imports($imports),
            '{{ routeKey }}' => $routeKey,
            '{{ body }}' => $body,
        ]);
    }

    /**
     * Renders one resource class.
     *
     * The lightest generated resource extends JsonResource and returns the id (the UUID column with
     * --uuid); the others extend the nearest lighter one and add their fields to parent::toArray().
     *
     * @param  ModuleBlueprint  $blueprint  Validated module options
     * @param  ResourceKind  $kind  Resource to render
     *
     * @return string PHP source of the resource
     *
     * @throws RuntimeException When a stub file is missing
     *
     * @see ModuleBlueprint::parentResource()
     */
    private function resource(ModuleBlueprint $blueprint, ResourceKind $kind): string
    {
        $parent = $blueprint->parentResource($kind);
        $replacements = $this->baseReplacements($blueprint) + ['{{ class }}' => $blueprint->resourceClass($kind)];

        if ($parent === null) {
            return $this->render('consistent/resource.stub', $replacements + ['{{ key }}' => $blueprint->uuidColumn ?? 'id']);
        }

        return $this->render('consistent/resource.child.stub', $replacements + ['{{ parent }}' => $blueprint->resourceClass($parent)]);
    }

    /**
     * Renders the controller class.
     *
     * A ConsistentController with $resourceClass set to the default resource, $resourceClasses with the
     * index override when there is one, and one method per generated action. Imports only what the
     * methods use: JsonResponse for index, store and destroy, JsonResource for show and update.
     *
     * @param  ModuleBlueprint  $blueprint  Validated module options
     *
     * @return string PHP source of the controller
     *
     * @throws RuntimeException When a stub file is missing
     *
     * @see ModuleBlueprint::defaultResource()
     * @see ModuleBlueprint::resourceOverrides()
     */
    private function controller(ModuleBlueprint $blueprint): string
    {
        $namespace = $blueprint->namespace;
        $imports = [
            'Adonyarik\\ConsistentApi\\Controllers\\ConsistentController',
            $namespace.'\\Models\\'.$blueprint->model,
        ];

        foreach ($blueprint->requestPrefixes() as $prefix) {
            $imports[] = $namespace.'\\Requests\\'.$prefix.$blueprint->model.'Request';
        }

        $defaultResource = $blueprint->resourceClass($blueprint->defaultResource());
        $imports[] = $namespace.'\\Resources\\'.$defaultResource;

        $overrides = '[]';

        if ($blueprint->resourceOverrides() !== []) {
            $lines = [];

            foreach ($blueprint->resourceOverrides() as $method => $kind) {
                $imports[] = $namespace.'\\Resources\\'.$blueprint->resourceClass($kind);
                $lines[] = sprintf("        '%s' => %s::class,", $method, $blueprint->resourceClass($kind));
            }

            $overrides = "[\n".implode("\n", $lines)."\n    ]";
        }

        $methods = [];
        $returnsResponse = false;
        $returnsResource = false;

        foreach ($blueprint->routes as $route) {
            $action = $route['action'];
            $methods[] = rtrim($this->render('module/controller.'.$action->value.'.stub', $this->baseReplacements($blueprint)));

            if (in_array($action, [RouteAction::Show, RouteAction::Update], true)) {
                $returnsResource = true;
            } else {
                $returnsResponse = true;
            }
        }

        if ($returnsResponse) {
            $imports[] = 'Illuminate\\Http\\JsonResponse';
        }

        if ($returnsResource) {
            $imports[] = 'Illuminate\\Http\\Resources\\Json\\JsonResource';
        }

        return $this->render('consistent/controller.stub', $this->baseReplacements($blueprint) + [
            '{{ imports }}' => $this->imports($imports),
            '{{ resourceClass }}' => $defaultResource,
            '{{ resourceClasses }}' => $overrides,
            '{{ methods }}' => "\n".implode("\n\n", $methods),
        ]);
    }

    /**
     * Renders Routes.php.
     *
     * Routes without access groups go first, the rest are grouped by identical access groups in order of
     * first appearance. Each group is a Route::access()->group() block naming the groups, so the routes get
     * the middleware the groups have in consistentapi.accesses when they register, not a copy made now.
     *
     * @param  ModuleBlueprint  $blueprint  Validated module options
     *
     * @return string PHP source of the route file
     *
     * @throws RuntimeException When a stub file is missing
     *
     * @see ModuleGenerator::routeLine()
     */
    private function routes(ModuleBlueprint $blueprint): string
    {
        $topLevel = [];
        $groups = [];

        foreach ($blueprint->routes as $route) {
            $line = $this->routeLine($blueprint, $route['action'], $route['methods']);

            if ($route['accesses'] === []) {
                $topLevel[] = $line;

                continue;
            }

            $key = implode("\0", $route['accesses']);
            $groups[$key] ??= ['accesses' => $route['accesses'], 'lines' => []];
            $groups[$key]['lines'][] = $line;
        }

        $blocks = $topLevel === [] ? [] : [implode("\n", $topLevel)];

        foreach ($groups as $group) {
            $blocks[] = sprintf(
                "Route::access(%s)->group(function () {\n    %s\n});",
                implode(', ', array_map($this->phpString(...), $group['accesses'])),
                implode("\n    ", $group['lines']),
            );
        }

        return $this->render('consistent/routes.stub', [
            '{{ imports }}' => $this->imports([
                $blueprint->namespace.'\\Controllers\\'.$blueprint->model.'Controller',
                'Illuminate\\Support\\Facades\\Route',
            ]),
            '{{ routes }}' => implode("\n\n", $blocks),
        ]);
    }

    /**
     * Renders the route statement of one action.
     *
     * Route::{method}() for one HTTP method, Route::match() for put and patch together. Routes with the
     * model parameter are constrained with whereNumber() or, with --uuid, whereUuid(); every route is
     * named {uri}.{action}.
     *
     * @param  ModuleBlueprint  $blueprint  Validated module options
     * @param  RouteAction  $action  Action of the route
     * @param  list<string>  $methods  Selected HTTP methods of the action
     *
     * @return string One route statement
     */
    private function routeLine(ModuleBlueprint $blueprint, RouteAction $action, array $methods): string
    {
        $uri = $blueprint->uri.($action->hasParameter() ? '/{'.$blueprint->parameter.'}' : '');
        $target = sprintf("[%sController::class, '%s']", $blueprint->model, $action->value);

        $line = count($methods) > 1
            ? sprintf("Route::match(%s, '%s', %s)", $this->phpList($methods), $uri, $target)
            : sprintf("Route::%s('%s', %s)", $methods[0], $uri, $target);

        if ($action->hasParameter()) {
            $line .= sprintf(
                "->%s('%s')",
                $blueprint->uuidColumn !== null ? 'whereUuid' : 'whereNumber',
                $blueprint->parameter,
            );
        }

        return $line.sprintf("->name('%s.%s');", $blueprint->uri, $action->value);
    }

    /**
     * Placeholders shared by all stubs.
     *
     * {{ namespace }}, {{ model }}, {{ table }}, {{ param }} (the route parameter) and {{ variable }} (the
     * controller variable); each renderer adds its own on top.
     *
     * @param  ModuleBlueprint  $blueprint  Validated module options
     *
     * @return array<string, string> Placeholder => value
     */
    private function baseReplacements(ModuleBlueprint $blueprint): array
    {
        return [
            '{{ namespace }}' => $blueprint->namespace,
            '{{ model }}' => $blueprint->model,
            '{{ table }}' => $blueprint->table,
            '{{ param }}' => $blueprint->parameter,
            '{{ variable }}' => $blueprint->variable(),
        ];
    }

    /**
     * use statements sorted the way Pint's ordered_imports expects.
     *
     * Duplicates are removed and names compared case-insensitively with namespace separators as spaces,
     * so the generated files pass Pint without changes.
     *
     * @param  list<string>  $classes  Fully qualified class names
     *
     * @return string One use statement per line
     *
     * @see PhpImports::statements()
     */
    private function imports(array $classes): string
    {
        return implode("\n", PhpImports::statements($classes));
    }

    /**
     * Renders a module Routes.php from imports and route statements.
     *
     * consistent:rebuild uses it for route files it creates, so they look like generated ones.
     *
     * @param  array<int|string, string>  $imports  Fully qualified class names; a string key is the alias when it differs from the short name
     * @param  string  $routes  Route statements
     *
     * @return string PHP source of the route file
     *
     * @throws RuntimeException When the stub file is missing
     */
    public function routesFile(array $imports, string $routes): string
    {
        return $this->render('consistent/routes.stub', [
            '{{ imports }}' => implode("\n", PhpImports::statements($imports)),
            '{{ routes }}' => $routes,
        ]);
    }

    /**
     * Renders strings as a short PHP array literal.
     *
     * Each item is single-quoted with quotes and backslashes escaped: ['put', 'patch'].
     *
     * @param  list<string>  $items  Values of the array
     *
     * @return string PHP array literal
     */
    private function phpList(array $items): string
    {
        return '['.implode(', ', array_map($this->phpString(...), $items)).']';
    }

    /**
     * PHP single-quoted string literal of a value.
     *
     * @param  string  $value  Value to quote
     *
     * @return string The literal, with ' and \ escaped
     */
    private function phpString(string $value): string
    {
        return "'".addcslashes($value, "'\\")."'";
    }

    /**
     * Fills a stub with the replacements.
     *
     * A placeholder that renders empty takes its whole line with it, so optional parts such as the
     * #[RouteKey] line of a model without --uuid leave no blank lines behind. Stubs are read with LF line
     * endings whatever the checkout has.
     *
     * @param  string  $stub  Stub path relative to the stub directory, such as consistent/model.stub
     * @param  array<string, string>  $replacements  Placeholder => value
     *
     * @return string The rendered contents
     *
     * @throws RuntimeException When the stub file is missing
     */
    private function render(string $stub, array $replacements): string
    {
        $path = $this->stubPath.'/'.$stub;

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Stub not found: %s', $path));
        }

        // A checkout with CRLF endings (git autocrlf on Windows) renders the same as one with LF
        $contents = str_replace("\r\n", "\n", (string) file_get_contents($path));

        foreach ($replacements as $placeholder => $value) {
            if ($value === '') {
                $contents = str_replace($placeholder."\n", '', $contents);
            }

            $contents = str_replace($placeholder, $value, $contents);
        }

        return $contents;
    }
}
