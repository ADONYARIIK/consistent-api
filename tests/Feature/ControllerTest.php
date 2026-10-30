<?php

declare(strict_types=1);

namespace Tests\Feature;

use Adonyarik\ConsistentApi\Controllers\ConsistentController;
use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use ReflectionMethod;
use Tests\Concerns\CreatesLibrary;
use Tests\Fixtures\Author;
use Tests\Fixtures\Book;
use Tests\Fixtures\BookController;
use Tests\Fixtures\BookPolicy;
use Tests\Fixtures\OwnBookController;
use Tests\Fixtures\ProfileController;
use Tests\Fixtures\TagController;
use Tests\TestCase;

/**
 * ConsistentController through HTTP: CRUD, eager loading, pagination modes and the 422 answers of the index.
 */
class ControllerTest extends TestCase
{
    use CreatesLibrary;

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('api')->prefix('api')->group(function () use ($router) {
            $router->get('books', [BookController::class, 'index']);
            $router->get('plain-books', [BookController::class, 'plainIndex']);
            $router->get('unchecked-books', [BookController::class, 'uncheckedIndex']);
            $router->post('books', [BookController::class, 'store']);
            $router->post('ann-books', [BookController::class, 'storeForAnn']);
            $router->get('books/{book}', [BookController::class, 'show']);
            $router->patch('books/{book}', [BookController::class, 'update']);
            $router->delete('books/{book}', [BookController::class, 'destroy']);
            $router->get('profiles', [ProfileController::class, 'index']);
            $router->get('tags', [TagController::class, 'index']);
            $router->get('own-books', [OwnBookController::class, 'index']);
            $router->post('own-books', [OwnBookController::class, 'store']);
            $router->patch('own-books/{book}', [OwnBookController::class, 'update']);
        });

        // Without the api group no SubstituteBindings: the actions get empty models
        $router->patch('nobind/books/{book}', [BookController::class, 'update']);
        $router->delete('nobind/books/{book}', [BookController::class, 'destroy']);
        $router->get('nobind/books/{book}', [BookController::class, 'show']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLibraryTables();
        $this->seedLibrary();
    }

    private function bookId(string $title): int
    {
        return (int) Book::where('title', $title)->value('id');
    }

    public function test_store_creates_the_model_and_loads_relations(): void
    {
        $ann = Author::where('name', 'Ann')->value('id');

        $this->postJson('/api/books', ['title' => 'New', 'status' => 'draft', 'author_id' => $ann, 'price' => 99])
            ->assertCreated()
            ->assertJson(['title' => 'New', 'status' => 'draft', 'author' => 'Ann']);

        // Only validated attributes are saved: price has no rule in StoreBookRequest
        $this->assertSame(0, (int) Book::where('title', 'New')->value('price'));

        $this->postJson('/api/books', ['status' => 'draft'])->assertStatus(422)->assertJsonValidationErrors(['title']);
    }

    public function test_show_update_and_destroy_work_on_the_bound_model(): void
    {
        $id = $this->bookId('Dune');

        $this->getJson('/api/books/'.$id)->assertOk()->assertJson(['id' => $id, 'title' => 'Dune', 'author' => 'Ann']);

        $this->patchJson('/api/books/'.$id, ['title' => 'Dune Messiah'])
            ->assertOk()
            ->assertJson(['id' => $id, 'title' => 'Dune Messiah', 'status' => 'archived', 'author' => 'Ann']);

        $this->deleteJson('/api/books/'.$id)->assertNoContent();
        $this->assertNull(Book::find($id));
        $this->getJson('/api/books/'.$id)->assertNotFound();
    }

    public function test_index_paginates_with_the_model_page_size_and_perpage(): void
    {
        $this->getJson('/api/books')
            ->assertOk()
            ->assertJsonCount(4, 'items')
            ->assertJsonPath('items.0.author', 'Ann')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 4);

        $this->getJson('/api/books?perpage=10&page=2')->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('meta.from', null);
        $this->getJson('/api/books?perpage=7')->assertStatus(422)->assertJsonValidationErrors(['perpage']);
        $this->getJson('/api/books?paginate=maybe')->assertStatus(422)->assertJsonValidationErrors(['paginate']);

