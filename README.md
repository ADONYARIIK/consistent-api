# Consistent API

Laravel toolkit for lean, consistent REST APIs: modular route structure, CRUD controller, filtering and sorting, pagination, JSON/multipart middleware, debug responses, and PostgreSQL ENUM helpers.

| Requirement | Version                       |
| ----------- | ----------------------------- |
| PHP         | `^8.1`                        |
| Laravel     | `^10` / `^11` / `^12` / `^13` |

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
  - [Modular structure](#modular-structure)
    - [Rate limiting](#rate-limiting)
  - [Artisan commands](#artisan-commands)
    - [`consistent:crud`](#consistentcrud)
    - [`consistent:rebuild`](#consistentrebuild)
  - [Models (`CrudModel`)](#models-crudmodel)
    - [Disable pagination for a model](#disable-pagination-for-a-model)
  - [CRUD controller](#crud-controller)
    - [Methods](#methods)
  - [Search, filters, and sorting](#search-filters-and-sorting)
    - [Request](#request)
    - [Example request](#example-request)
    - [Behaviour](#behaviour)
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
- `PgEnumServiceProvider` — PostgreSQL ENUM macros (migration context)

Publish the config files:

```bash
php artisan vendor:publish --tag=consistent-api-config
```

This creates:

- `config/consistentapi.php`
- `config/pagination.php`

---

## Configuration

### `config/consistentapi.php`

| Key                | Default                          | Description                                        |
| ------------------ | -------------------------------- | -------------------------------------------------- |
| `modules_folder`   | `Modules`                        | Modules directory relative to `app/`               |
| `request_limit`    | `60`                             | Max requests per minute for the `api` rate limiter |
| `api_url_prefix`   | `api`                            | URL prefix for module routes                       |
| `middlewares`      | `['api']`                        | Middleware stack applied to module routes          |
| `debugger_enabled` | `env('DEBUGGER_ENABLED', false)` | Enable the debug block in JSON responses           |

Example `.env`:

```env
DEBUGGER_ENABLED=true
```

### `config/pagination.php`

| Key                   | Default                                   | Description                    |
| --------------------- | ----------------------------------------- | ------------------------------ |
| `data_container_name` | `items`                                   | Data array key in the response |
| `meta_container_name` | `meta`                                    | Pagination metadata key        |
| `per_page`            | `sm/default/md/lg/xl` → `10/15/25/50/100` | Allowed `perpage` values       |

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
| ------------------------------------- | ---------------------------------------------- |
| `php artisan consistent:crud {model}` | Scaffold a full CRUD module                    |
| `php artisan consistent:rebuild`      | Move existing Laravel API classes into modules |

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

- Model extends `CrudModel` with empty `$fillable` / `$filter` / `$sort`
- Controller extends `CrudController` with `index` / `show` / `store` / `update` / `destroy`
- Search request extends `BaseSearchRequest`
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
- Moves matching route statements from `routes/api.php` into each module’s `Routes.php`

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

class Post extends CrudModel
{
    protected array $filter = ['title', 'body'];
    protected array $sort = ['id', 'created_at', 'title'];

    protected $fillable = ['title', 'body'];
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
| -------------- | --------------------------- | --------------------------------------------- |
| `indexLogic`   | List + filter/sort/paginate | `PaginatedJsonResponse` or non-paginated JSON |
| `selectLogic`  | Single record               | `JsonResource`                                |
| `storeLogic`   | Create                      | `201` + resource                              |
| `updateLogic`  | Update                      | `JsonResource`                                |
| `destroyLogic` | Delete                      | `204 No Content`                              |

Models passed into these methods must extend `CrudModel`.

---

## Search, filters, and sorting

### Request

Use `BaseSearchRequest` or extend it:

```php
use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;

class SearchPostRequest extends BaseSearchRequest
{
    // add extra rules if needed
}
```

Default rules:

| Parameter  | Rules                                              |
| ---------- | -------------------------------------------------- |
| `perpage`  | numeric value from `config('pagination.per_page')` |
| `paginate` | `true` / `false` / `0` / `1`                       |
| `sort`     | array                                              |
| `sort.*`   | `asc` or `desc`                                    |
| `filter`   | array                                              |
| `filter.*` | any nullable value                                 |

### Example request

```http
GET /api/posts?perpage=25&filter[title]=hello&sort[created_at]=desc
```

### Behaviour

- Filtering: `LIKE` / `ILIKE` (PostgreSQL) on columns allowed in `$filter`
- Sorting: `orderBy` on columns allowed in `$sort`
- Disallowed keys → `422` with Laravel-style validation errors
- If `filter` / `sort` is sent but the model is not filterable/sortable → `422`

Whitelist on the model:

```php
protected array $filter = ['title'];
protected array $sort = ['id', 'created_at'];
```

An empty array means filtering/sorting is disabled.

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

| Alias                         | Class                       | Purpose                                                 |
| ----------------------------- | --------------------------- | ------------------------------------------------------- |
| `consistent.api-json`         | `ApiJsonMiddleware`         | Sets `Accept: application/json` for API-prefixed URLs   |
| `consistent.ensure-json`      | `EnsureJsonMiddleware`      | Requires JSON Content-Type for `POST` / `PUT` / `PATCH` |
| `consistent.ensure-multipart` | `EnsureMultipartMiddleware` | Requires `multipart/form-data` for `POST`               |
| `consistent.debugger`         | `DebuggerMiddleware`        | Appends a `debugger` block to JSON responses            |

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
DB::pgsqlCreateEnumType('post_status', ['draft', 'published', 'archived']);

DB::pgsqlChangeEnum('posts', 'status', 'post_status');

DB::pgsqlAlterEnumValues('post_status', ['draft', 'published', 'archived', 'deleted']);

DB::pgsqlChangeEnumWithDefault('posts', 'status', 'post_status', ['draft', 'published'], 'draft');

DB::pgsqlDropEnumType('post_status');
```

### Blueprint macros

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->pgsqlCreateEnum('status', 'post_status', ['draft', 'published']);
    // or, if the type already exists:
    // $table->pgsqlEnum('status', 'post_status');
    $table->pgsqlSetEnumDefault('status', 'post_status', 'draft');
    $table->timestamps();
});
```

Failures throw `Adonyarik\ConsistentApi\Exceptions\PgEnumException`.

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

Assert that a related model “belongs” to the current one (matching IDs):

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
│   └── pagination.php
├── stubs/
│   └── crud/
└── src/
    ├── ConsistentApiProvider.php
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
    │   └── PgEnumException.php
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
    │   └── PgEnumServiceProvider.php
    ├── Requests/
    │   └── BaseSearchRequest.php
    ├── Responses/
    │   └── PaginatedJsonResponse.php
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
4. Fill in `$fillable` / `$filter` / `$sort` and request validation rules
5. Optionally add middleware aliases to your `api` group
6. For debugging: `DEBUGGER_ENABLED=true` + `consistent.debugger`

---

## License

MIT © Yaroslav Tyrchenko
