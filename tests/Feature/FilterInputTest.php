<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Product;
use Tests\Fixtures\ProductController;
use Tests\TestCase;

class FilterInputTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['Alpha', 'Beta', 'Gamma', 'Delta'];

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('products', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('status')->nullable();
            $table->integer('price');
            $table->date('released_at')->nullable();
            $table->timestamps();
        });
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('api')->prefix('api')->get('products', [ProductController::class, 'index']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Product::create(['name' => 'Alpha', 'status' => 'draft', 'price' => 10, 'released_at' => '2020-01-01']);
        Product::create(['name' => 'Beta', 'status' => 'published', 'price' => 20, 'released_at' => '2021-06-01']);
        Product::create(['name' => 'Gamma', 'status' => 'archived', 'price' => 30, 'released_at' => '2022-01-01']);
        Product::create(['name' => 'Delta', 'status' => null, 'price' => 40, 'released_at' => null]);
    }

    /**
     * @return list<string>
     */
    private function names(string $query): array
    {
        return $this->getJson('/api/products?'.$query)->assertOk()->json('items.*.name');
    }

    public function test_operator_without_rules_next_to_one_with_rules_is_applied(): void
    {
        // filter.price.in has no rules of its own next to filter.price.eq, and validated() must keep it
        $this->assertSame(['Alpha', 'Gamma'], $this->names('filter[price][in]=10,30'));
        $this->assertSame(['Beta'], $this->names('filter[price][eq]=20'));
        $this->assertSame(['Alpha'], $this->names('filter[status][eq]=draft'));
    }

    public function test_plain_list_is_an_in_filter(): void
    {
        $this->assertSame(['Alpha', 'Gamma'], $this->names('filter[status][]=draft&filter[status][]=archived'));
        $this->assertSame(['Alpha', 'Gamma'], $this->names('filter[price][]=10&filter[price][]=30'));

        $this->getJson('/api/products?filter[released_at][]=2020-01-01')
            ->assertStatus(422)
            ->assertJson(['errors' => ['filter.released_at.in' => ['Filtering by released_at.in is not allowed.']]]);
    }

    public function test_operator_not_declared_on_the_column_is_rejected(): void
    {
        $this->getJson('/api/products?filter[price][like]=1')
            ->assertStatus(422)
            ->assertJson(['errors' => ['filter.price.like' => ['Filtering by price.like is not allowed.']]]);
    }

    public function test_operator_disabled_in_config_is_rejected_for_columns_with_rules(): void
    {
        config(['consistentapi.filters.operators' => ['eq', 'like', 'to', 'in']]);

        $this->getJson('/api/products?filter[released_at][from]=2021-01-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['filter.released_at']);
    }

    public function test_empty_values_are_ignored(): void
    {
        $this->assertSame(self::ALL, $this->names('filter[status][eq]='));
        $this->assertSame(self::ALL, $this->names('filter[status][]='));
        $this->assertSame(self::ALL, $this->names('filter[price][in]='));
        $this->assertSame(self::ALL, $this->names('filter[released_at][from]='));
        $this->assertSame(self::ALL, $this->names('sort[name]='));
    }
}
