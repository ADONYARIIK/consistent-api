<?php

declare(strict_types=1);

namespace Tests\Feature;

use Adonyarik\ConsistentApi\Exceptions\InvalidAccessException;
use Adonyarik\ConsistentApi\Exceptions\InvalidRelationSortException;
use Adonyarik\ConsistentApi\Support\FilterableSortableValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Article;
use Tests\Fixtures\ArticleController;
use Tests\TestCase;

class AccessRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('consistentapi.accesses', [
            'guest' => [],
            'admin' => ['auth:sanctum', 'can:admin'],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('articles', function ($table) {
            $table->id();
            $table->string('title');
            $table->string('email');
            $table->timestamps();
        });
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('api')->prefix('api')->group(function () use ($router) {
            $router->get('articles', [ArticleController::class, 'index']);
            $router->get('secret-articles', [ArticleController::class, 'secretIndex']);

            $router->middleware(['auth:sanctum', 'can:admin'])->group(function () use ($router) {
                $router->get('admin/articles', [ArticleController::class, 'index']);
                $router->get('admin/secret-articles', [ArticleController::class, 'secretIndex']);
                $router->get('throttled/articles', [ArticleController::class, 'index'])->middleware('throttle:60,1');
                $router->get('stripped/articles', [ArticleController::class, 'index'])->withoutMiddleware('can:admin');
            });
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubMiddlewareAliases('auth', 'can');

        Article::create(['title' => 'First', 'email' => 'a@example.com']);
        Article::create(['title' => 'Second', 'email' => 'b@example.com']);
    }

    public function test_route_without_middleware_sees_only_unrestricted_columns(): void
    {
        $this->getJson('/api/articles?filter[title]=First')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/articles?filter[email]=a@example.com')
            ->assertStatus(422)
            ->assertJson(['errors' => ['filter.email' => ['Filtering by email is not allowed.']]]);

        $this->getJson('/api/articles?sort[title]=desc')->assertOk()->assertJsonPath('items.0.title', 'Second');
        $this->getJson('/api/articles?sort[email]=asc')
            ->assertStatus(422)
            ->assertJson(['errors' => ['sort.email' => ['Sorting by email is not allowed.']]]);
    }

    public function test_restricted_column_values_are_not_validated_without_access(): void
    {
        // Rules for filter.email.eq exist only for admin, so the client gets "not allowed", not a value error
        $this->getJson('/api/articles?filter[email][eq]=not-an-email')
            ->assertStatus(422)
            ->assertJson(['errors' => ['filter.email' => ['Filtering by email is not allowed.']]]);

        $this->getJson('/api/admin/articles?filter[email][eq]=not-an-email')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['filter.email.eq']);
    }

    public function test_admin_route_sees_restricted_columns(): void
    {
        $this->getJson('/api/admin/articles?filter[email][eq]=a@example.com')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/admin/articles?sort[email]=desc')->assertOk()->assertJsonPath('items.0.email', 'b@example.com');
    }

    public function test_extra_middleware_keeps_access(): void
    {
        $this->getJson('/api/throttled/articles?filter[email][eq]=a@example.com')->assertOk();
        $this->getJson('/api/throttled/articles?sort[email]=asc')->assertOk();
    }

    public function test_excluded_middleware_removes_access(): void
    {
        $this->getJson('/api/stripped/articles?filter[email][eq]=a@example.com')
            ->assertStatus(422)
            ->assertJson(['errors' => ['filter.email' => ['Filtering by email is not allowed.']]]);
        $this->getJson('/api/stripped/articles?sort[email]=asc')
            ->assertStatus(422)
            ->assertJson(['errors' => ['sort.email' => ['Sorting by email is not allowed.']]]);
    }

    public function test_model_with_only_restricted_columns(): void
    {
        $this->getJson('/api/secret-articles?filter[email]=a')
            ->assertStatus(422)
            ->assertJsonPath('errors.filter.0', 'Filtering on this object is not allowed.');
        $this->getJson('/api/secret-articles?sort[email]=asc')
            ->assertStatus(422)
            ->assertJsonPath('errors.sort.0', 'Sorting on this object is not allowed.');

        $this->getJson('/api/admin/secret-articles?filter[email]=a@')->assertOk()->assertJsonCount(1, 'items');
    }

    public function test_outside_http_only_unrestricted_columns_apply_unless_accesses_are_passed(): void
    {
        $this->assertSame(2, Article::filter(['email' => ['eq' => 'a@example.com']])->count());
        $this->assertSame(1, Article::filter(['email' => ['eq' => 'a@example.com']], ['admin'])->count());

        $this->assertSame(['title'], (new Article)->getAllowedSorts());
        $this->assertSame(['title', 'email'], (new Article)->getAllowedSorts(['admin']));
    }

    public function test_validator_rejects_unknown_access(): void
    {
        config(['consistentapi.filters.model_paths' => [dirname(__DIR__).'/InvalidModels/UnknownAccess']]);

        $this->expectException(InvalidAccessException::class);
        $this->expectExceptionMessageToContain('access "unknown"');

        (new FilterableSortableValidator)->validateAll();
    }

    public function test_validator_rejects_column_repeated_across_attributes(): void
    {
        config(['consistentapi.filters.model_paths' => [dirname(__DIR__).'/InvalidModels/DuplicateColumn']]);

        $this->expectException(InvalidAccessException::class);
        $this->expectExceptionMessageToContain('column "title" in more than one #[Sortable]');

        (new FilterableSortableValidator)->validateAll();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brokenRelationSorts(): array
    {
        return [
            'missing method' => ['MissingSortRelation', 'no such method exists'],
            'not a relation' => ['SortByNonRelation', 'does not return an Eloquent relation'],
            'unsupported relation' => ['UnsupportedSortRelation', 'MorphTo'],
        ];
    }

    #[DataProvider('brokenRelationSorts')]
    public function test_validator_rejects_broken_relation_sort(string $directory, string $message): void
    {
        config(['consistentapi.filters.model_paths' => [dirname(__DIR__).'/InvalidModels/'.$directory]]);

        $this->expectException(InvalidRelationSortException::class);
        $this->expectExceptionMessageToContain($message);

        (new FilterableSortableValidator)->validateAll();
    }

    public function test_validator_accepts_valid_restrictions(): void
    {
        // The Book fixture declares every operator
        config([
            'consistentapi.filters.model_paths' => [dirname(__DIR__).'/Fixtures'],
            'consistentapi.filters.operators' => ['eq', 'not_eq', 'like', 'from', 'to', 'min', 'max', 'in', 'not_in', 'null'],
        ]);

        (new FilterableSortableValidator)->validateAll();

        $this->addToAssertionCount(1);
    }
}
