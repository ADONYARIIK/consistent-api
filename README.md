# Consistent API

Laravel toolkit for lean, consistent REST APIs: a module generator, modular route structure, a base CRUD controller with per-action resources, advanced filtering and sorting (including relations) with per-access column restrictions, pagination and JSON/multipart middleware.

| Requirement | Version   |
| ----------- | --------- |
| PHP         | `^8.3`    |
| Laravel     | `^13.30`  |

The package is built on the Eloquent attributes and application structure of Laravel 13 and does not support earlier Laravel versions. Check your own dependency tree for security advisories with `composer audit`.

Package: `adonyarik/consistent-api`
Namespace: `Adonyarik\ConsistentApi`

---

## Table of contents

- [Consistent API](#consistent-api)
  - [Table of contents](#table-of-contents)
  - [Installation](#installation)
  - [Configuration](#configuration)
    - [General settings](#general-settings)
    - [Pagination (`consistentapi.pagination`)](#pagination-consistentapipagination)
    - [Filters (`consistentapi.filters`)](#filters-consistentapifilters)
    - [Access groups (`consistentapi.accesses`)](#access-groups-consistentapiaccesses)
  - [Modular structure](#modular-structure)
    - [Rate limiting](#rate-limiting)
  - [Artisan commands](#artisan-commands)
    - [`consistent:create`](#consistentcreate)
    - [`consistent:rebuild`](#consistentrebuild)
      - [What `consistent:rebuild` does not do](#what-consistentrebuild-does-not-do)
  - [Models (`ConsistentModel`)](#models-consistentmodel)
    - [Joining a table once](#joining-a-table-once)
    - [Disable pagination for a model](#disable-pagination-for-a-model)
  - [Controller (`ConsistentController`)](#controller-consistentcontroller)
    - [Methods](#methods)
    - [Authorization](#authorization)
    - [Resource per action (`$resourceClasses`)](#resource-per-action-resourceclasses)
  - [Filtering](#filtering)
    - [Simple columns](#simple-columns)
    - [Nested operators](#nested-operators)
    - [Filtering through relations](#filtering-through-relations)
    - [Restricting columns by access](#restricting-columns-by-access)
    - [How input reaches the query](#how-input-reaches-the-query)
    - [Validation of filter input](#validation-of-filter-input)
  - [Sorting](#sorting)
    - [Simple columns](#simple-columns-1)
    - [Sorting through relations](#sorting-through-relations)
    - [Restricting columns by access](#restricting-columns-by-access-1)
  - [Search request (`BaseSearchRequest`)](#search-request-basesearchrequest)
  - [Startup validation of `#[Filterable]` / `#[Sortable]`](#startup-validation-of-filterable--sortable)
  - [Pagination and responses](#pagination-and-responses)
  - [Middleware](#middleware)
    - [JSON for API URLs](#json-for-api-urls)
    - [Example usage](#example-usage)
  - [Route macro `development`](#route-macro-development)
  - [Extra traits](#extra-traits)
    - [`Credibility`](#credibility)
  - [AI agent skills (Laravel Boost)](#ai-agent-skills-laravel-boost)
    - [Adding the skills to a project](#adding-the-skills-to-a-project)
  - [Package structure](#package-structure)
  - [Development](#development)
  - [Quick start checklist](#quick-start-checklist)
  - [Related packages](#related-packages)
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

Publish the config file:

```bash
php artisan vendor:publish --tag=consistent-api-config
```

This creates `config/consistentapi.php`. Every setting of the package lives under the `consistentapi` key, so the package never touches config files or keys of the application or of other packages.

> **Resources lose the `data` wrapper application-wide.** The package calls `JsonResource::withoutWrapping()` while booting, so *every* API resource of the application — including resources that have nothing to do with this package and resources of other packages — is returned without the `data` key. For an existing API this changes the responses its clients see. Set `consistentapi.without_wrapping` to `false` to keep Laravel's wrapping.

---

## Configuration

### General settings

| Key                    | Default     | Description                                        |
| ---------------------- | ----------- | -------------------------------------------------- |
| `modules_folder`       | `Modules`   | Modules directory relative to `app/`. Every segment must be a PHP identifier (`Modules`, `Domain/Modules`); backslashes count as slashes. An invalid value (empty, `..`, `my-modules`) is an error |
| `request_limit`        | `60`        | Max requests per minute for the `api` rate limiter  |
| `api_url_prefix`       | `api`       | URL prefix for module routes, also matched by `ApiJsonMiddleware` |
| `middlewares`          | `['api']`   | Middleware stack applied to module routes           |
| `api_json_enabled`     | `true`      | Prepends `ApiJsonMiddleware` to the global middleware; `false` leaves it to you |
| `without_wrapping`     | `true`      | Calls `JsonResource::withoutWrapping()` for the whole application; `false` keeps the `data` wrapper |
| `hide_model_not_found` | `true`      | With `app.debug` off, a missing route-bound model under the API prefix answers `{"message": "Not found."}` instead of naming its class |
| `uuid`                 | `true`      | Allows `consistent:create --uuid`; with `false` the flag is an error |
| `uuid_column`          | `public_id` | UUID column that `--uuid` modules use as the route key |

The namespace of the modules follows the application namespace: with `"Acme\\": "app/"` in `composer.json`, modules live in `Acme\Modules\…`.

A published file is merged with the package defaults key by key, at the top level and inside the `pagination` and `filters` sections, so a key missing from the published file gets its default. Lists (`operators`, `per_page`, `middlewares`) and `accesses` are taken from the published file as a whole.

### Pagination (`consistentapi.pagination`)

| Key                    | Default                  | Description                     |
| ---------------------- | ------------------------ | ------------------------------- |
| `data_container_name`  | `items`                  | Data array key in the response   |
| `meta_container_name`  | `meta`                   | Pagination metadata key          |
| `per_page`             | `[10, 15, 25, 50, 100]`  | Values a request may pass as `perpage`. Without `perpage` the page size is the model's `$perPage` (`10` for `ConsistentModel`) |
| `unpaginated_limit`    | `1000`                   | Most rows `paginate=false` returns for a model with `WithoutPaginationModelContract`; `null` removes the limit |

### Filters (`consistentapi.filters`)

| Key                 | Default                                                  | Description                                                                                                       |
| ------------------- | ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `operators`         | `['eq', 'like', 'from', 'to', 'in']`                        | Global whitelist of nested filter operators. Uncomment `not_eq` / `min` / `max` / `not_in` / `null` to enable them. Both the search request and the controller reject the others, whatever the model declares. |
| `validate_on_boot`  | `env('CONSISTENTAPI_VALIDATE_ON_BOOT', true)`               | Scan models on boot and throw on invalid `#[Filterable]` / `#[Sortable]` declarations (see [Startup validation](#startup-validation-of-filterable--sortable)). |
| `model_paths`       | `[app_path('Models'), app_path('Modules/*/Models')]`        | Directories scanned for models during startup validation. Paths may contain `*` wildcards (e.g. modular apps). |

Startup validation runs only in `local` / `testing` environments (see [Startup validation](#startup-validation-of-filterable--sortable)); it never runs on production requests, so scanning the filesystem is not a performance concern there.

`model_paths` does not follow `consistentapi.modules_folder`: if you rename the modules folder, update the second path too.

### Access groups (`consistentapi.accesses`)

Access groups give names to sets of route middleware. The package ships the section with commented-out examples; publish the config and declare your own groups:

```php
// config/consistentapi.php
'accesses' => [
    'guest' => [],
    'unverified' => ['auth:sanctum'],
    'verified' => ['auth:sanctum', 'verified'],
    'admin' => ['auth:sanctum', 'verified', 'can:admin'],
],
```

Group names may contain letters, digits and `_ . : -`.

Groups are used in two places:

- `consistent:create --accesses=delete-admin` writes the group's middleware into the module's `Routes.php` (see [Access groups on routes](#access-groups-on-routes));
- `#[Filterable(..., accesses: ['admin'])]` / `#[Sortable(..., accesses: ['admin'])]` make columns available only to requests that have the access (see [Restricting columns by access](#restricting-columns-by-access)).

A request has access `admin` when its route runs **every** middleware of the `admin` group:

- Middleware are compared after resolving aliases and middleware groups, so an alias and the class it points to are the same middleware, and a group may list a middleware group such as `api`.
- Extra middleware on the route do not matter; middleware removed with `withoutMiddleware()` do not count.
- Parameters must match exactly: `auth` does not satisfy `auth:sanctum`.
- Global middleware from the HTTP kernel are not route middleware and cannot grant an access.

Because the check is by middleware, hierarchies come for free: when `admin` contains every middleware of `verified`, an admin route also passes `verified` restrictions. An empty group (`guest`) is granted to every route.

To read the accesses in your own code, use `Adonyarik\ConsistentApi\Support\AccessResolver`: `app(AccessResolver::class)->current()` for the current route (`[]` outside an HTTP request), `->forRoute($route)` for any route.

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

The module files are loaded once the application has booted, after the routes `bootstrap/app.php` registers with `withRouting()`, in the alphabetical order of the module folders, so the order is the same on every machine. Route files of the application are loaded once, as without the package. When the routes are cached (`php artisan route:cache`), the cache already holds the module routes and the files are not read.

`routes/api.php` keeps the prefix and middleware `bootstrap/app.php` gives it; `consistentapi.api_url_prefix` applies to module routes only. Changing the prefix (to `v1`, say) therefore moves the module routes, while `routes/api.php` stays under `/api` — and no longer gets the JSON errors of `ApiJsonMiddleware`, which follows the configured prefix.

A folder named `Middleware` inside the modules directory is skipped.

If the modules directory does not exist, the provider **does not fail** — routes are simply not loaded. An invalid `modules_folder` (see [General settings](#general-settings)) throws an `InvalidArgumentException`.

### Rate limiting

On boot, the package defines a rate limiter named `api`:

```php
Limit::perMinute(config('consistentapi.request_limit'))
    ->by($request->user()?->id ?: $request->ip());
```

The limiter applies to routes that run `throttle:api`. The `api` middleware group gets it in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->throttleApi();
})
```

The last definition of a limiter name wins, and application providers boot after package providers: an `api` limiter defined in your `AppServiceProvider` replaces this one, and `request_limit` no longer applies.

---

## Artisan commands

Both commands use the `consistent:{action}` naming format.

| Command                                 | Purpose                                        |
| ---------------------------------------- | ------------------------------------------------ |
| `php artisan consistent:create {model}`  | Scaffold an API module                          |
| `php artisan consistent:rebuild`         | Move existing Laravel API classes into modules  |

### `consistent:create`

Creates a ready-to-extend module for the given model name. Options choose the routes, the access groups that protect them, the resources and the route key:

```bash
php artisan consistent:create Post
php artisan consistent:create Post --routes=get,post --resources=l,s
php artisan consistent:create Post --accesses=all-verified,delete-admin --uuid
php artisan consistent:create Post --force
```

#### Options

| Option        | Values                                                                 | Default    |
| ------------- | ------------------------------------------------------------------------ | ------------ |
| `--routes`    | `get`, `post`, `put`, `patch`, `delete`, `all`                          | `all`      |
| `--accesses`  | `method-access` pairs: a `--routes` value (or `all`) and a group from `consistentapi.accesses` | none       |
| `--resources` | `l` / `light`, `s` / `standard`, `a` / `admin`, `all`                   | `standard` |
| `--uuid`      | Use `consistentapi.uuid_column` as the route key                        | off        |
| `--force`     | Overwrite the files of an existing module                               | off        |

`--routes`, `--accesses` and `--resources` take comma-separated values or can be repeated (`--routes=get --routes=post`); `all` next to other values means all. In `--accesses` the method is case-insensitive, while the access is a config key and is taken as is; the entry is split at the first dash, so `delete-super-admin` means the group `super-admin`.

Each `--routes` value adds these controller methods, routes and form requests:

| `--routes`        | Controller method | Route                    | Form request        |
| ----------------- | ----------------- | ------------------------ | ------------------- |
| `get`             | `index`           | `GET posts`              | `SearchPostRequest` |
| `get`             | `show`            | `GET posts/{post}`       | —                   |
| `post`            | `store`           | `POST posts`             | `StorePostRequest`  |
| `put` / `patch`   | `update`          | `PUT\|PATCH posts/{post}` | `UpdatePostRequest` |
| `delete`          | `destroy`         | `DELETE posts/{post}`    | —                   |

With both `put` and `patch` (the default), `update` is registered once with `Route::match(['put', 'patch'], …)`; with only one of them, only that method is registered.

Each `--resources` value adds a resource:

| `--resources`      | Class               |
| ------------------ | ------------------- |
| `l` / `light`      | `PostLightResource` |
| `s` / `standard`   | `PostResource`      |
| `a` / `admin`      | `PostAdminResource` |

The generated resources form an inheritance chain from light to admin: each one extends the nearest lighter generated resource and adds its fields to `parent::toArray()`, and the lightest one extends `JsonResource`. With `--resources=all`, `PostAdminResource` extends `PostResource`, which extends `PostLightResource`; with `--resources=l,a`, `PostAdminResource` extends `PostLightResource` directly; a single resource extends `JsonResource`.

The controller's `$resourceClass` is the first generated of standard → light → admin. When both the light and the standard resource are generated and the module has `index`, the controller binds `index` to the light one in [`$resourceClasses`](#resource-per-action-resourceclasses). The admin resource is only created: bind it to your own action.

#### Generated files

Layout for `Post` without options:

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

- Model extends `ConsistentModel` and is declared with attributes only: `#[Table('posts')]` and empty `#[Fillable([])]` / `#[Hidden([])]` / `#[Filterable([])]` / `#[Sortable([])]`, so a model without `--uuid` has an empty body. The table is the snake_case plural (`blog_posts`)
- Controller extends `ConsistentController` with only the methods of the selected routes; the bound model is a camelCase variable (`BlogPost $blogPost`)
- Search request extends `BaseSearchRequest` and points `$model` at the generated model, so the value rules declared on its `#[Filterable]` operators apply automatically; store and update requests start with empty `rules()`
- The lightest resource returns `'id' => $this->id` (`$this->public_id` with `--uuid`); the others extend the nearest lighter one and add their fields to `parent::toArray()`
- `Routes.php` registers the routes under the plural kebab-case URI (`posts`, `blog-posts`) with route names such as `blog-posts.index`, a snake_case parameter (`{blog_post}`) constrained with `whereNumber()` (`whereUuid()` with `--uuid`). Acronyms stay one word: `APIKey` gives `api_keys`, `api-keys`, `{api_key}` and `$apiKey`
- The namespace follows the application namespace (`App\Modules\Posts`, or `Acme\Modules\Posts` when `composer.json` maps `app/` to `Acme\`)
- Generated files pass `php -l` and Pint with the package `pint.json`: names that would not (see [Option errors](#option-errors)) are rejected before anything is written
- Without `--force`, existing target files cause the command to fail. With `--force` the files are overwritten; files an earlier run generated and this one does not (a resource left out this time, say) are listed, not deleted
- The files are written all or nothing: when a write fails, the files written so far are put back and the command fails

#### Access groups on routes

`--accesses` puts the middleware of [access groups](#access-groups-consistentapiaccesses) on the generated routes:

- `all-…` applies to every selected route; methods missing from `--routes` are skipped.
- An entry for a specific method replaces `all` for that method, and several entries for one method are merged. `--accesses=all-admin,get-guest` keeps reads public (`guest` is an empty group) and puts everything else behind `admin`.
- `get` covers both `index` and `show`.
- A route gets the union of its groups' middleware, in config order, without duplicates.
- `put` and `patch` share `update()`, so they must end up with the same middleware.

Routes without middleware are written at the top level. The rest are grouped by identical middleware in order of first appearance, each group commented with the access names of all its routes. Example:

```bash
php artisan consistent:create Post --routes=get,post,patch,delete --accesses=all-verified,delete-admin --uuid
```

with

```php
// config/consistentapi.php, accesses section
'verified' => ['auth:sanctum', 'verified'],
'admin' => ['auth:sanctum', 'verified', 'can:admin'],
```

generates:

```php
<?php

declare(strict_types=1);

use App\Modules\Posts\Controllers\PostController;
use Illuminate\Support\Facades\Route;

// verified
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('posts/{post}', [PostController::class, 'show'])->whereUuid('post')->name('posts.show');
    Route::post('posts', [PostController::class, 'store'])->name('posts.store');
    Route::patch('posts/{post}', [PostController::class, 'update'])->whereUuid('post')->name('posts.update');
});

// admin
Route::middleware(['auth:sanctum', 'verified', 'can:admin'])->group(function () {
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->whereUuid('post')->name('posts.destroy');
});
```

#### UUID route keys

`--uuid` requires `consistentapi.uuid` to be `true` and uses the `consistentapi.uuid_column` column (`public_id` by default) as the route key:

- The model gets `#[RouteKey('public_id')]`, `use HasUuids;` and `uniqueIds()` returning `['public_id']`. The primary key stays an auto-incrementing integer; the UUID is filled in on insert.
- `{post}` parameters use `whereUuid()`, so a malformed UUID in the URL is a `404`.
- Resources return `'id' => $this->public_id`.

The column name is written into the generated code, so changing `uuid_column` later does not affect existing modules. The command does not create migrations; it prints the column to add:

```php
$table->uuid('public_id')->unique();
```

#### Option errors

The command collects every problem with the options and prints them together; if there is any, no file is written:

- the model name is not a valid StudlyCase class name, is a reserved word of PHP (`List`, `Class`, `Match`, `Int`, `String`, `Null`, …), or would clash with what the generated files use: a class they import (`Fillable`, `Table`, `HasUuids`, `ConsistentModel`, `BaseSearchRequest`; `Json` makes `JsonResource`, `Consistent` makes `ConsistentController`), the controller variables `$this` / `$request` (`This`, `Request`), or the module `Middleware`, whose routes are never loaded;
- `consistentapi.modules_folder` is not a valid folder;
- an unknown `--routes` / `--resources` value (also next to `all`), or an empty list;
- an `--accesses` entry not in `method-access` form, with an unknown method, with a method not selected in `--routes`, or with characters other than letters, digits and `_ . : -` in the group name;
- an access group missing from `consistentapi.accesses` (publish the config first) or not a list of middleware names;
- `put` and `patch` ending up with different middleware;
- `--uuid` while `consistentapi.uuid` is `false` or `uuid_column` is not a column name;
- a target file that already exists, without `--force`;
- a module that already holds another model, even with `--force`: `Datum` and `Data` both make the module `Data`.

### `consistent:rebuild`

Migrates a conventional Laravel layout into the same plural module structure under `app/{modules_folder}`:

- Models from `app/Models`: every concrete Eloquent model (a class extending `Model`, `Authenticatable`, `Pivot` or `ConsistentModel`, directly or through other classes) becomes the model of a module named after its plural (`Post` → `Posts`). Abstract classes, scopes, casts and other classes that are not models stay where they are
- Controllers from `app/Http/Controllers` (and `app/Controllers`)
- Requests from `app/Http/Requests` (and `app/Requests`)
- Resources from `app/Http/Resources` (and `app/Resources`)

A controller, request or resource goes to the module its name points to. The type suffix (`Controller`, `Request`, `Resource`, `Collection`), an action prefix (`Store`, `Update`, `Search`, `Index`, `Show`, `Create`, `Edit`, `Destroy`, `Delete`, …) and a variant suffix (`Light`, `Admin`, …) are taken off, and the rest must equal a model name, in the singular or the plural: `PostController`, `PostsController`, `StorePostRequest`, `PostLightResource` and `PostCollection` go to `Posts`, even when a model `Store` exists too. Only when nothing matches exactly is a model accepted whose name is a StudlyCase word of the class name, provided it is the only one (`V2PostResource`) or contains all the others (`PostCommentReplyController` with models `Post` and `PostComment`). A name that points to several models, such as `AdminPostController` with models `Admin` and `Post`, stays in place and is reported. Subfolders are not kept: `app/Http/Controllers/Api/PostController.php` becomes `Posts/Controllers/PostController.php`.

References are rewritten on the syntax tree of every PHP file under `app/`, `routes/`, `database/`, `tests/`, `config/` and `bootstrap/` (`bootstrap/cache` excepted), so each name is resolved the way PHP resolves it:

- the namespace statement of a moved file, whatever its form (`<?php namespace App\Models;` on one line, `namespace App\Models { … }`);
- imports, aliased imports and group imports (`use App\Models\{Post, Tag};` is split into one import per class), fully qualified names, relative names (`Models\Post` under `use App\Models;`, `namespace\Post`), and names that reached a class through a shared namespace (`extends Controller` inside `App\Http\Controllers`, `Post::class` inside `App\Models\Comment`), which get an import — or a fully qualified name when the alias is taken. A longer name (`\Legacy\App\Models\Post`) or a class below a model's name (`App\Models\Post\Status`) is another class and is left alone;
- class names in strings (`'App\Models\Post'`, `"App\\Models\\Post"`, `'App\Http\Controllers\PostController@index'`), in comments and in docblocks; a short name used only in a docblock type (`@property-read Post $post`) gets an import;
- a moved model that uses `HasFactory` and has a factory at `database/factories/{Model}Factory.php` gets `#[UseFactory({Model}Factory::class)]`, since Laravel would look for its factory under the new namespace.

Top-level `Route::` statements of `routes/api.php` that use moved controllers of one module move into that module's `Routes.php`, whole: a route group keeps its middleware (`auth` included) and every class it names is imported into the module file. A route inside an `if` or any other statement, an assignment (`$posts = Route::…`), and a statement using controllers of several modules stay in `routes/api.php` with their class names updated, and are listed. Comments are never taken for code: a commented-out route stays commented out in `routes/api.php`. Imports of moved controllers that nothing in `routes/api.php` uses any more are removed. Module `Routes.php` files the command creates or extends keep their imports sorted the way Pint orders them.

Commit or stash your changes first, so the result can be reviewed with `git diff`:

```bash
php artisan consistent:rebuild --dry-run   # print the plan, change nothing
php artisan consistent:rebuild             # print the plan, ask, then write
php artisan consistent:rebuild --force     # no question, also in production
php artisan consistent:rebuild --rollback  # undo the last run
```

- The whole plan is computed in memory first: which classes move, which stay and why, which route statements move or stay, which files change. Before anything is written, the command stops with a non-zero exit code when two classes would share a destination, a destination exists, a file that names a moved class cannot be parsed, or a rewritten file would not be valid PHP (each result goes through PHP's own parser) or would not declare the planned class.
- Without `--force` the command asks for confirmation, and in production first asks whether to run at all. It warns when the git working tree has uncommitted changes.
- Every file is written atomically. When a write fails, every file is put back, the folders the run created are removed, and the command fails.
- Before the first write, the original contents of every touched file go into `storage/app/consistent-rebuild.json`. `--rollback` restores them and removes the created folders; a file changed since the run stops it unless `--force` is given.

`Post` + `PostController` become `App\Modules\Posts\Models\Post` and `App\Modules\Posts\Controllers\PostController`.

#### What `consistent:rebuild` does not do

- **Class names stored in the database keep the old names.** Without a morph map, Laravel stores the full class name in polymorphic `*_type` columns, in `notifications.notifiable_type`, in Sanctum's `personal_access_tokens.tokenable_type` and in serialized queued jobs. After the move, `App\Models\User` no longer exists. When the application defines no morph map, the command prints one that maps the old names to the new classes; add it to `AppServiceProvider::boot()` (or update the rows):

  ```php
  Relation::morphMap([
      'App\Models\User' => \App\Modules\Users\Models\User::class,
  ]);
  ```

- Blade templates, JSON fixtures and other files that are not plain PHP are not rewritten: the command lists every line of `resources/`, `database/`, `tests/` and `config/` that names a moved class.
- Generated caches (`bootstrap/cache`) are left alone: run `php artisan optimize:clear` and `composer dump-autoload` afterwards; the command prints this checklist.
- A file declaring several classes is not moved, and neither are the classes it declares; the command lists them.
- Moved routes get the prefix and middleware of `config/consistentapi.php` (`api_url_prefix`, `middlewares`) instead of those `bootstrap/app.php` gives `routes/api.php`. With the defaults (`api`, `['api']`) nothing changes.

---

## Models (`ConsistentModel`)

Base API model:

```php
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Attributes\Sortable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('posts')]
#[Fillable(['title', 'body'])]
#[Hidden([])]
#[Filterable(['title', 'body'])]
#[Sortable(['id', 'created_at', 'title'])]
class Post extends ConsistentModel {}
```

`#[Table]`, `#[Fillable]` and `#[Hidden]` are Eloquent attributes, which Laravel also reads from parent classes; the package's `#[Filterable]` / `#[Sortable]` sit next to them. The package's attributes are read from the model class itself, so a model does not inherit them from its parent class: declare them on every model you filter or sort.

Features:

- `CanFilter` and `CanSort` traits
- `HasFactory`: a `$factory` property or `#[UseFactory]` attribute works as in any Laravel model; without them `Database\Factories\{Model}Factory` is looked up and told which model it creates (a factory generated by `make:factory` names none), then Laravel's resolver. Two modules with models of the same short name would share that factory: point them to their own with `#[UseFactory]`
- `joinOnce()`, `leftJoinOnce()`, `rightJoinOnce()` — protected helpers for model scopes: an inner, left or right join that is skipped when the query already joins the table (see [Joining a table once](#joining-a-table-once))
- `getAllColumns()` — column listing of the table, read on the model's own connection
- `$perPage = 10` — page size when the request has no `perpage`

### Joining a table once

`joinOnce()`, `leftJoinOnce()` and `rightJoinOnce()` take the query, then the table and the `on` condition as `join()` does: first column, operator, second column. They are `protected`, so call them from the model's scopes:

```php
use Illuminate\Database\Eloquent\Builder;

class Post extends ConsistentModel
{
    public function scopeWrittenBy(Builder $query, string $name): Builder
    {
        return $this->joinOnce($query, 'users', 'users.id', '=', 'posts.user_id')
            ->select('posts.*')
            ->where('users.name', $name);
    }
}
```

A join is skipped when the query already joins the table, whatever the type of that join, so the first join wins: `leftJoinOnce()` after `joinOnce()` of the same table keeps the inner join, and two scopes joining the same table do not fail on a duplicate table. [Sorting through relations](#sorting-through-relations) adds no joins, so it combines with any scope. The table is compared as written: `'users as u'` and `'users'` are different joins. The `on` condition is a single column comparison; for a closure, use `join()`.

### Disable pagination for a model

Implement the contract:

```php
use Adonyarik\ConsistentApi\Contracts\WithoutPaginationModelContract;

class Setting extends ConsistentModel implements WithoutPaginationModelContract
{
    // ...
}
```

Then, with `paginate=false` (or `0`), `indexLogic` returns the list without pagination meta, at most `consistentapi.pagination.unpaginated_limit` rows (`1000` by default; `null` for no limit). Use it for short lists such as dictionaries.

---

## Controller (`ConsistentController`)

Extend `Adonyarik\ConsistentApi\Controllers\ConsistentController` and set:

- `$resourceClass` — API Resource class (`JsonResource` when not set, which returns every visible attribute)
- `$resourceClasses` — resources for specific controller methods (optional, see [below](#resource-per-action-resourceclasses))
- `$relationFunctions` — relations for `with` / `load` (optional)

```php
namespace App\Modules\Posts\Controllers;

use Adonyarik\ConsistentApi\Controllers\ConsistentController;
use App\Modules\Posts\Models\Post;
use App\Modules\Posts\Requests\SearchPostRequest;
use App\Modules\Posts\Requests\StorePostRequest;
use App\Modules\Posts\Requests\UpdatePostRequest;
use App\Modules\Posts\Resources\PostResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class PostController extends ConsistentController
{
    protected string $resourceClass = PostResource::class;

    protected array $relationFunctions = ['author'];

    public function index(SearchPostRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Post);
    }

    public function show(Post $post): JsonResource
    {
        return $this->selectLogic($post);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        return $this->storeLogic($request, new Post);
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

`indexLogic` takes a `ConsistentModel` (it needs the `CanFilter` and `CanSort` traits); the other methods work with any Eloquent model.

- `storeLogic` fills the given instance with `$request->validated()` and saves it, so the validated attributes must be fillable, and attributes you set before keep their values. Set what must not come from the client on the instance:

  ```php
  public function store(StorePostRequest $request): JsonResponse
  {
      $post = new Post;
      $post->author()->associate($request->user());

      return $this->storeLogic($request, $post);
  }
  ```

- `updateLogic` fills the bound model with `$request->validated()` and saves it.
- `selectLogic`, `updateLogic` and `destroyLogic` answer `404` for a model that is not stored. Laravel's route model binding normally answers that already; this covers routes without `SubstituteBindings`, where the action would otherwise get an empty model and create a row on update.
- When a model event cancels the write (a `creating`, `updating`, `saving` or `deleting` listener returning `false`), `storeLogic`, `updateLogic` and `destroyLogic` answer `409` instead of reporting success. Override `writeRefused()` to answer differently.

`indexLogic` validates `filter`/`sort` against the model's `#[Filterable]` / `#[Sortable]` declarations available to the request's route *before* the query runs. Any disallowed column, disallowed or disabled nested operator, nesting on a column that doesn't support it, or value of the wrong shape (see [Nested operators](#nested-operators)) returns a `422` shaped like a failed form request — `{"message": "<the error>", "errors": {"filter.x": ["<the error>"]}}` — and the query is never executed with unverified input. A value the database cannot compare with its column (`filter[price][eq]=abc` on an integer column on PostgreSQL) is a `422` on `filter` too, not a server error.

The rows are ordered by the primary key after the requested sorts, so `LIMIT` / `OFFSET` pages never repeat or skip a row, also when no sort is requested or the sort column is not unique.

### Authorization

Every `*Logic` method authorizes its action before running it, through the overridable `authorizeAction(string $ability, Model|string $subject)`:

| Method         | Ability    | Subject           |
| --------------- | ---------- | ----------------- |
| `indexLogic`    | `viewAny`  | the model class   |
| `selectLogic`   | `view`     | the model         |
| `storeLogic`    | `create`   | the model class   |
| `updateLogic`   | `update`   | the model         |
| `destroyLogic`  | `delete`   | the model         |

When a [policy](https://laravel.com/docs/authorization#creating-policies) is registered or discovered for the model (Laravel also looks in the module, e.g. `App\Modules\Posts\Policies\PostPolicy`), the ability is checked with `$this->authorize()`, which answers `403` when the policy denies it or lacks the method. Without a policy every action is allowed, and access depends on the route middleware alone: a route behind `verified` lets any verified user update or delete any record. For record-level rules, write a policy:

```php
class PostPolicy
{
    public function viewAny(?User $user): bool { return true; }
    public function view(?User $user, Post $post): bool { return true; }
    public function create(User $user): bool { return true; }
    public function update(User $user, Post $post): bool { return $post->author_id === $user->id; }
    public function delete(User $user, Post $post): bool { return $post->author_id === $user->id; }
}
```

Override `authorizeAction()` to authorize differently, or with an empty body to switch it off. The form requests `consistent:create` generates have no `authorize()` method, so `FormRequest` allows them; add one when a request needs its own check.

### Resource per action (`$resourceClasses`)

Several controller methods can share a `*Logic` method and still return different resources:

```php
class PostController extends ConsistentController
{
    protected string $resourceClass = PostResource::class;

    protected array $resourceClasses = [
        'index' => PostLightResource::class,
        'adminIndex' => PostAdminResource::class,
    ];

    public function index(SearchPostRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Post);
    }

    public function adminIndex(SearchPostRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Post);
    }

    public function show(Post $post): JsonResource
    {
        return $this->selectLogic($post); // not listed: PostResource
    }
}
```

The `*Logic` methods use the resource of the controller method the route points to; methods not listed in `$resourceClasses` use `$resourceClass`. The controller records the method in `callAction()`, which Laravel calls before every action — if your controller overrides `callAction()`, call `parent::callAction()`. Called directly, without the router, the `*Logic` methods use `$resourceClass`. In your own methods, `$this->resourceClassFor()` returns the class for the current action (or for the method name you pass).

---

## Filtering

Declare allowed columns on the model with `#[Filterable]`:

```php
use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Support\RelationFilter;

#[Filterable([
    'name',
    'description',
    'status' => ['eq', 'in'],
    'price' => [
        'min' => ['numeric', 'min:0'],
        'max' => ['numeric', 'min:0'],
    ],
    'created_at' => [
        'from' => ['date'],
        'to'   => ['date', 'after_or_equal:filter.created_at.from'],
    ],
    'user' => new RelationFilter(relation: 'user', column: 'name'),
])]
class Recipe extends ConsistentModel { /* ... */ }
```

Give every operator on a column that is not text a type rule (`numeric`, `integer`, `date`, …). Without one, a value the column cannot hold is a `422` from the controller on PostgreSQL, while MySQL and SQLite compare it anyway and simply find nothing.

### Simple columns

A plain string (`'name'`) allows only scalar filtering, applied as a substring search: `ILIKE '%value%'` on PostgreSQL, which ignores case, and `LIKE '%value%'` elsewhere, where case sensitivity follows the collation — MySQL with a `_bin` or `_cs` collation compares case-sensitively, and SQLite ignores case for ASCII letters only (`'ÉCOLE'` does not match `é`):

```http
GET /api/recipes?filter[name]=chocolate
```

The value is matched literally: its `%` and `_` are escaped, so `filter[name]=100%` finds names that contain `100%` and `filter[name]=%` does not match every row (see [How input reaches the query](#how-input-reaches-the-query)).

A filterable column can be searched character by character even when the model hides it: `filter[api_token]=a`, then `ab`, … reveals a value that `#[Hidden]` keeps out of the responses. Do not declare hidden or secret columns as filterable, or restrict them with [`accesses`](#restricting-columns-by-access).

### Nested operators

A column can be declared with a list of allowed operators (`'status' => ['eq', 'in']`), or with operators paired with validation rules for their values:

```php
'price' => [
    'min' => ['numeric', 'min:0'],
    'max' => ['numeric', 'min:0'],
],
```

A rule comparing two operators, such as `gte:filter.price.min`, fails whenever the other operator is missing (Laravel's `gte` needs both values), so `filter[price][max]=25` alone would get a `422`. Compare them in a closure rule that skips a missing value instead, or leave the order to the client. `after_or_equal:filter.created_at.from` does not have this problem.

```http
GET /api/recipes?filter[status][eq]=published
GET /api/recipes?filter[price][min]=10&filter[price][max]=50
GET /api/recipes?filter[created_at][from]=2024-01-01&filter[created_at][to]=2024-12-31
```

Supported operators (must also be enabled in `config('consistentapi.filters.operators')`):

| Operator  | Behaviour                                     |
| --------- | ----------------------------------------------- |
| `eq`      | `column = value`                                |
| `not_eq`  | `column != value`                               |
| `like`    | `column LIKE/ILIKE '%value%'`, value matched literally |
| `from`    | `column >= 'YYYY-MM-DD'` of the value's day     |
| `to`      | `column < 'YYYY-MM-DD'` of the next day          |
| `min`     | `column >= value`                               |
| `max`     | `column <= value`                               |
| `in`      | `column IN (values)` — comma string or array    |
| `not_in`  | `column NOT IN (values)` — comma string or array|
| `null`    | `whereNull` for `true` / `1` / `on` / `yes`, `whereNotNull` for `false` / `0` / `off` / `no` |

`from` / `to` take `YYYY-MM-DD`, optionally followed by a time (`2024-01-10T08:30:00+02:00`), and cover whole days: only the day is used, compared as a date string, which every database compares correctly with `date`, `datetime` and `timestamp` columns — SQLite's text dates and fractional seconds (`23:59:59.5`) included. Dates that do not exist (`2024-02-30`, `0000-00-00`) and relative words (`next monday`) return a `422`. A `date` rule on the operator gives a Laravel validation message instead.

A plain list of values without operator keys is treated as `in`, provided the column allows `in`; the value rules declared for `in` apply to it too:

```http
GET /api/recipes?filter[status][]=draft&filter[status][]=published
```

Attempting to nest a column that was declared as a plain string, or using an operator that isn't in its declared list, returns a `422`. So do the wrong value shapes:

- A column declared with operators takes operators only: a scalar value (`filter[status]=pub`) returns a `422` instead of becoming a `LIKE` search, so it cannot get around the declared list. Only plain columns and relation columns take a scalar.
- `in` / `not_in` take a list of single values: `filter[status][in][x][]=draft` returns `422 Filtering by status.in takes a list of values.`
- Every other operator takes a single value: `filter[status][like][]=pub` returns `422 Filtering by status.like takes a single value.`
- `from` / `to` take a date (`… takes a date.`), `null` takes one of the boolean words above (`… takes true or false.`): `filter[status][null]=maybe` is an error, not a `whereNotNull`.

An empty value (`filter[status][eq]=`) means the operator was not set and is not checked.

Called directly, `Post::filter()` does not reject anything: it applies a scalar to any declared column as a `LIKE` search and skips every value of the wrong shape instead of reading it as something else. `$model->filterValueError($operator, $value)` tells why a value would be rejected.

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

Internally this runs `whereHas($relation, ...)` matching `$column` with an escaped `LIKE`/`ILIKE` (`operator: 'like'`, the default) or `=` (`operator: 'eq'`) — this works for any relation type (`BelongsTo`, `HasMany`, `BelongsToMany`, etc.) without duplicating rows, and for a dotted path through several relations (`new RelationFilter('author.profile', 'nickname')`). A relation column takes a single value: nested operators (`filter[user][eq]=...`) return a `422`.

Validation rules for the value go in the fourth argument; give a column that is not text a type rule:

```php
'author_id' => new RelationFilter('author', 'id', 'eq', ['integer']),
```

`eq` and `like` are the only operators, written exactly so. Anything else, such as `'equal'`, `'='` or `'EQ'`, is never taken for a substring match: startup validation rejects it with `InvalidRelationFilterException`, and where it does not run, applying the filter throws the same exception.

### Restricting columns by access

`#[Filterable]` is repeatable. Columns of an attribute without `accesses` are available to every request; with `accesses`, only to requests that have at least one of the listed [access groups](#access-groups-consistentapiaccesses):

```php
use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Support\RelationFilter;

#[Filterable(['title', 'status' => ['eq', 'in']])]
#[Filterable(['email', 'user' => new RelationFilter('user', 'name')], accesses: ['admin'])]
class Post extends ConsistentModel { /* ... */ }
```

- A column may be declared in only one `#[Filterable]` of a model.
- A column the request has no access to is treated as unknown: `filter[email]=…` on a route without `admin` returns the same `422 Filtering by email is not allowed.` as a column that does not exist, so the response does not reveal it. If none of the model's columns is available, the response is `Filtering on this object is not allowed.`
- `BaseSearchRequest` builds value rules only for the columns available to the route.

Outside an HTTP request (console commands, queued jobs) there is no route, so only unrestricted columns apply. Pass the accesses explicitly where you need more:

```php
Post::filter($filters, ['admin']);
(new Post)->getAllowedFilters(['admin']);
```

The methods of `CanFilter` that depend on the declarations (`scopeFilter`, `isFilterable`, `getAllowedFilters`, `getAllowedFilterKeys`, `filterRulesMap`) and of `CanSort` (`scopeSort`, `isSortable`, `getAllowedSorts`) take this list as their last argument; `null` (the default) means the accesses of the current route.

### How input reaches the query

Everything in `filter` and `sort` comes from the client, so none of it reaches SQL unchecked:

- Column and operator names from the input are only looked up in the model's declarations; the query is built from the declared names, never from the input keys. A direction is `asc` or `desc`.
- Values are bound as query parameters, never written into the SQL.
- `LIKE` patterns of substring searches (plain columns, `like`, relation filters) escape `%`, `_`, `[` and the escape character with an explicit `ESCAPE '!'` clause, so the value is matched literally on every database.
- A value of the wrong shape (see [Nested operators](#nested-operators)), an unknown direction or an operator disabled in `consistentapi.filters.operators` returns a `422` before the query runs. The controller checks this itself, so it holds even for a search request whose rules were overridden.
- Only validated values are read: `perpage`, `page` and `paginate` come from the validated input, and an invalid `page` is a `422`, not page 1.
- The `422` responses of the controller repeat names from the input; their JSON escapes `<`, `>`, `&`, `'` and `"` as `\u003C`-style sequences, so the body never carries markup from the input. A JSON decoder reads the original text, so render it as text, not HTML.

### Validation of filter input

`BaseSearchRequest` automatically builds validation rules from the model's `#[Filterable]`:

- Any operator not enabled in `config('consistentapi.filters.operators')` → `422` (a plain list counts as `in`).
- Any per-operator rules declared on the model (e.g. `numeric`, `date`, `after_or_equal:filter.created_at.from`) are applied to the corresponding `filter.column.operator` input; operators declared without rules are accepted as they are. `nullable` is added in front of every declared rule list, so an empty value never fails them.
- A plain list (`filter[qty][]=1&filter[qty][]=2`) is rewritten to `filter[qty][in][]=…` before validation, so the rules declared for `in` (`'in' => ['array', 'max:2']`) cannot be bypassed.
- A column declared with operators must get an array: `filter[status]=pub` fails with `The filter.status field must be an array.` Without `$model` the controller rejects it with `Filtering by status requires an operator.`
- A `RelationFilter` with rules gets them for `filter.{column}`; without rules its value is only checked to be a single value.
- Columns the route has no access to get no rules; the controller rejects them as unknown.
- An empty value of an allowed column (`filter[name]=`, `filter[price][min]=`, `filter[status][]=`) is ignored, as if it was not sent.

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
class Recipe extends ConsistentModel { /* ... */ }
```

### Simple columns

```http
GET /api/recipes?sort[created_at]=desc
```

An empty direction (`sort[created_at]=`) is ignored; through the controller any direction other than `asc` / `desc` returns a `422`. Called directly, the `sort()` scope skips such a direction silently (`ASC` / `DESC` in capitals are accepted).

### Sorting through relations

`RelationSort` orders by a correlated subquery that picks one related value per row: the smallest non-null value for `asc`, the largest for `desc` — the way search engines sort by multi-valued fields. For a to-one relation that is simply the related value; for a to-many relation (or a `HasOne` that finds several rows) the rows are never multiplied and the `total` of the page stays right. Rows without a related value sort as `NULL`.

```http
GET /api/recipes?sort[user]=asc
GET /api/recipes?sort[categories]=asc
```

The subquery is built from the relation itself, the way `withMin()` / `withMax()` build theirs, so:

- the relation's own conditions apply: `hasMany(Comment::class)->where('approved', true)`, `latestOfMany()` / `ofMany()`, pivot conditions;
- so do the related model's global scopes: soft deleted rows do not count;
- custom keys of `BelongsToMany` and the morph type of `MorphOne`, `MorphMany` and `MorphToMany` are respected;
- a relation to the model's own table (a parent, children) gets an alias of its own;
- names are quoted and get the connection's table prefix, and the values compare by their own type, so `9` sorts before `100` and booleans work.

Every relation type works, on every database, except `MorphTo`, whose related table depends on the row. Ties are broken by the next sort and, in `indexLogic`, by the primary key. No join is added to the main query, so a relation sort combines with scopes that join tables.

The SQL follows the model's connection, not the default one: a model on a PostgreSQL connection gets `ILIKE` and PostgreSQL quoting even when the default connection is MySQL.

Startup validation rejects a `RelationSort` on `MorphTo` or on a method that is not a relation (see [Startup validation](#startup-validation-of-filterable--sortable)); where it does not run, such a sort is silently ignored.

### Restricting columns by access

`#[Sortable]` is repeatable and takes `accesses` the same way as [`#[Filterable]`](#restricting-columns-by-access):

```php
#[Sortable(['id', 'created_at'])]
#[Sortable(['salary'], accesses: ['admin', 'manager'])]
```

A request without `admin` or `manager` gets `422 Sorting by salary is not allowed.`, as for an unknown column. Outside HTTP, pass the accesses explicitly: `Post::sort($sorts, ['admin'])`.

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

Setting `$model` lets `BaseSearchRequest` build the `filter.*` value-validation rules straight from that model's `#[Filterable]` declaration (see [Validation of filter input](#validation-of-filter-input)). This is done automatically by the `consistent:create` stub. A subclass that overrides `rules()` should merge `parent::rules()`, and one that overrides `prepareForValidation()` should call the parent method, which turns plain lists into `in` filters.

Base rules (always applied, regardless of `$model`):

| Parameter   | Rules                                                |
| ----------- | ------------------------------------------------------ |
| `perpage`   | numeric value from `config('consistentapi.pagination.per_page')` |
| `page`      | integer from `1` to `BaseSearchRequest::MAX_PAGE` (1,000,000), so the offset cannot overflow |
| `paginate`  | `true` / `false` / `0` / `1`                            |
| `sort`      | array                                                   |
| `sort.*`    | `asc` or `desc`                                         |
| `filter`    | array                                                   |
| `filter.*`  | nullable; if an array, its keys must all be enabled in `config('consistentapi.filters.operators')` |

Example request:

```http
GET /api/posts?perpage=25&filter[title]=hello&sort[created_at]=desc
```

Behaviour at the controller level:

- Filtering: escaped `LIKE` / `ILIKE` (PostgreSQL) on columns allowed by `#[Filterable]`, or the operator/relation logic described above
- Sorting: `orderBy` on columns allowed by `#[Sortable]`, or the relation logic described above
- Only the columns available to the route's [accesses](#restricting-columns-by-access) count as allowed
- Disallowed columns, disallowed nested operators, a scalar on a column declared with operators, a value of the wrong shape for its operator, a direction other than `asc` / `desc`, or filter/sort on a non-filterable/non-sortable model → `422` with Laravel-style validation errors, whatever rules the search request has (see [How input reaches the query](#how-input-reaches-the-query))

Filterable and sortable columns come from `#[Filterable]` / `#[Sortable]`: a model without the attribute, or with an empty one such as the `#[Filterable([])]` / `#[Sortable([])]` that `consistent:create` generates, cannot be filtered or sorted: any `filter` / `sort` input returns `422`.

---

## Startup validation of `#[Filterable]` / `#[Sortable]`

When `config('consistentapi.filters.validate_on_boot')` is `true` **and** the app is running in `local` or `testing`, `ConsistentApiProvider::boot()` scans every model under `config('consistentapi.filters.model_paths')` — every class declared in every file — and eagerly checks every `#[Filterable]` / `#[Sortable]` on it (abstract models are skipped, since their attributes do not apply to subclasses):

- Every operator declared in a `#[Filterable]` column exists in `config('consistentapi.filters.operators')` → otherwise `InvalidFilterableOperatorException`.
- Every `RelationFilter` has the operator `eq` or `like` and points to a method that exists on the model and actually returns an Eloquent `Relation`; a dotted path (`author.profile`) is checked step by step on the related models → otherwise `InvalidRelationFilterException`.
- Every `RelationSort` points to a method that exists on the model and returns a relation other than `MorphTo` → otherwise `InvalidRelationSortException`.
- Every name in `accesses:` is a group in `consistentapi.accesses` → otherwise `InvalidAccessException`.
- A column appears in only one `#[Filterable]` (and only one `#[Sortable]`) of a model → otherwise `InvalidAccessException`.

This catches typos (`min` used but not enabled in config, `relation: 'usre'`, `operator: 'equal'`, `accesses: ['amdin']`, pointing a `RelationFilter` at a non-relation method) at boot time instead of on the first matching request.

`model_paths` supports `*` wildcards, which makes it work with both conventional (`app/Models`) and modular (`app/Modules/*/Models`) project layouts out of the box.

Disable this check entirely (e.g. to skip the filesystem scan) with:

```env
CONSISTENTAPI_VALIDATE_ON_BOOT=false
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

The `items` / `meta` keys are configurable in `consistentapi.pagination`. `PaginatedJsonResponse` takes any `Illuminate\Contracts\Pagination\LengthAwarePaginator`; top-level data the resource collection adds with `with()` or `additional()` is merged into the body, as Laravel does for resource responses.

The page size comes from `perpage`, which must be one of the `config('consistentapi.pagination.per_page')` values. Without it, the model's `$perPage` is used — `10` for `ConsistentModel`.

`meta.path` is the URL of the request, which Laravel builds from the client's `Host` header. Enable trusted hosts in `bootstrap/app.php` (`$middleware->trustHosts()`), so a forged `Host` is rejected instead of ending up in the response.

Without pagination (contract + `paginate=false`, at most `unpaginated_limit` rows):

```json
{
  "items": [ /* ... */ ]
}
```

Single resources (`show`, `store`, `update`) are returned without a `data` wrapper, since the package calls `JsonResource::withoutWrapping()` (see [Installation](#installation)).

With `app.debug` off, a missing route-bound model under the API prefix answers `404 {"message": "Not found."}` instead of Laravel's `No query results for model [App\…\Post] 1`, which names the class; set `consistentapi.hide_model_not_found` to `false` to keep Laravel's message.

---

## Middleware

Aliases are registered automatically:

| Alias                          | Class                        | Purpose                                                  |
| -------------------------------- | ------------------------------ | ----------------------------------------------------------- |
| `consistent.api-json`          | `ApiJsonMiddleware`          | Asks for JSON on API-prefixed URLs whose client prefers no specific type |
| `consistent.ensure-json`       | `EnsureJsonMiddleware`       | Requires a JSON Content-Type for `POST` / `PUT` / `PATCH` requests with a body |
| `consistent.ensure-multipart`  | `EnsureMultipartMiddleware`  | Requires `multipart/form-data` for `POST`                |

### JSON for API URLs

`ApiJsonMiddleware` is prepended to the global middleware automatically. It runs before routing and before the other global middleware, so everything under the API prefix gets JSON errors, including `404` for unknown URLs, `405`, `413` and maintenance mode. URLs outside the prefix are left alone.

It sets `Accept: application/json` only when the client prefers no specific type: no `Accept` header, `*/*`, or HTML first, as browsers send it. A client asking for JSON (`application/vnd.api+json`) or for another type (`text/csv` for an export, `application/pdf`) keeps its header, so content negotiation and `$request->accepts()` work under the prefix.

To turn it off, set `api_json_enabled` to `false` in `config/consistentapi.php`. You can then attach it yourself, globally or with the `consistent.api-json` alias. As route middleware it runs only after a route matches, so `404` and `405` responses stay HTML unless the client sends `Accept: application/json`.

### Example usage

The other middleware are attached per route or group:

```php
Route::middleware(['consistent.ensure-json'])->group(function () {
    // ...
});

Route::post('/files', UploadController::class)
    ->middleware('consistent.ensure-multipart');
```

Attach `EnsureMultipartMiddleware` only to upload endpoints: any non-POST request or missing multipart Content-Type returns `415 {"message": …}`. The method is the one the client really sent, so `POST` with `_method=PUT` / `PATCH` — Laravel's way to upload files on update, since PHP reads files of real `POST` requests only — passes.

`EnsureJsonMiddleware` checks only requests that carry a body: an action endpoint such as `POST /posts/1/publish` without a body passes, whatever its `Content-Type`. A body that is not JSON returns `415 {"message": …}`.

`ApiJsonMiddleware` covers `/{api_url_prefix}` and everything under it; slashes around the prefix are ignored, as for the module routes, and a nested prefix such as `api/v1` works. With an empty prefix the API is the whole application, so it applies to every request.

---

## Route macro `development`

Routes available only in the `local` environment:

```php
use Illuminate\Support\Facades\Route;

Route::development(function () {
    Route::get('/api/_debug/ping', fn () => ['ok' => true]);
});
```

Outside the `local` environment the callback is not called, so the routes do not exist. The macro is declared while the providers register, so it works in `routes/web.php`, `routes/api.php` and module `Routes.php` alike.

The routes also run `DevelopmentOnlyMiddleware`, which answers `404` outside `local`. A route cache built with `APP_ENV=local` (in a CI image, say) contains the development routes; deployed to production, they answer `404` instead of serving the debug endpoints. Build the route cache in the environment it is deployed to anyway.

---

## Extra traits

### `Credibility`

Check that the current model belongs to another one through its foreign key, for nested routes such as `posts/{post}/comments/{comment}`. The foreign key is compared with the other model's primary key (`getKey()`), or with the column passed as `$ownerKey` when it references another one:

```php
use Adonyarik\ConsistentApi\Traits\Credibility;

class Comment extends ConsistentModel
{
    use Credibility;

    public function ensurePost(Post $post): void
    {
        $this->checkModelCredibility($post, 'post_id'); // 404 on mismatch
    }
}
```

`isCredible($post, 'post_id')` returns the result instead; `checkModelCredibility($post, 'post_uuid', ownerKey: 'uuid')` compares with another column.

---

## AI agent skills (Laravel Boost)

The package ships [Agent Skills](https://agentskills.io) for [Laravel Boost](https://laravel.com/docs/boost): short instructions an AI agent loads only when the task needs them, so it can build modules, filters and controllers of your app without reading the package source every time.

| Skill | Covers |
| ----- | ------ |
| `consistent-api-modules` | `consistent:create` / `consistent:rebuild`, the module layout and `Routes.php`, access groups on routes, UUID route keys, `config/consistentapi.php` |
| `consistent-api-filtering-sorting` | `#[Filterable]` / `#[Sortable]`, operators, `RelationFilter` / `RelationSort`, columns restricted by access, `BaseSearchRequest`, the `filter` / `sort` / `perpage` query syntax, `422` errors, startup validation exceptions |
| `consistent-api-controllers` | `ConsistentController` and its `*Logic` methods, resources per action, response shapes, `WithoutPaginationModelContract`, `ConsistentModel` helpers, `Credibility`, middleware, `Route::development()`, feature tests |

The skills live in `resources/boost/skills/{skill-name}/SKILL.md`.

### Adding the skills to a project

Boost discovers skills of packages required directly in the application's `composer.json`:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

In `boost:install`, enable the skills feature and select `adonyarik/consistent-api` when asked which third-party AI guidelines/skills to install. Boost copies the skills into the folders of the agents you chose (`.claude/skills`, `.cursor/skills`, `.agents/skills`, …).

- Boost already installed: run `php artisan boost:update`. It finds the newly required package and asks whether to add its skills; the question is skipped when the command runs non-interactively, such as from a Composer script. If Boost was installed without skills, run `boost:install` again and enable them.
- After updating this package, run `php artisan boost:update` to refresh the skills, or add `"@php artisan boost:update --ansi"` to `post-update-cmd` in `composer.json`.
- To adjust a skill for your project, copy its folder to `.ai/skills/{skill-name}/` and edit it: a skill there with the same name replaces the package one on the next `boost:update`.
- Without Boost, copy the folders of `vendor/adonyarik/consistent-api/resources/boost/skills/` into your agent's skills directory (`.claude/skills/` for Claude Code).

---

## Package structure

```text
consistent-api/
├── composer.json
├── phpstan.neon
├── config/
│   └── consistentapi.php
├── resources/
│   └── boost/skills/        # Laravel Boost skills: modules, filtering-sorting, controllers
├── stubs/
│   ├── consistent/          # whole-file templates for consistent:create
│   └── module/              # fragments: controller methods, UUID model methods
└── src/
    ├── ConsistentApiProvider.php
    ├── Attributes/
    │   ├── Filterable.php
    │   └── Sortable.php
    ├── Console/
    │   ├── Commands/
    │   │   ├── Concerns/
    │   │   │   └── ResolvesModulesFolder.php
    │   │   ├── CreateModuleCommand.php
    │   │   └── RebuildCommand.php
    │   ├── Generators/
    │   │   ├── ModuleBlueprint.php
    │   │   ├── ModuleBlueprintFactory.php
    │   │   ├── ModuleGenerator.php
    │   │   ├── ResourceKind.php
    │   │   └── RouteAction.php
    │   ├── Rebuild/         # consistent:rebuild: planner, syntax-tree rewriters, route relocation
    │   │   ├── ApiRoutesRelocator.php
    │   │   ├── ClassNameResolver.php
    │   │   ├── Move.php
    │   │   ├── ParsedFile.php
    │   │   ├── PhpSourceRewriter.php
    │   │   ├── RebuildException.php
    │   │   ├── RebuildPlan.php
    │   │   ├── RebuildPlanner.php
    │   │   └── SourceEdits.php
    │   └── Support/
    │       ├── FileTransaction.php
    │       └── PhpImports.php
    ├── Contracts/
    │   └── WithoutPaginationModelContract.php
    ├── Controllers/
    │   └── ConsistentController.php
    ├── Exceptions/
    │   ├── InvalidAccessException.php
    │   ├── InvalidFilterableOperatorException.php
    │   ├── InvalidModuleOptionsException.php
    │   ├── InvalidRelationFilterException.php
    │   └── InvalidRelationSortException.php
    ├── Middleware/
    │   ├── ApiJsonMiddleware.php
    │   ├── DevelopmentOnlyMiddleware.php
    │   ├── EnsureJsonMiddleware.php
    │   └── EnsureMultipartMiddleware.php
    ├── Models/
    │   └── ConsistentModel.php
    ├── Providers/
    │   ├── MacroServiceProvider.php
    │   └── ModuleServiceProvider.php
    ├── Requests/
    │   └── BaseSearchRequest.php
    ├── Responses/
    │   └── PaginatedJsonResponse.php
    ├── Support/
    │   ├── AccessResolver.php
    │   ├── FilterableSortableValidator.php
    │   ├── ModulesFolder.php
    │   ├── PhpClassReader.php
    │   ├── RelationFilter.php
    │   └── RelationSort.php
    └── Traits/
        ├── CanFilter.php
        ├── CanSort.php
        └── Credibility.php
```

---

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

The tests run on Orchestra Testbench with in-memory SQLite. Larastan analyses `src/` at level 5. `consistent:create` tests write into the Testbench skeleton app (`vendor/orchestra/testbench-core/laravel`) and remove what they create; `consistent:rebuild` tests work in a temporary directory.

The filtering and sorting scenarios also run on PostgreSQL and MySQL. `phpunit.xml` forces `APP_ENV`, `DB_CONNECTION` and `DB_DATABASE`, so variables of your shell cannot point the SQLite tests elsewhere. Without a server these tests are skipped; to run them, point them at an empty database you can afford to lose — every test drops all tables there, and on PostgreSQL recreates the `public` schema:

```bash
TEST_PGSQL_HOST=127.0.0.1 TEST_PGSQL_PASSWORD=secret \
TEST_MYSQL_HOST=127.0.0.1 TEST_MYSQL_PASSWORD=secret \
vendor/bin/phpunit
```

Each driver reads `TEST_{PGSQL,MYSQL}_{HOST,PORT,DATABASE,USERNAME,PASSWORD}`; the defaults are ports `5432` / `3306`, database `consistent` and users `postgres` / `root`. The PHP `pdo_pgsql` / `pdo_mysql` extensions must be installed.

Throwaway servers that match the command above:

```bash
docker run -d --rm --name consistent-pgsql -p 127.0.0.1:5432:5432 \
    -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=consistent postgres:17-alpine
docker run -d --rm --name consistent-mysql -p 127.0.0.1:3306:3306 \
    -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=consistent mysql:8.4
```

MySQL needs 10–20 seconds to initialize before it accepts connections; `docker stop consistent-pgsql consistent-mysql` removes both. When `TEST_{DRIVER}_HOST` is set but the server cannot be used — no PDO extension, connection refused, wrong credentials or database — the first test of that driver fails with the reason and the rest of its tests are skipped.

On PHP 8.3 Composer installs PHPUnit 12.5, on PHP 8.4+ PHPUnit 13.

Running `vendor/bin/testbench` commands creates a `.env` in that skeleton, whose `CACHE_STORE=database` breaks the tests of throttled routes; delete the file afterwards.

Every class and method in `src/` has a phpDoc block: summary, description, `@param` / `@return` / `@throws` where they apply and `@see` for the classes and methods it works with. `pint.json` keeps `@package` and the blank lines between tag groups that this format uses.

The [skills](#ai-agent-skills-laravel-boost) in `resources/boost/skills/` restate the behaviour described in this README for AI agents. When a change affects what they say, update them in the same commit.

---

## Quick start checklist

1. `composer require adonyarik/consistent-api`
2. `php artisan vendor:publish --tag=consistent-api-config`
3. Decide whether the application-wide `JsonResource::withoutWrapping()` suits you (`consistentapi.without_wrapping`)
4. Declare your access groups in the `accesses` section of `config/consistentapi.php` (optional)
5. `php artisan consistent:create Post` with the options you need (or `consistent:rebuild --dry-run`, then `consistent:rebuild`, for an existing app on a clean git tree)
6. Write the migration (with `$table->uuid('public_id')->unique();` for a `--uuid` module)
7. Fill in `#[Fillable]` / `#[Hidden]`, `#[Filterable(...)]` / `#[Sortable(...)]` and the request validation rules
8. Write a policy for record-level authorization (see [Authorization](#authorization))
9. For foreign-key/relation columns, use `RelationFilter` / `RelationSort` instead of plain column names
10. Enable any extra nested operators you need in the `filters` section of `config/consistentapi.php`
11. Optionally attach `consistent.ensure-json` / `consistent.ensure-multipart` to your routes (`ApiJsonMiddleware` is already global)
12. With Laravel Boost, install the package's [AI agent skills](#ai-agent-skills-laravel-boost) (`php artisan boost:install` or `boost:update`)

---

## Related packages

`adonyarik/pgsql-enums` (namespace `Adonyarik\PgsqlEnums`) adds native PostgreSQL ENUM types to migrations — the `DB::pgEnum*()` / `$table->pgEnum*()` macros — and the `EnumHelpers` trait for PHP enums:

```bash
composer require adonyarik/pgsql-enums
```

---

## License

MIT © Yaroslav Tyrchenko
