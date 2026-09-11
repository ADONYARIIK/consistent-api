# Consistent API

Laravel toolkit for lean, consistent REST APIs: modular route structure, CRUD controller, advanced filtering (including relations) and sorting (including relations), pagination, JSON/multipart middleware, debug responses, and PostgreSQL ENUM helpers.

| Requirement | Version                       |
| ----------- | ------------------------------ |
| PHP         | `^8.1`                         |
| Laravel     | `^10` / `^11` / `^12` / `^13`  |

Package: `adonyarik/consistent-api`
Namespace: `Adonyarik\ConsistentApi`

---

## Table of contents

- [Consistent API](#consistent-api)
  - [Table of contents](#table-of-contents)
  - [Installation](#installation)
  - [Configuration](#configuration)
    - [`config/consistentapi.php`](#configconsistentapiphp)
    - [`config/pagination.php`](#configpaginationphp)
    - [`config/filters.php`](#configfiltersphp)
  - [Modular structure](#modular-structure)
    - [Rate limiting](#rate-limiting)
  - [Artisan commands](#artisan-commands)
    - [`consistent:crud`](#consistentcrud)
    - [`consistent:rebuild`](#consistentrebuild)
  - [Models (`CrudModel`)](#models-crudmodel)
    - [Disable pagination for a model](#disable-pagination-for-a-model)
  - [CRUD controller](#crud-controller)
    - [Methods](#methods)
  - [Filtering](#filtering)
    - [Simple columns](#simple-columns)
    - [Nested operators](#nested-operators)
    - [Filtering through relations](#filtering-through-relations)
    - [Validation of filter input](#validation-of-filter-input)
  - [Sorting](#sorting)
    - [Simple columns](#simple-columns-1)
    - [Sorting through relations](#sorting-through-relations)
  - [Search request (`BaseSearchRequest`)](#search-request-basesearchrequest)
  - [Startup validation of `#[Filterable]` / `#[Sortable]`](#startup-validation-of-filterable--sortable)
  - [Pagination and responses](#pagination-and-responses)
  - [Middleware](#middleware)
    - [Example usage](#example-usage)
  - [Debugger](#debugger)
  - [Route macro `development`](#route-macro-development)
  - [PostgreSQL ENUM](#postgresql-enum)
    - [DB macros](#db-macros)
    - [Blueprint macros](#blueprint-macros)
  - [Extra traits](#extra-traits)
    - [`EnumHelpers`](#enumhelpers)
    - [`Credibility`](#credibility)
  - [Package structure](#package-structure)
  - [Quick start checklist](#quick-start-checklist)
  - [License](#license)

---

## Installation

```bash
composer require adonyarik/consistent-api
```

The service provider is registered via Laravel package discovery:

- `Adonyarik\ConsistentApi\ConsistentApiProvider`

It automatically boots:

- `ModuleServiceProvider` — module routes and the `api` rate limiter
- `MacroServiceProvider` — `Route::development()`
- `PostgresEnumServiceProvider` — PostgreSQL ENUM macros (migration/test context)

Publish the config files:

```bash
php artisan vendor:publish --tag=consistent-api-config
```

This creates:

- `config/consistentapi.php`
- `config/pagination.php`
- `config/filters.php`

---

## Configuration

### `config/consistentapi.php`

| Key                | Default                          | Description                                        |
| ------------------ | --------------------------------- | ----------------------------------------------------- |
| `modules_folder`   | `Modules`                          | Modules directory relative to `app/`                |
| `request_limit`    | `60`                               | Max requests per minute for the `api` rate limiter  |
| `api_url_prefix`   | `api`                              | URL prefix for module routes                        |
| `middlewares`      | `['api']`                          | Middleware stack applied to module routes           |
| `debugger_enabled` | `env('DEBUGGER_ENABLED', false)`  | Enable the debug block in JSON responses            |

Example `.env`:

```env
DEBUGGER_ENABLED=true
```

### `config/pagination.php`

| Key                    | Default                                    | Description                     |
| ---------------------- | -------------------------------------------- | ---------------------------------- |
| `data_container_name`  | `items`                                     | Data array key in the response   |
| `meta_container_name`  | `meta`                                      | Pagination metadata key          |
| `per_page`             | `sm/default/md/lg/xl` → `10/15/25/50/100`   | Allowed `perpage` values         |

### `config/filters.php`

| Key                 | Default                                                  | Description                                                                                                       |
| ------------------- | ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `operators`         | `['eq', 'like', 'from', 'to', 'in']`                        | Global whitelist of nested filter operators the package will accept. Uncomment `not_eq` / `min` / `max` / `not_in` / `null` to enable them. |
| `validate_on_boot`  | `env('FILTERS_VALIDATE_ON_BOOT', true)`                     | Scan models on boot and throw if `#[Filterable]` declares an operator that isn't enabled above, or a broken relation. |
| `model_paths`       | `[app_path('Models'), app_path('Modules/*/Models')]`        | Directories scanned for models during startup validation. Supports a single `*` wildcard segment (e.g. modular apps). |

An operator listed in `operators` must also be implemented in `CanFilter::applyArrayFilter()`, otherwise it is silently ignored at the trait level even if it passes validation.

Startup validation only runs in `local` / `testing` environments by default (see [Startup validation](#startup-validation-of-filterable--sortable)); it never runs on every production request, so scanning the filesystem is not a performance concern there.

---

## Modular structure

The package loads modules from `app/{modules_folder}` (default: `app/Modules`).

Example layout:

```text
app/Modules/
├── Routes.php              # optional global API routes
├── Users/
│   ├── Controllers/
│   ├── Models/
│   ├── Requests/
│   ├── Resources/
│   └── Routes.php
└── Posts/
    ├── Controllers/
    ├── Models/
    ├── Requests/
    ├── Resources/
    └── Routes.php
```

Module folders use the **plural** StudlyCase name of the model (`Post` → `Posts`, `Company` → `Companies`).

Each module `Routes.php` (and the optional root `Routes.php`) is loaded with:

- prefix from `consistentapi.api_url_prefix` (e.g. `api`)
- middleware from `consistentapi.middlewares` (e.g. `api`)

A folder named `Middleware` inside the modules directory is skipped.

If the modules directory does not exist, the provider **does not fail** — routes are simply not loaded.

### Rate limiting

On boot, the package registers a limiter named `api`:

```php
Limit::perMinute(config('consistentapi.request_limit'))
    ->by($request->user()?->id ?: $request->ip());
```

This may override your application's default `api` limiter. Adjust `request_limit`, or redefine the limiter in your `AppServiceProvider` / `bootstrap/app.php` if needed.

---

## Artisan commands

Both commands use the `consistent:{action}` naming format.

| Command                               | Purpose                                        |
| -------------------------------------- | ------------------------------------------------ |
| `php artisan consistent:crud {model}`  | Scaffold a full CRUD module                     |
| `php artisan consistent:rebuild`       | Move existing Laravel API classes into modules  |

### `consistent:crud`

Creates a ready-to-extend module for the given model name:

```bash
php artisan consistent:crud Post
php artisan consistent:crud Post --force
```

Generated layout for `Post`:

```text
app/Modules/Posts/
├── Controllers/PostController.php
├── Models/Post.php
├── Requests/SearchPostRequest.php
├── Requests/StorePostRequest.php
├── Requests/UpdatePostRequest.php
├── Resources/PostResource.php
└── Routes.php
```

- Model extends `CrudModel` with empty `#[Fillable([])]` / `#[Hidden([])]` / `#[Filterable([])]` / `#[Sortable([])]` declarations (Laravel 13+ for Eloquent attributes)
- Controller extends `CrudController` with `index` / `show` / `store` / `update` / `destroy`
- Search request extends `BaseSearchRequest` and points `$model` at the generated model, so relation/operator validation rules are built automatically
- `Routes.php` registers REST routes under the plural URI (`posts`) with `{post}` route-model binding
- Without `--force`, existing target files cause the command to fail

### `consistent:rebuild`

Migrates a conventional Laravel layout into the same plural module structure:

- Models from `app/Models`
- Controllers from `app/Http/Controllers` (and `app/Controllers`)
- Requests from `app/Http/Requests` (and `app/Requests`)
- Resources from `app/Http/Resources` (and `app/Resources`)

Also:

- Rewrites namespaces and class references under `app/`, `routes/`, `database/`, and `tests/`
- Moves matching route statements from `routes/api.php` into each module's `Routes.php`

Example:

```bash
php artisan consistent:rebuild
```

`Post` + `PostController` become `App\Modules\Posts\Models\Post` and `App\Modules\Posts\Controllers\PostController`.

---

## Models (`CrudModel`)

Base API model:

```php
use Adonyarik\ConsistentApi\Models\CrudModel;
use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Attributes\Sortable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;

#[Fillable(['title', 'body'])]
#[Hidden([])]
#[Filterable(['title', 'body'])]
#[Sortable(['id', 'created_at', 'title'])]
class Post extends CrudModel
{
}
```

Features:

- `CanFilter` and `CanSort` traits
- `HasFactory` with lookup for `Database\Factories\{Model}Factory`
- `leftJoinOnce()` — left join without duplicates
- `getAllColumns()` — table column listing

### Disable pagination for a model

Implement the contract:

```php
use Adonyarik\ConsistentApi\Contracts\WithoutPaginationModelContract;

class Setting extends CrudModel implements WithoutPaginationModelContract
{
    // ...
}
```

Then, with `paginate=false` (or `0`), `indexLogic` returns the full list without pagination meta.

---

## CRUD controller

Extend `Adonyarik\ConsistentApi\Controllers\CrudController` and set:

- `$resourceClass` — API Resource class
- `$relationFunctions` — relations for `with` / `load` (optional)

```php
namespace App\Modules\Posts\Controllers;

use Adonyarik\ConsistentApi\Controllers\CrudController;
use App\Modules\Posts\Models\Post;
use App\Modules\Posts\Requests\SearchPostRequest;
use App\Modules\Posts\Requests\StorePostRequest;
use App\Modules\Posts\Requests\UpdatePostRequest;
use App\Modules\Posts\Resources\PostResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class PostController extends CrudController
{
    protected string $resourceClass = PostResource::class;

    protected array $relationFunctions = ['author'];

    public function index(SearchPostRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Post());
    }

    public function show(Post $post): JsonResource
    {
        return $this->selectLogic($post);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        return $this->storeLogic($request, new Post());
    }

    public function update(UpdatePostRequest $request, Post $post): JsonResource
    {
        return $this->updateLogic($request, $post);
    }

    public function destroy(Post $post): JsonResponse
    {
        return $this->destroyLogic($post);
    }
}
```

### Methods

| Method         | Purpose                     | Response                                      |
| --------------- | ----------------------------- | ------------------------------------------------ |
| `indexLogic`    | List + filter/sort/paginate  | `PaginatedJsonResponse` or non-paginated JSON   |
| `selectLogic`   | Single record                | `JsonResource`                                  |
| `storeLogic`    | Create                       | `201` + resource                                |
| `updateLogic`   | Update                       | `JsonResource`                                  |
| `destroyLogic`  | Delete                       | `204 No Content`                                |

Models passed into these methods must extend `CrudModel`.

`indexLogic` validates `filter`/`sort` against the model's `#[Filterable]` / `#[Sortable]` declarations *before* the query is built. Any disallowed column, disallowed nested operator, or nesting on a column that doesn't support it returns a `422` with Laravel-style validation errors — the query is never executed with unverified input.

---

## Filtering

Declare allowed columns on the model with `#[Filterable]`:

```php
use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Support\RelationFilter;

#[Filterable([
    'name',
    'description',
    'price' => [
        'min' => ['numeric', 'nullable', 'min:0'],
        'max' => ['numeric', 'nullable', 'min:0', 'gte:filter.price.min'],
    ],
    'created_at' => [
        'from' => ['date', 'nullable'],
        'to'   => ['date', 'nullable', 'after_or_equal:filter.created_at.from'],
    ],
    'user' => new RelationFilter(relation: 'user', column: 'name'),
])]
class Recipe extends CrudModel { /* ... */ }
```

### Simple columns

A plain string (`'name'`) allows only scalar filtering, applied as `LIKE '%value%'` (`ILIKE` on PostgreSQL):

```http
GET /api/recipes?filter[name]=chocolate
```

### Nested operators

A column can be declared with an array of allowed operators, optionally paired with validation rules for that operator's value:

```php
'price' => [
    'min' => ['numeric', 'nullable', 'min:0'],
    'max' => ['numeric', 'nullable', 'min:0', 'gte:filter.price.min'],
],
```

```http
GET /api/recipes?filter[price][min]=10&filter[price][max]=50
GET /api/recipes?filter[created_at][from]=2024-01-01&filter[created_at][to]=2024-12-31
```

Supported operators (must also be enabled in `config('filters.operators')`):

| Operator  | Behaviour                                     |
| --------- | ----------------------------------------------- |
| `eq`      | `column = value`                                |
| `not_eq`  | `column != value`                               |
| `like`    | `column LIKE/ILIKE '%value%'`                   |
| `from`    | `column >= start of day(value)` (date-aware)    |
| `to`      | `column <= end of day(value)` (date-aware)      |
| `min`     | `column >= value`                               |
| `max`     | `column <= value`                               |
| `in`      | `column IN (values)` — comma string or array    |
| `not_in`  | `column NOT IN (values)` — comma string or array|
| `null`    | `whereNull` / `whereNotNull` based on boolean value |

A plain list of scalar values without operator keys is treated as `in`:

```http
GET /api/recipes?filter[status][]=draft&filter[status][]=published
```

Attempting to nest a column that was declared as a plain string, or using an operator that isn't in its declared list, returns a `422`.

### Filtering through relations

Use `RelationFilter` for columns backed by a foreign key or a relationship, instead of the raw column:

```php
use Adonyarik\ConsistentApi\Support\RelationFilter;

#[Filterable([
    'user'        => new RelationFilter(relation: 'user', column: 'name'),
    'categories'  => new RelationFilter(relation: 'categories', column: 'name'),
    'ingredients' => new RelationFilter(relation: 'ingredients', column: 'name', operator: 'eq'),
])]
```

```php
public function user(): BelongsTo { return $this->belongsTo(User::class); }
public function categories(): BelongsToMany { return $this->belongsToMany(Category::class, 'recipe_categories'); }
```

```http
GET /api/recipes?filter[user]=John
GET /api/recipes?filter[categories]=Dessert
```

Internally this runs `whereHas($relation, ...)` matching `$column` with `LIKE`/`ILIKE` (default) or `=` when `operator: 'eq'` is set — this works for any relation type (`BelongsTo`, `HasMany`, `BelongsToMany`, etc.) without duplicating rows. Nested operators (`filter[user][eq]=...`) on relation columns are not yet supported and return a `422`.

### Validation of filter input

`BaseSearchRequest` automatically builds validation rules from the model's `#[Filterable]`:

- Any operator not enabled in `config('filters.operators')` → `422`.
- Any per-operator rules declared on the model (e.g. `numeric`, `date`, `gte:filter.price.min`) are applied to the corresponding `filter.column.operator` input.
- Columns declared as `RelationFilter` are excluded from value-rule generation (there is no scalar value shape to validate beyond the base type checks).

---

## Sorting

Declare allowed columns on the model with `#[Sortable]`:

```php
use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Support\RelationSort;

#[Sortable([
    'name',
    'created_at',
    'user'        => new RelationSort(relation: 'user', column: 'name'),
    'categories'  => new RelationSort(relation: 'categories', column: 'name'),
])]
class Recipe extends CrudModel { /* ... */ }
```

### Simple columns

```http
GET /api/recipes?sort[created_at]=desc
```

### Sorting through relations

`RelationSort` inspects the relationship type and picks the right strategy automatically:

- **`BelongsTo` / `HasOne`** — a single related record, resolved with a `leftJoinOnce()` join, then `ORDER BY {related_table}.{column}`.
- **`BelongsToMany` / `HasMany`** — potentially multiple related records. To avoid row duplication from a `JOIN`, the values are aggregated into a single comma-separated string per parent row via a correlated subquery (`STRING_AGG` on PostgreSQL, `GROUP_CONCAT` on MySQL), and the main query orders by that subquery — no join on the outer query at all.

```http
GET /api/recipes?sort[user]=asc
GET /api/recipes?sort[categories]=asc
```

Sorting on a relation that isn't `BelongsTo` / `HasOne` / `BelongsToMany` / `HasMany` is silently ignored.

> **MySQL note:** `GROUP_CONCAT` truncates at `group_concat_max_len` (1024 bytes by default). If a parent row can have many related values, consider raising this session/server variable.

---

## Search request (`BaseSearchRequest`)

```php
use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;
use App\Modules\Posts\Models\Post;

class SearchPostRequest extends BaseSearchRequest
{
    protected string $model = Post::class;
}
```

Setting `$model` lets `BaseSearchRequest` build the `filter.*` value-validation rules straight from that model's `#[Filterable]` declaration (see [Validation of filter input](#validation-of-filter-input)). This is done automatically by the `consistent:crud` stub.

Base rules (always applied, regardless of `$model`):

| Parameter   | Rules                                                |
| ----------- | ------------------------------------------------------ |
| `perpage`   | numeric value from `config('pagination.per_page')`     |
| `paginate`  | `true` / `false` / `0` / `1`                            |
| `sort`      | array                                                   |
| `sort.*`    | `asc` or `desc`                                         |
| `filter`    | array                                                   |
| `filter.*`  | nullable; if an array, its keys must all be enabled in `config('filters.operators')` |

Example request:

```http
GET /api/posts?perpage=25&filter[title]=hello&sort[created_at]=desc
```

Behaviour at the controller level:

- Filtering: `LIKE` / `ILIKE` (PostgreSQL) on columns allowed by `#[Filterable]`, or the operator/relation logic described above
- Sorting: `orderBy` on columns allowed by `#[Sortable]`, or the relation logic described above
- Disallowed columns, disallowed nested operators, or filter/sort on a non-filterable/non-sortable model → `422` with Laravel-style validation errors

Simple array-based whitelisting (without the attribute) still works:

```php
protected array $filter = ['title'];
protected array $sort = ['id', 'created_at'];
```

An empty array means filtering/sorting is disabled.

---

## Startup validation of `#[Filterable]` / `#[Sortable]`

When `config('filters.validate_on_boot')` is `true` **and** the app is running in `local` or `testing`, `ConsistentApiProvider::boot()` scans every model under `config('filters.model_paths')` and eagerly checks:

- Every operator declared in a `#[Filterable]` column exists in `config('filters.operators')` → otherwise `InvalidFilterableOperatorException`.
- Every `RelationFilter` points to a method that exists on the model and actually returns an Eloquent `Relation` → otherwise `InvalidRelationFilterException`.

This catches typos (`min` used but not enabled in config, `relation: 'usre'`, pointing a `RelationFilter` at a non-relation method) at boot time instead of on the first matching request.

`model_paths` supports a single `*` wildcard segment, which makes it work with both conventional (`app/Models`) and modular (`app/Modules/*/Models`) project layouts out of the box.

Disable this check entirely (e.g. to skip the filesystem scan) with:

```env
FILTERS_VALIDATE_ON_BOOT=false
```

---

## Pagination and responses

`PaginatedJsonResponse` produces JSON like:

```json
{
  "items": [ /* resource collection */ ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "from": 1,
    "to": 15,
    "total": 42,
    "per_page": 15,
    "path": "http://localhost/api/posts"
  }
}
```

The `items` / `meta` keys are configurable in `config/pagination.php`.

Without pagination (contract + `paginate=false`):

```json
{
  "items": [ /* ... */ ]
}
```

---

## Middleware

Aliases are registered automatically:

| Alias                          | Class                        | Purpose                                                  |
| -------------------------------- | ------------------------------ | ----------------------------------------------------------- |
| `consistent.api-json`          | `ApiJsonMiddleware`          | Sets `Accept: application/json` for API-prefixed URLs    |
| `consistent.ensure-json`       | `EnsureJsonMiddleware`       | Requires JSON Content-Type for `POST` / `PUT` / `PATCH`  |
| `consistent.ensure-multipart`  | `EnsureMultipartMiddleware`  | Requires `multipart/form-data` for `POST`                |
| `consistent.debugger`          | `DebuggerMiddleware`         | Appends a `debugger` block to JSON responses             |

### Example usage

Laravel 11+:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('api', [
        \Adonyarik\ConsistentApi\Middleware\ApiJsonMiddleware::class,
    ]);
})
```

Or in routes:

```php
Route::middleware(['consistent.ensure-json'])->group(function () {
    // ...
});

Route::post('/files', UploadController::class)
    ->middleware('consistent.ensure-multipart');
```

Attach `EnsureMultipartMiddleware` only to upload endpoints: any non-POST request or missing multipart Content-Type returns `415`.

---

## Debugger

1. Set `DEBUGGER_ENABLED=true`
2. Apply the `consistent.debugger` middleware to the routes/group you need

JSON responses will include:

```json
{
  "items": [],
  "meta": {},
  "debugger": {
    "id": "dbg_...",
    "datetime": "2026-09-05 21:00:00",
    "executionTime": 0.012,
    "method": "GET",
    "uri": "/api/posts",
    "clientIP": "127.0.0.1",
    "memoryUsage": "4.2 MB",
    "router": "App\\Modules\\Posts\\Controllers\\PostController@index",
    "inputs": {},
    "db": {
      "queryCount": 2,
      "list": [
        { "sql": "...", "bindings": [], "time": 0.5 }
      ]
    }
  }
}
```

Do not enable the debugger in production unless you intend to expose SQL, bindings, and request input.

---

## Route macro `development`

Routes available only in the `local` environment:

```php
use Illuminate\Support\Facades\Route;

Route::development(function () {
    Route::get('/api/_debug/ping', fn () => ['ok' => true]);
});
```

In `production` / `staging` the callback is not executed.

---

## PostgreSQL ENUM

Macros are active during migrations (`artisan migrate*`) and tests (`pest` / `phpunit`).

### DB macros

```php
DB::pgEnumCreate('post_status', ['draft', 'published', 'archived']);

DB::pgEnumConvertColumn('posts', 'status', 'post_status');

DB::pgEnumRedefine('post_status', ['draft', 'published', 'archived', 'deleted']);

DB::pgEnumRedefineWithDefault('posts', 'status', 'post_status', ['draft', 'published'], 'draft');

DB::pgEnumDrop('post_status');
```

### Blueprint macros

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->pgEnumColumnNew('status', 'post_status', ['draft', 'published']);
    // or, if the type already exists:
    // $table->pgEnumColumn('status', 'post_status');
    $table->pgEnumDefault('status', 'post_status', 'draft');
    $table->timestamps();
});
```

Failures throw `Adonyarik\ConsistentApi\Exceptions\PostgresEnumException` (e.g. `typeAlreadyExists`, `typeMissing`, `columnHasInvalidValues`, `valueNotAllowed`, `typeStillReferenced`, `typeSharedAcrossTables`).

---

## Extra traits

### `EnumHelpers`

For PHP backed enums:

```php
use Adonyarik\ConsistentApi\Traits\EnumHelpers;

enum PostStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case Published = 'published';
}

PostStatus::names();   // ['Draft', 'Published']
PostStatus::values();  // ['draft', 'published']
PostStatus::toArray(); // ['Draft' => 'draft', ...]
```

### `Credibility`

Assert that a related model "belongs" to the current one (matching IDs):

```php
use Adonyarik\ConsistentApi\Traits\Credibility;

class Comment extends CrudModel
{
    use Credibility;

    public function ensurePost(Post $post): void
    {
        $this->checkModelCredibility($post, 'post_id'); // 404 on mismatch
    }
}
```

---

## Package structure

```text
consistent-api/
├── composer.json
├── config/
│   ├── consistentapi.php
│   ├── pagination.php
│   └── filters.php
├── stubs/
│   └── crud/
└── src/
    ├── ConsistentApiProvider.php
    ├── Attributes/
    │   ├── Filterable.php
    │   └── Sortable.php
    ├── Console/
    │   └── Commands/
    │       ├── CreateCrudCommand.php
    │       └── RebuildCommand.php
    ├── Contracts/
    │   └── WithoutPaginationModelContract.php
    ├── Controllers/
    │   ├── Controller.php
    │   └── CrudController.php
    ├── Exceptions/
    │   ├── InvalidFilterableOperatorException.php
    │   ├── InvalidRelationFilterException.php
    │   └── PostgresEnumException.php
    ├── Middleware/
    │   ├── ApiJsonMiddleware.php
    │   ├── DebuggerMiddleware.php
    │   ├── EnsureJsonMiddleware.php
    │   └── EnsureMultipartMiddleware.php
    ├── Models/
    │   └── CrudModel.php
    ├── Providers/
    │   ├── DebuggerServiceProvider.php  # debug service (not a Laravel SP)
    │   ├── MacroServiceProvider.php
    │   ├── ModuleServiceProvider.php
    │   └── PostgresEnumServiceProvider.php
    ├── Requests/
    │   └── BaseSearchRequest.php
    ├── Responses/
    │   └── PaginatedJsonResponse.php
    ├── Support/
    │   ├── FilterableValidator.php
    │   ├── PostgresEnumManager.php
    │   ├── RelationFilter.php
    │   └── RelationSort.php
    └── Traits/
        ├── CanFilter.php
        ├── CanSort.php
        ├── Credibility.php
        └── EnumHelpers.php
```

---

## Quick start checklist

1. `composer require adonyarik/consistent-api`
2. `php artisan vendor:publish --tag=consistent-api-config`
3. `php artisan consistent:crud Post` (or `consistent:rebuild` for an existing app)
4. Fill in `#[Fillable(...)]` / `#[Hidden(...)]` / `#[Filterable(...)]` / `#[Sortable(...)]` and request validation rules (Eloquent attributes require Laravel 13+)
5. For foreign-key/relation columns, use `RelationFilter` / `RelationSort` instead of plain column names
6. Enable any extra nested operators you need in `config/filters.php`
7. Optionally add middleware aliases to your `api` group
8. For debugging: `DEBUGGER_ENABLED=true` + `consistent.debugger`

---

## License

MIT © Yaroslav Tyrchenko