<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Adonyarik\ConsistentApi\Support\RelationFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesLibrary;
use Tests\Fixtures\Author;
use Tests\Fixtures\Book;
use Tests\Fixtures\BookController;
use Tests\Fixtures\Category;
use Tests\Fixtures\Moment;
use Tests\Fixtures\Profile;
use Tests\Fixtures\Sorting\Clip;
use Tests\Fixtures\Sorting\Gallery;
use Tests\Fixtures\Sorting\Shelf;
use Tests\Fixtures\Sorting\ShelfLabel;
use Tests\Fixtures\Sorting\Sticker;
use Tests\Fixtures\Sorting\Volume;
use Tests\Fixtures\Tag;
use Tests\InvalidModels\UnsupportedSortRelation\Report;

/**
 * CanFilter and CanSort against a real database; each driver runs the same scenarios.
 */
abstract class FilterSortTestCase extends DatabaseTestCase
{
    use CreatesLibrary;

    protected function setUp(): void
    {
        parent::setUp();

        config(['consistentapi.filters.operators' => ['eq', 'not_eq', 'like', 'from', 'to', 'min', 'max', 'in', 'not_in', 'null']]);

        $this->createLibraryTables();
        $this->seedLibrary();
    }

    /**
     * @param  array<string, mixed>  $filters
     *
     * @return list<string>
     */
    private function filtered(array $filters): array
    {
        return Book::filter($filters, [])->orderBy('books.id')->pluck('title')->all();
    }

    /**
     * @return list<string>
     */
    private function titles(Builder $query): array
    {
        return $query->get()->pluck('title')->all();
    }

    public function test_scalar_value_is_a_case_insensitive_substring_search(): void
    {
        $this->assertSame(['The Ring', 'Ringworld'], $this->filtered(['title' => 'ring']));
        $this->assertSame(['Ringworld'], $this->filtered(['status' => 'PUB']));
    }

    public function test_comparison_operators(): void
    {
        $this->assertSame(['The Ring'], $this->filtered(['status' => ['eq' => 'draft']]));
        $this->assertSame(['Ringworld', 'Dune'], $this->filtered(['status' => ['not_eq' => 'draft']]));
        $this->assertSame(['Ringworld'], $this->filtered(['status' => ['like' => 'PUB']]));
        $this->assertSame(['Dune'], $this->filtered(['price' => ['eq' => 30]]));
        $this->assertSame(['Ringworld', 'Dune', 'Emma'], $this->filtered(['price' => ['min' => 20]]));
        $this->assertSame(['The Ring', 'Ringworld'], $this->filtered(['price' => ['max' => 20]]));
        $this->assertSame(['Ringworld', 'Dune'], $this->filtered(['price' => ['min' => 20, 'max' => 30]]));
    }

    public function test_list_operators_take_arrays_and_comma_separated_strings(): void
    {
        $this->assertSame(['The Ring', 'Dune'], $this->filtered(['status' => ['in' => ['draft', 'archived']]]));
        $this->assertSame(['The Ring', 'Dune'], $this->filtered(['status' => ['in' => 'draft, archived']]));
        $this->assertSame(['Ringworld', 'Dune'], $this->filtered(['status' => ['not_in' => 'draft']]));
        $this->assertSame(['The Ring', 'Ringworld'], $this->filtered(['status' => ['draft', 'published']]));
    }

    public function test_null_operator_takes_a_boolean(): void
    {
        $this->assertSame(['Emma'], $this->filtered(['status' => ['null' => 'true']]));
        $this->assertSame(['The Ring', 'Ringworld', 'Dune'], $this->filtered(['status' => ['null' => '0']]));
        $this->assertSame(['The Ring'], $this->filtered(['published_at' => ['null' => '1']]));
    }

    public function test_from_and_to_cover_whole_days(): void
    {
        $this->assertSame(['Ringworld'], $this->filtered(['published_at' => ['from' => '2021-06-15', 'to' => '2021-06-15']]));
        $this->assertSame(['Ringworld', 'Dune'], $this->filtered(['published_at' => ['from' => '2021-01-01']]));
        $this->assertSame(['Emma'], $this->filtered(['published_at' => ['to' => '2020-12-31']]));
    }

