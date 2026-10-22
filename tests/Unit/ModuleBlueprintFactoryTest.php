<?php

declare(strict_types=1);

namespace Tests\Unit;

use Adonyarik\ConsistentApi\Console\Generators\ModuleBlueprint;
use Adonyarik\ConsistentApi\Console\Generators\ModuleBlueprintFactory;
use Adonyarik\ConsistentApi\Console\Generators\ResourceKind;
use Adonyarik\ConsistentApi\Exceptions\InvalidModuleOptionsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModuleBlueprintFactoryTest extends TestCase
{
    private const ACCESSES = [
        'guest' => [],
        'verified' => ['auth:sanctum', 'verified'],
        'admin' => ['auth:sanctum', 'verified', 'can:admin'],
        'broken' => 'auth',
    ];

    private function factory(bool $uuid = true, string $uuidColumn = 'public_id'): ModuleBlueprintFactory
    {
        return new ModuleBlueprintFactory('/app', 'Modules', self::ACCESSES, $uuid, $uuidColumn);
    }

    /**
     * @param  list<string>  $routes
     * @param  list<string>  $accesses
     * @param  list<string>  $resources
     */
    private function make(array $routes = [], array $accesses = [], array $resources = [], bool $uuid = false): ModuleBlueprint
    {
        return $this->factory()->make('Post', $routes, $accesses, $resources, $uuid);
    }

    /**
     * @param  list<string>  $routes
     * @param  list<string>  $accesses
     * @param  list<string>  $resources
     *
     * @return list<string>
     */
    private function errors(array $routes = [], array $accesses = [], array $resources = [], bool $uuid = false, ?ModuleBlueprintFactory $factory = null): array
    {
        try {
            ($factory ?? $this->factory())->make('Post', $routes, $accesses, $resources, $uuid);
        } catch (InvalidModuleOptionsException $e) {
            return $e->errors;
        }

        $this->fail('Expected InvalidModuleOptionsException');
    }

    public function test_defaults(): void
    {
        $blueprint = $this->make();

        $this->assertSame('Posts', $blueprint->module);
        $this->assertSame('App\\Modules\\Posts', $blueprint->namespace);
        $this->assertSame('/app/Modules/Posts', $blueprint->path);
        $this->assertSame(['index', 'show', 'store', 'update', 'destroy'], array_keys($blueprint->routes));
        $this->assertSame(['put', 'patch'], $blueprint->routes['update']['methods']);
        $this->assertSame([ResourceKind::Standard], $blueprint->resources);
        $this->assertNull($blueprint->uuidColumn);

        foreach ($blueprint->routes as $route) {
            $this->assertSame([], $route['middleware']);
        }
    }

    public function test_option_values_are_split_trimmed_and_lowercased(): void
    {
        $blueprint = $this->make(routes: [' GET , post', 'Delete', ''], resources: ['L', ' s ']);

        $this->assertSame(['index', 'show', 'store', 'destroy'], array_keys($blueprint->routes));
        $this->assertSame([ResourceKind::Light, ResourceKind::Standard], $blueprint->resources);
    }

    public function test_all_wins_over_other_values(): void
    {
        $blueprint = $this->make(routes: ['get', 'all'], resources: ['a', 'all']);

        $this->assertCount(5, $blueprint->routes);
        $this->assertSame(ResourceKind::cases(), $blueprint->resources);
    }

    public function test_specific_access_replaces_all_and_entries_for_one_method_merge(): void
    {
        $blueprint = $this->make(accesses: ['all-admin,get-guest', 'delete-verified', 'delete-admin']);

        $this->assertSame(['guest'], $blueprint->routes['index']['accesses']);
        $this->assertSame([], $blueprint->routes['index']['middleware']);
        $this->assertSame(['admin'], $blueprint->routes['store']['accesses']);
        $this->assertSame(['verified', 'admin'], $blueprint->routes['destroy']['accesses']);
        $this->assertSame(['auth:sanctum', 'verified', 'can:admin'], $blueprint->routes['destroy']['middleware']);
    }

    public function test_access_name_may_contain_a_dash(): void
    {
        $factory = new ModuleBlueprintFactory('/app', 'Modules', ['super-admin' => ['can:all']], true, 'public_id');

        $blueprint = $factory->make('Post', [], ['delete-super-admin'], [], false);

        $this->assertSame(['can:all'], $blueprint->routes['destroy']['middleware']);
    }

    public function test_all_access_covers_only_selected_routes(): void
    {
        $blueprint = $this->make(routes: ['get'], accesses: ['all-verified']);

        $this->assertSame(['index', 'show'], array_keys($blueprint->routes));
        $this->assertSame(['verified'], $blueprint->routes['show']['accesses']);
    }

    public function test_resource_selection(): void
    {
        $both = $this->make(resources: ['l,s']);
        $this->assertSame(ResourceKind::Standard, $both->defaultResource());
        $this->assertSame(['index' => ResourceKind::Light], $both->resourceOverrides());

        $adminOnly = $this->make(resources: ['a']);
        $this->assertSame([ResourceKind::Admin], $adminOnly->resources);
        $this->assertSame(ResourceKind::Admin, $adminOnly->defaultResource());
        $this->assertSame([], $adminOnly->resourceOverrides());

        $withoutIndex = $this->make(routes: ['post'], resources: ['l,s']);
        $this->assertSame([], $withoutIndex->resourceOverrides());
    }

    /**
     * @return array<string, array{string, array<string, string|null>}> --resources value, parent of each generated resource
     */
    public static function resourceParents(): array
    {
        return [
            'standard' => ['s', ['standard' => null]],
            'light' => ['l', ['light' => null]],
            'admin' => ['a', ['admin' => null]],
            'light and standard' => ['l,s', ['light' => null, 'standard' => 'light']],
            'standard and admin' => ['s,a', ['standard' => null, 'admin' => 'standard']],
            'light and admin' => ['l,a', ['light' => null, 'admin' => 'light']],
            'admin and light in any order' => ['a,l', ['light' => null, 'admin' => 'light']],
            'all' => ['all', ['light' => null, 'standard' => 'light', 'admin' => 'standard']],
        ];
    }

    /**
     * @param  array<string, string|null>  $expected
     */
    #[DataProvider('resourceParents')]
    public function test_resource_parents(string $resources, array $expected): void
    {
        $blueprint = $this->make(resources: [$resources]);
        $parents = [];

        foreach ($blueprint->resources as $kind) {
            $parents[$kind->value] = $blueprint->parentResource($kind)?->value;
        }

        $this->assertSame($expected, $parents);
    }

    public function test_uuid_uses_configured_column(): void
    {
        $this->assertSame('public_id', $this->make(uuid: true)->uuidColumn);
    }

    public function test_collects_all_errors_at_once(): void
    {
        $errors = $this->errors(routes: ['get', 'head'], accesses: ['admin', 'post-admin', 'get-unknown', 'all-broken', 'fetch-admin'], resources: ['x']);

        $this->assertCount(7, $errors);
        $this->assertStringContainsString('"head"', $errors[0]);
        $this->assertStringContainsString('"x"', $errors[1]);
        $this->assertStringContainsString('expected method-access', $errors[2]);
        $this->assertStringContainsString('not selected in --routes', $errors[3]);
        $this->assertStringContainsString('vendor:publish --tag=consistent-api-config', $errors[4]);
        $this->assertStringContainsString('must be an array of middleware names', $errors[5]);
        $this->assertStringContainsString('Unknown method "fetch"', $errors[6]);
    }

    public function test_rejects_invalid_model_name(): void
    {
        $this->expectException(InvalidModuleOptionsException::class);

        $this->factory()->make('!!!', [], [], [], false);
    }

    public function test_rejects_empty_lists(): void
    {
        $this->assertCount(2, $this->errors(routes: [','], resources: [' ']));
    }

    public function test_rejects_different_put_and_patch_middleware(): void
    {
        $this->assertStringContainsString('put and patch', $this->errors(accesses: ['put-admin'])[0]);

        $this->assertSame(['put', 'patch'], $this->make(accesses: ['put-admin', 'patch-admin'])->routes['update']['methods']);
        $this->assertSame(['patch'], $this->make(routes: ['patch'], accesses: ['patch-admin'])->routes['update']['methods']);
    }

    public function test_rejects_uuid_when_disabled_or_column_is_empty(): void
    {
        $this->assertStringContainsString('consistentapi.uuid ', $this->errors(uuid: true, factory: $this->factory(uuid: false))[0]);
        $this->assertStringContainsString('uuid_column', $this->errors(uuid: true, factory: $this->factory(uuidColumn: ''))[0]);
    }

    public function test_uses_the_application_namespace(): void
    {
        $blueprint = (new ModuleBlueprintFactory('/app', 'Domain/Modules', [], false, '', 'Acme\\'))->make('Post', [], [], [], false);

        $this->assertSame('Acme\\Domain\\Modules\\Posts', $blueprint->namespace);
    }

    public function test_uuid_column_with_a_trailing_newline_is_rejected(): void
    {
        $this->assertStringContainsString('uuid_column', $this->errors(uuid: true, factory: $this->factory(uuidColumn: "public_id\n"))[0]);
    }
}