        // An invalid page is an error, not page 1
        foreach (['abc', '0', '-1', '1.5'] as $page) {
            $this->getJson('/api/books?page='.$page)->assertStatus(422)->assertJsonValidationErrors(['page']);
        }
    }

    public function test_paginate_false_returns_all_rows_only_for_models_with_the_contract(): void
    {
        $this->getJson('/api/tags?paginate=false&sort[name]=asc')
            ->assertOk()
            ->assertExactJson(['items' => [['name' => 'classic'], ['name' => 'novel'], ['name' => 'sf']]]);
        $this->getJson('/api/tags?paginate=0')->assertOk()->assertJsonMissingPath('meta');
        $this->getJson('/api/tags')->assertOk()->assertJsonPath('meta.total', 3);

        $this->getJson('/api/books?paginate=false')->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_index_filters_and_sorts_through_relations(): void
    {
        $this->getJson('/api/books?filter[author]=ann&sort[title]=asc')
            ->assertOk()
            ->assertJsonPath('items.*.title', ['Dune', 'The Ring']);

        $this->getJson('/api/books?sort[author]=desc&sort[title]=asc')
            ->assertOk()
            ->assertJsonPath('items.*.title', ['Emma', 'Ringworld', 'Dune', 'The Ring']);
    }

    public function test_index_rejects_what_the_model_does_not_declare(): void
    {
        $errors = [
            'filter[title][eq]=x' => ['filter.title', 'Nested filtering by title is not allowed.'],
            'filter[author][eq]=x' => ['filter.author', 'Nested filtering by author is not allowed.'],
            'filter[price][]=10' => ['filter.price.in', 'Filtering by price.in is not allowed.'],
            'filter[price][like]=1' => ['filter.price.like', 'Filtering by price.like is not allowed.'],
            // like has no value rules, so the search request lets the array through
            'filter[status][like][]=pub' => ['filter.status.like', 'Filtering by status.like takes a single value.'],
            'sort[status]=asc' => ['sort.status', 'Sorting by status is not allowed.'],
        ];

        foreach ($errors as $query => [$key, $message]) {
            $this->getJson('/api/books?'.$query)
                ->assertStatus(422)
                ->assertExactJson(['message' => $message, 'errors' => [$key => [$message]]]);
        }
    }

    public function test_index_rejects_values_of_the_wrong_shape_without_request_rules(): void
    {
        config(['consistentapi.filters.operators' => ['eq', 'like', 'from', 'to', 'in', 'null']]);

        $errors = [
            'sort[title]=up' => ['sort.title', 'Sorting by title takes asc or desc.'],
            'sort[title][]=asc' => ['sort.title', 'Sorting by title takes asc or desc.'],
            'filter[status]=pub' => ['filter.status', 'Filtering by status requires an operator.'],
            'filter[status][eq][]=draft' => ['filter.status.eq', 'Filtering by status.eq takes a single value.'],
            'filter[status][in][x][]=draft' => ['filter.status.in', 'Filtering by status.in takes a list of values.'],
            'filter[status][null]=maybe' => ['filter.status.null', 'Filtering by status.null takes true or false.'],
            'filter[published_at][from]=not-a-date' => ['filter.published_at.from', 'Filtering by published_at.from takes a date.'],
            'filter[published_at][to]=2021-13-45' => ['filter.published_at.to', 'Filtering by published_at.to takes a date.'],
        ];

        foreach ($errors as $query => [$key, $message]) {
            $this->getJson('/api/unchecked-books?'.$query)
                ->assertStatus(422)
                ->assertExactJson(['message' => $message, 'errors' => [$key => [$message]]]);
        }

        // Values of the right shape and empty values pass
        $this->getJson('/api/unchecked-books?sort[title]=desc&filter[status][null]=false&filter[published_at][from]=2021-01-01&filter[price][eq]=')
            ->assertOk()
            ->assertJsonPath('items.*.title', ['Ringworld', 'Dune']);
    }

    public function test_input_echoed_in_errors_is_escaped(): void
    {
        $response = $this->getJson('/api/books?filter[<b>x</b>]=1')->assertStatus(422);

        // A JSON decoder reads the name back, the body itself carries no markup
        $this->assertSame(['filter.<b>x</b>' => ['Filtering by <b>x</b> is not allowed.']], $response->json('errors'));
        $this->assertStringNotContainsString('<b>', (string) $response->getContent());
        $this->assertStringContainsString(trim(json_encode('<b>', JSON_HEX_TAG), '"'), (string) $response->getContent());
    }

    public function test_column_with_operators_takes_operators_only(): void
    {
        // The search request with $model rejects the scalar by its rules, the controller does without them
        $this->getJson('/api/books?filter[status]=pub')->assertStatus(422)->assertJsonValidationErrors(['filter.status']);
        $this->getJson('/api/plain-books?filter[status]=pub')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Filtering by status requires an operator.', 'errors' => ['filter.status' => ['Filtering by status requires an operator.']]]);

        $this->getJson('/api/plain-books?filter[status][eq]=draft')->assertOk()->assertJsonPath('items.*.title', ['The Ring']);
        $this->getJson('/api/plain-books?filter[status]=')->assertOk()->assertJsonCount(4, 'items');
        // Plain and relation columns take a scalar
        $this->getJson('/api/plain-books?filter[title]=ring&filter[author]=bob')->assertOk()->assertJsonPath('items.*.title', ['Ringworld']);
    }

    public function test_index_rejects_filtering_and_sorting_a_model_without_declarations(): void
    {
        $this->getJson('/api/profiles?filter[nickname]=a')
            ->assertStatus(422)
            ->assertJsonPath('errors.filter.0', 'Filtering on this object is not allowed.');
        $this->getJson('/api/profiles?sort[nickname]=asc')
            ->assertStatus(422)
            ->assertJsonPath('errors.sort.0', 'Sorting on this object is not allowed.');
        $this->getJson('/api/profiles')->assertOk()->assertJsonCount(2, 'items');
    }

    public function test_search_request_validates_values_and_enabled_operators(): void
    {
        $this->getJson('/api/books?filter[price][min]=abc')->assertStatus(422)->assertJsonValidationErrors(['filter.price.min']);
        $this->getJson('/api/books?filter[published_at][from]=not-a-date')->assertStatus(422)->assertJsonValidationErrors(['filter.published_at.from']);
        $this->getJson('/api/books?sort[title]=up')->assertStatus(422)->assertJsonValidationErrors(['sort.title']);

        // not_eq is declared on the model but not enabled in config('consistentapi.filters.operators')
        $this->getJson('/api/books?filter[status][not_eq]=draft')
            ->assertStatus(422)
            ->assertJsonPath('errors', ['filter.status' => ['The filter operators [not_eq] for "status" are not enabled.']]);

        config(['consistentapi.filters.operators' => ['eq', 'like', 'from', 'to', 'in', 'not_eq']]);

        $this->getJson('/api/books?filter[status][not_eq]=draft')->assertOk()->assertJsonPath('items.*.title', ['Ringworld', 'Dune']);
    }

    public function test_store_keeps_attributes_set_on_the_server(): void
    {
        $this->postJson('/api/ann-books', ['title' => 'Owned'])->assertCreated()->assertJson(['title' => 'Owned', 'author' => 'Ann']);

        $book = Book::where('title', 'Owned')->firstOrFail();
        $this->assertSame(Author::where('name', 'Ann')->value('id'), $book->author_id);
        $this->assertSame(77, (int) $book->price);
    }

    public function test_index_query_scopes_the_list_before_the_requested_filters_and_sorts(): void
    {
        $this->getJson('/api/own-books?sort[title]=asc')
            ->assertOk()
            ->assertJsonPath('items.*.title', ['Dune', 'The Ring'])
            ->assertJsonPath('meta.total', 2);

        // Filters narrow the scoped list; a filter matching other authors' books cannot widen it
        $this->getJson('/api/own-books?filter[title]=ring')->assertOk()->assertJsonPath('items.*.title', ['The Ring']);
        $this->getJson('/api/own-books?filter[author]=bob')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/own-books?sort[editor]=desc')->assertOk()->assertJsonPath('items.*.title', ['The Ring', 'Dune']);

        // The 422 checks are the same as without the hook
        $this->getJson('/api/own-books?sort[status]=asc')->assertStatus(422)->assertJsonValidationErrors(['sort.status']);

        $this->getJson('/api/own-books', ['X-Admin' => '1'])->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_before_store_and_before_update_take_precedence_over_the_input(): void
    {
        $ann = Author::where('name', 'Ann')->value('id');
        $bob = Author::where('name', 'Bob')->value('id');

        $this->postJson('/api/own-books', ['title' => 'Mine', 'author_id' => $bob])
            ->assertCreated()
            ->assertJson(['title' => 'Mine', 'author' => 'Ann']);
        $this->assertSame($ann, Book::where('title', 'Mine')->value('author_id'));

        $id = $this->bookId('Emma');

        $this->patchJson('/api/own-books/'.$id, ['status' => 'draft'])->assertOk();
        $this->assertNotSame($ann, Book::find($id)?->editor_id);

        $this->patchJson('/api/own-books/'.$id, ['title' => 'Emma II'])->assertOk()->assertJson(['title' => 'Emma II']);
        $this->assertSame($ann, Book::find($id)?->editor_id);
    }

    public function test_cancelled_writes_answer_409_and_change_nothing(): void
    {
        $id = $this->bookId('Dune');

        try {
            Book::updating(fn () => false);
            Book::deleting(fn () => false);
            Book::creating(fn () => false);

            $this->patchJson('/api/books/'.$id, ['title' => 'Changed'])->assertStatus(409)->assertJsonPath('message', 'The record was not updated.');
            $this->deleteJson('/api/books/'.$id)->assertStatus(409)->assertJsonPath('message', 'The record was not deleted.');
            $this->postJson('/api/books', ['title' => 'Never'])->assertStatus(409)->assertJsonPath('message', 'The record was not created.');
        } finally {
            Book::flushEventListeners();
        }

        $this->assertSame('Dune', Book::find($id)?->title);
        $this->assertFalse(Book::where('title', 'Never')->exists());
    }

    public function test_actions_answer_404_for_models_that_are_not_stored(): void
    {
        $count = Book::count();

        $this->patchJson('/nobind/books/999', ['title' => 'Ghost'])->assertNotFound();
        $this->deleteJson('/nobind/books/999')->assertNotFound();
        $this->getJson('/nobind/books/999')->assertNotFound();

        $this->assertSame($count, Book::count());
    }

    public function test_actions_are_authorized_through_the_model_policy(): void
    {
        Gate::policy(Book::class, BookPolicy::class);
        $id = $this->bookId('Dune');

        $this->getJson('/api/books/'.$id)->assertOk();
        $this->patchJson('/api/books/'.$id, ['title' => 'Locked'])->assertOk();
        // The policy now sees the stored title
        $this->patchJson('/api/books/'.$id, ['title' => 'Free'])->assertForbidden();
        $this->deleteJson('/api/books/'.$id)->assertForbidden();
        // A policy without viewAny denies the index
        $this->getJson('/api/books')->assertForbidden();
        $this->postJson('/api/books', ['title' => 'Allowed'])->assertCreated();

        // Models without a policy are not authorized at all
        $this->getJson('/api/tags')->assertOk();
    }

    public function test_index_orders_by_the_primary_key_after_the_requested_sorts(): void
    {
        DB::enableQueryLog();

        $this->getJson('/api/books?sort[price]=asc')->assertOk();
        $this->getJson('/api/tags?paginate=false')->assertOk();

        $selects = array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $sql) => str_contains($sql, 'from "books"') && ! str_contains($sql, 'count(*)') || str_contains($sql, 'from "tags"'),
        ));

        $this->assertStringContainsString('order by "books"."price" asc, "books"."id" asc limit 10 offset 0', $selects[0]);
        $this->assertStringContainsString('order by "tags"."id" asc', $selects[1]);
    }

    public function test_page_has_an_upper_bound(): void
    {
        $this->getJson('/api/books?page='.BaseSearchRequest::MAX_PAGE)->assertOk();
        $this->getJson('/api/books?page='.(BaseSearchRequest::MAX_PAGE + 1))->assertStatus(422)->assertJsonValidationErrors(['page']);
        $this->getJson('/api/books?page=9223372036854775807')->assertStatus(422)->assertJsonValidationErrors(['page']);
    }

    public function test_paginate_false_returns_at_most_the_configured_number_of_rows(): void
    {
        config(['consistentapi.pagination.unpaginated_limit' => 2]);

        $this->getJson('/api/tags?paginate=false&sort[name]=asc')->assertOk()->assertExactJson(['items' => [['name' => 'classic'], ['name' => 'novel']]]);

        config(['consistentapi.pagination.unpaginated_limit' => null]);

        $this->getJson('/api/tags?paginate=false')->assertOk()->assertJsonCount(3, 'items');
    }

    public function test_plain_list_is_validated_like_the_in_operator(): void
    {
        $three = 'filter[status][in][]=draft&filter[status][in][]=published&filter[status][in][]=archived';

        $this->getJson('/api/books?'.$three)->assertStatus(422)->assertJsonValidationErrors(['filter.status.in']);
        $this->getJson('/api/books?'.str_replace('[in]', '', $three))->assertStatus(422)->assertJsonValidationErrors(['filter.status.in']);

        $this->getJson('/api/books?filter[status][]=draft&filter[status][]=published')
            ->assertOk()
            ->assertJsonPath('items.*.title', ['The Ring', 'Ringworld']);
    }

    public function test_relation_filter_rules_validate_the_value(): void
    {
        $ann = Author::where('name', 'Ann')->value('id');

        $this->getJson('/api/books?filter[author_id]=abc')->assertStatus(422)->assertJsonValidationErrors(['filter.author_id']);
        $this->getJson('/api/books?filter[author_id]='.$ann)->assertOk()->assertJsonPath('items.*.title', ['The Ring', 'Dune']);
    }

    public function test_controller_rejects_operators_disabled_in_config_without_request_rules(): void
    {
        // not_eq is declared on the model, the request has no operator rules, and the config does not enable it
        $this->getJson('/api/unchecked-books?filter[status][not_eq]=draft')
            ->assertStatus(422)
            ->assertJsonPath('errors', ['filter.status.not_eq' => ['Filtering by status.not_eq is not allowed.']]);
    }

    public function test_resource_class_defaults_to_json_resource(): void
    {
        $controller = new class extends ConsistentController {};
        $resourceClassFor = new ReflectionMethod($controller, 'resourceClassFor');

        $this->assertSame(JsonResource::class, $resourceClassFor->invoke($controller));
    }
}