    public function test_empty_and_unknown_input_is_skipped(): void
    {
        $all = ['The Ring', 'Ringworld', 'Dune', 'Emma'];

        $this->assertSame($all, $this->filtered(['status' => null]));
        $this->assertSame($all, $this->filtered(['status' => ['eq' => null, 'in' => '']]));
        $this->assertSame($all, $this->filtered(['nope' => 'x']));
        // like is not declared for price, and a list of arrays is not an `in` filter
        $this->assertSame($all, $this->filtered(['price' => ['like' => '1']]));
        $this->assertSame($all, $this->filtered(['status' => [['draft'], ['archived']]]));
        // Only in / not_in take a list: an array for another operator is malformed and skipped
        $this->assertSame($all, $this->filtered(['status' => ['like' => ['pub'], 'eq' => ['draft']]]));
        $this->assertSame($all, $this->filtered(['published_at' => ['from' => ['2021-01-01']]]));
        // So are other values of the wrong shape, instead of failing or being read as something else
        $this->assertSame($all, $this->filtered(['published_at' => ['from' => 'not-a-date', 'to' => '2021-13-45']]));
        $this->assertSame($all, $this->filtered(['status' => ['null' => 'maybe']]));
        $this->assertSame($all, $this->filtered(['status' => ['in' => [['draft', 'archived']]]]));
        $this->assertSame($all, $this->filtered(['title' => '', 'status' => ['eq' => '']]));
    }

    public function test_substring_search_takes_wildcards_literally(): void
    {
        Tag::create(['name' => '100%']);
        Tag::create(['name' => 'snake_case']);
        Tag::create(['name' => 'a!b [x]']);
        Tag::create(['name' => 'back\\slash']);

        $names = fn (array $filters) => Tag::filter($filters, [])->orderBy('tags.id')->pluck('name')->all();

        $this->assertSame(['100%'], $names(['name' => '%']));
        $this->assertSame(['snake_case'], $names(['name' => '_']));
        $this->assertSame(['100%'], $names(['name' => '0%']));
        $this->assertSame([], $names(['name' => 'snake%case']));
        $this->assertSame(['a!b [x]'], $names(['name' => '!b [']));
        $this->assertSame(['back\\slash'], $names(['name' => 'k\\s']));
        $this->assertSame(['classic', 'snake_case', 'back\\slash'], $names(['name' => 'C']));
        // Relation filters and the like operator escape the same way
        $this->assertSame([], $this->filtered(['author' => '%']));
        $this->assertSame([], $this->filtered(['status' => ['like' => '_']]));
    }

    public function test_filter_keeps_the_columns_selected_before_it(): void
    {
        $book = Book::withCount('tags')->filter(['title' => 'Dune'], [])->first();

        $this->assertSame('Dune', $book->title);
        $this->assertSame(2, (int) $book->tags_count);
    }

    public function test_direct_call_applies_any_operator_to_a_column_without_operators(): void
    {
        $this->assertSame(['Dune'], $this->filtered(['title' => ['eq' => 'Dune', 'bogus' => 'x']]));
    }

    public function test_relation_filters(): void
    {
        $this->assertSame(['The Ring', 'Dune'], $this->filtered(['author' => 'an']));
        $this->assertSame(['The Ring', 'Dune'], $this->filtered(['author_exact' => 'Ann']));
        $this->assertSame([], $this->filtered(['author_exact' => 'an']));
        // A belongs-to-many relation does not duplicate rows
        $this->assertSame(['The Ring', 'Dune', 'Emma'], $this->filtered(['tag' => 'class']));
        // A relation filter takes a single value
        $this->assertSame(['The Ring', 'Ringworld', 'Dune', 'Emma'], $this->filtered(['author' => ['eq' => 'Ann']]));
    }

    public function test_declarations_are_normalized(): void
    {
        $book = new Book;

        $this->assertTrue($book->isFilterable([]));
        $this->assertSame(['title', 'status', 'price', 'published_at', 'author', 'author_exact', 'tag', 'author_id'], $book->getAllowedFilters([]));
        $this->assertSame(['eq', 'not_eq', 'like', 'in', 'not_in', 'null'], $book->getAllowedFilterKeys('status', []));
        $this->assertNull($book->getAllowedFilterKeys('title', []));
        $this->assertNull($book->getAllowedFilterKeys('author', []));
        $this->assertNull($book->getAllowedFilterKeys('nope', []));

        $rules = $book->filterRulesMap([]);
        $this->assertSame(['eq' => [], 'min' => ['integer', 'nullable'], 'max' => ['integer', 'nullable']], $rules['price']);
        $this->assertNull($rules['title']);
        $this->assertInstanceOf(RelationFilter::class, $rules['author']);
        $this->assertSame('eq', $rules['author_exact']->operator);
    }

    public function test_sorts_by_columns_in_input_order(): void
    {
        $this->assertSame(['Dune', 'Emma', 'Ringworld', 'The Ring'], $this->titles(Book::sort(['title' => 'asc'], [])));
        $this->assertSame(['Emma', 'Dune', 'Ringworld', 'The Ring'], $this->titles(Book::sort(['price' => 'desc'], [])));
    }

    public function test_empty_direction_and_unknown_columns_add_no_order(): void
    {
        $sql = strtolower(Book::sort(['title' => null, 'nope' => 'asc'], [])->toSql());

        $this->assertStringNotContainsString('order by', $sql);
    }

    public function test_sorts_through_belongs_to_and_keeps_the_model_columns(): void
    {
        $books = Book::sort(['author' => 'asc', 'title' => 'asc'], [])->get();

        $this->assertSame(['Dune', 'The Ring', 'Emma', 'Ringworld'], $books->pluck('title')->all());
        $this->assertSame(Book::where('title', 'Dune')->value('id'), $books->first()->id);
    }

    public function test_sorts_through_two_relations_to_the_same_table(): void
    {
        $query = Book::sort(['author' => 'asc', 'editor' => 'asc'], []);

        // Subqueries, not joins: nothing can replace the book's columns or multiply its rows
        $this->assertEmpty($query->getQuery()->joins);
        // Ann wrote Dune (edited by Bob) and The Ring (Cid), Bob wrote Ringworld (Ann) and Emma (Cid)
        $this->assertSame(['Dune', 'The Ring', 'Ringworld', 'Emma'], $this->titles($query));

        $books = Book::sort(['editor' => 'desc', 'title' => 'asc'], [])->get();

        $this->assertSame(['Emma', 'The Ring', 'Dune', 'Ringworld'], $books->pluck('title')->all());
        $this->assertSame(Book::where('title', 'Emma')->value('id'), $books->first()->id);
    }

    public function test_sorts_through_has_one_and_keeps_models_without_a_related_row(): void
    {
        $names = Author::sort(['nickname' => 'asc'], [])->get()->pluck('name')->all();

        // NULL goes first or last depending on the database
        $this->assertCount(3, $names);
        $this->assertSame(['Bob', 'Ann'], array_values(array_diff($names, ['Cid'])));
    }

    public function test_to_many_sort_takes_the_smallest_value_ascending_and_the_largest_descending(): void
    {
        $query = fn (string $direction) => Author::sort(['books' => $direction], [])->whereHas('books');

        // Ann: Dune, The Ring; Bob: Emma, Ringworld
        $this->assertSame(['Ann', 'Bob'], $query('asc')->get()->pluck('name')->all());
        $this->assertSame(['Ann', 'Bob'], $query('desc')->get()->pluck('name')->all());
    }

    public function test_sorts_through_belongs_to_many(): void
    {
        // Smallest tags: classic for Dune, Emma and The Ring, sf for Ringworld
        $this->assertSame(['Dune', 'Emma', 'The Ring', 'Ringworld'], $this->titles(Book::sort(['tags' => 'asc', 'title' => 'asc'], [])));
        // Largest tags: sf for Dune and Ringworld, novel for The Ring, classic for Emma
        $this->assertSame(['Dune', 'Ringworld', 'The Ring', 'Emma'], $this->titles(Book::sort(['tags' => 'desc', 'title' => 'asc'], [])));
    }

    public function test_sort_direction_other_than_asc_or_desc_is_skipped(): void
    {
        $this->assertStringNotContainsString('order by', strtolower(Book::sort(['title' => 'up', 'price' => ['asc']], [])->toSql()));
        $this->assertSame(['Dune', 'Emma', 'Ringworld', 'The Ring'], $this->titles(Book::sort(['title' => 'ASC'], [])));
    }

    /**
     * Alpha and Beta are roots; zulu is Alpha's child and related to Beta, echo is Beta's child and related to Alpha.
     */
    private function createCategories(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable();
            $table->timestamps();
        });

        Schema::create('category_related', function (Blueprint $table) {
            $table->foreignId('category_id');
            $table->foreignId('related_id');
        });

        $alpha = Category::create(['name' => 'Alpha']);
        $beta = Category::create(['name' => 'Beta']);
        $zulu = Category::create(['name' => 'zulu', 'parent_id' => $alpha->id]);
        $echo = Category::create(['name' => 'echo', 'parent_id' => $beta->id]);

        $alpha->related()->attach($echo->id);
        $beta->related()->attach($zulu->id);
    }

    /**
     * @return list<string>
     */
    private function categoryNames(Builder $query): array
    {
        return $query->get()->pluck('name')->all();
    }

    public function test_sorts_through_to_one_relations_to_the_same_table(): void
    {
        $this->createCategories();

        $children = Category::sort(['parent' => 'desc'], [])->whereNotNull('categories.parent_id')->get();
        $this->assertSame(['echo', 'zulu'], $children->pluck('name')->all());
        // The parent's columns do not replace the child's
        $this->assertSame(Category::where('name', 'echo')->value('id'), $children->first()->id);

        $this->assertSame(['Beta', 'Alpha'], $this->categoryNames(Category::sort(['first_child' => 'asc'], [])->whereNull('categories.parent_id')));
        $this->assertSame(['Alpha', 'Beta'], $this->categoryNames(Category::sort(['first_child' => 'desc'], [])->whereNull('categories.parent_id')));

        // Each relation gets its own alias, so both sorts fit in one query
        $this->assertCount(4, Category::sort(['parent' => 'asc', 'first_child' => 'asc', 'name' => 'asc'], [])->get());
    }

    public function test_sorts_through_to_many_relations_to_the_same_table(): void
    {
        $this->createCategories();
        $roots = fn (string $relation, string $direction) => $this->categoryNames(
            Category::sort([$relation => $direction], [])->whereNull('categories.parent_id'),
        );

        // Children: Alpha has "zulu", Beta has "echo"
        $this->assertSame(['Beta', 'Alpha'], $roots('children', 'asc'));
        $this->assertSame(['Alpha', 'Beta'], $roots('children', 'desc'));
        // Related: Alpha has "echo", Beta has "zulu"
        $this->assertSame(['Alpha', 'Beta'], $roots('related', 'asc'));
        $this->assertSame(['Beta', 'Alpha'], $roots('related', 'desc'));
    }

    /**
     * Ann has two labels (old "zeta" first, then "alpha"), Bob one ("mid"). Ann's volumes: "Zebra" (approved,
     * 9 pages), "00 trashed" (approved, soft deleted), "Aardvark" (not approved); Bob's: "Mango" (approved,
     * 100 pages). Volumes reach stickers through string codes.
     */
    private function createShelves(?string $connection = null): void
    {
        $schema = Schema::connection($connection);

        $schema->create('shelves', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $schema->create('shelf_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shelf_id');
            $table->string('displayName');
            $table->timestamps();
        });

        $schema->create('volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shelf_id');
            $table->string('code');
            $table->string('title');
            $table->integer('pages')->default(0);
            $table->boolean('approved')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        $schema->create('stickers', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
        });

        $schema->create('volume_sticker', function (Blueprint $table) {
            $table->string('volume_code');
            $table->string('sticker_code');
        });

        $on = fn (string $class) => (new $class)->setConnection($connection);

        $ann = $on(Shelf::class)->create(['name' => 'Ann']);
        $bob = $on(Shelf::class)->create(['name' => 'Bob']);

        $on(ShelfLabel::class)->create(['shelf_id' => $ann->id, 'displayName' => 'zeta']);
        $on(ShelfLabel::class)->create(['shelf_id' => $bob->id, 'displayName' => 'mid']);
        $on(ShelfLabel::class)->create(['shelf_id' => $ann->id, 'displayName' => 'alpha']);

        $on(Volume::class)->create(['shelf_id' => $ann->id, 'code' => 'v-zebra', 'title' => 'Zebra', 'pages' => 9, 'approved' => true]);
        $on(Volume::class)->create(['shelf_id' => $ann->id, 'code' => 'v-trash', 'title' => '00 trashed', 'pages' => 1, 'approved' => true])->delete();
        $on(Volume::class)->create(['shelf_id' => $ann->id, 'code' => 'v-aard', 'title' => 'Aardvark', 'pages' => 5, 'approved' => false]);
        $on(Volume::class)->create(['shelf_id' => $bob->id, 'code' => 'v-mango', 'title' => 'Mango', 'pages' => 100, 'approved' => true]);

        // The sticker ids are the reverse of their names, so joining on ids would order them the other way
        $on(Sticker::class)->create(['code' => 's-2', 'name' => 'zz']);
        $on(Sticker::class)->create(['code' => 's-1', 'name' => 'aa']);

        $on(Volume::class)->newQuery()->getConnection()->table('volume_sticker')->insert([
            ['volume_code' => 'v-zebra', 'sticker_code' => 's-1'],
            ['volume_code' => 'v-mango', 'sticker_code' => 's-2'],
        ]);
    }

    public function test_has_one_with_several_rows_neither_duplicates_rows_nor_inflates_the_total(): void
    {
        $this->createShelves();

        // Ann's labels are "zeta" and "alpha", Bob's is "mid"
        $this->assertSame(['Ann', 'Bob'], Shelf::sort(['label' => 'asc'], [])->get()->pluck('name')->all());
        $this->assertSame(['Ann', 'Bob'], Shelf::sort(['label' => 'desc'], [])->get()->pluck('name')->all());
        $this->assertSame(2, Shelf::sort(['label' => 'asc'], [])->paginate(10)->total());
    }

    public function test_relation_conditions_one_of_many_and_soft_deletes_apply(): void
    {
        $this->createShelves();

        // Only approved, not deleted volumes count: Bob's "Mango" before Ann's "Zebra"
        $this->assertSame(['Bob', 'Ann'], Shelf::sort(['approved_volumes' => 'asc'], [])->get()->pluck('name')->all());
        // The latest label alone: Ann's "alpha", not her older "zeta", against Bob's "mid"
        $this->assertSame(['Bob', 'Ann'], Shelf::sort(['latest_label' => 'desc'], [])->get()->pluck('name')->all());
    }

    public function test_to_many_sort_compares_numbers_as_numbers(): void
    {
        $this->createShelves();

        // Ann's volumes have 9 and 5 pages (the deleted one is ignored), Bob's 100: as text "100" < "5"
        $this->assertSame(['Ann', 'Bob'], Shelf::sort(['volume_pages' => 'asc'], [])->get()->pluck('name')->all());
        $this->assertSame(['Bob', 'Ann'], Shelf::sort(['volume_pages' => 'desc'], [])->get()->pluck('name')->all());
    }

    public function test_belongs_to_many_uses_the_custom_keys(): void
    {
        $this->createShelves();

        // Zebra has sticker "aa", Mango "zz"; the other volumes have none
        $titles = Volume::sort(['stickers' => 'asc'], [])->whereHas('stickers')->get()->pluck('title')->all();

        $this->assertSame(['Zebra', 'Mango'], $titles);
    }

    public function test_quotes_column_names_and_follows_the_table_prefix(): void
    {
        config(['database.connections.prefixed' => array_merge(
            config('database.connections.'.config('database.default')),
            ['prefix' => 'px_'],
        )]);
        $this->createShelves('prefixed');

        $query = (new Shelf)->setConnection('prefixed')->newQuery()->sort(['label' => 'desc', 'approved_volumes' => 'asc'], []);

        $this->assertSame(['Ann', 'Bob'], $query->get()->pluck('name')->all());
    }

    public function test_morph_relations_keep_to_their_own_type(): void
    {
        Schema::create('galleries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('clips', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('pictures', function (Blueprint $table) {
            $table->id();
            $table->morphs('imageable');
            $table->string('file');
            $table->timestamps();
        });
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('topicables', function (Blueprint $table) {
            $table->foreignId('topic_id');
            $table->morphs('topicable');
        });

        $first = Gallery::create(['name' => 'first']);
        $second = Gallery::create(['name' => 'second']);
        // The clip shares its id with the first gallery
        $clip = Clip::create(['name' => 'clip']);
        $this->assertSame($first->id, $clip->id);

        $first->pictures()->create(['file' => 'm.png']);
        $second->pictures()->create(['file' => 'k.png']);
        $clip->pictures()->create(['file' => 'a.png']);

        $first->topics()->create(['name' => 'mm']);
        $second->topics()->create(['name' => 'kk']);
        $clip->topics()->create(['name' => 'aa']);

        // Without the morph type the clip's "a.png" / "aa" would put the first gallery first
        $this->assertSame(['second', 'first'], Gallery::sort(['pictures' => 'asc'], [])->get()->pluck('name')->all());
        $this->assertSame(['second', 'first'], Gallery::sort(['topics' => 'asc'], [])->get()->pluck('name')->all());
    }

    public function test_morph_to_leaves_the_query_unsorted(): void
    {
        $sql = strtolower(Report::sort(['owner' => 'asc'], [])->toSql());

        $this->assertStringNotContainsString('order by', $sql);
    }

    public function test_from_and_to_cover_date_and_fractional_datetime_columns(): void
    {
        Schema::create('moments', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->dateTime('happened_at', 6);
        });

        Moment::create(['day' => '2024-01-10', 'happened_at' => '2024-01-10 23:59:59.500000']);
        Moment::create(['day' => '2024-01-11', 'happened_at' => '2024-01-11 00:00:00']);

        $ids = fn (array $filters) => Moment::filter($filters, [])->orderBy('moments.id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([1, 2], $ids(['day' => ['from' => '2024-01-10']]));
        $this->assertSame([1], $ids(['day' => ['to' => '2024-01-10']]));
        $this->assertSame([2], $ids(['day' => ['from' => '2024-01-11', 'to' => '2024-01-11']]));
        $this->assertSame([1], $ids(['happened_at' => ['to' => '2024-01-10']]));
        $this->assertSame([2], $ids(['happened_at' => ['from' => '2024-01-11T08:30:00+02:00']]));
        // Dates that do not exist are not shifted to other days
        $this->assertSame([1, 2], $ids(['day' => ['from' => '2024-02-30', 'to' => 'next monday']]));
    }

    public function test_filter_value_the_column_type_rejects_answers_422_or_matches_nothing(): void
    {
        Route::middleware('api')->get('api/books', [BookController::class, 'index']);

        $response = $this->getJson('/api/books?filter[price][eq]=abc');

        if (static::driver() === 'pgsql') {
            // invalid input syntax for type integer
            $response->assertStatus(422)->assertJsonPath('errors.filter.0', 'A filter value does not match the type of its column.');

            return;
        }

        $response->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_model_without_attributes_has_nothing_to_filter_or_sort(): void
    {
        $profile = new Profile;

        $this->assertFalse($profile->isFilterable(['admin']));
        $this->assertFalse($profile->isSortable(['admin']));
        $this->assertSame([], $profile->getAllowedFilters(['admin']));
        $this->assertSame([], $profile->getAllowedSorts(['admin']));
        $this->assertSame(2, Profile::filter(['nickname' => 'zeta'], [])->count());
        $this->assertStringNotContainsString('order by', strtolower(Profile::sort(['nickname' => 'asc'], [])->toSql()));
    }
}
