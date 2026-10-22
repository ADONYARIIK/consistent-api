---
name: consistent-api-modules
description: "Scaffold and organize API modules with adonyarik/consistent-api — the consistent:create and consistent:rebuild Artisan commands, the app/Modules/{Plural} layout, module Routes.php files, access groups from consistentapi.accesses on routes, UUID route keys and the package config. Use when adding a new API resource or CRUD endpoints, changing or protecting module routes, moving app/Models and app/Http classes into modules, or editing config/consistentapi.php."
---

# Consistent API: modules

Package `adonyarik/consistent-api`, namespace `Adonyarik\ConsistentApi`. This skill summarizes the package's behaviour, which is usually enough to work without opening its source. When unsure about a detail or the best way to do something, read the relevant class in `vendor/adonyarik/consistent-api/src` (every class and method has a phpDoc block) or the package README.

Related skills: `consistent-api-filtering-sorting` (`#[Filterable]` / `#[Sortable]`, search requests), `consistent-api-controllers` (controllers, resources, responses, middleware).

## Module layout

```text
app/Modules/                 # consistentapi.modules_folder, relative to app/
├── Routes.php               # optional routes outside any module
└── Posts/                   # plural StudlyCase of the model: Post → Posts, Company → Companies
    ├── Controllers/PostController.php
    ├── Models/Post.php
    ├── Requests/{Search,Store,Update}PostRequest.php
    ├── Resources/PostResource.php
    └── Routes.php
```

- Namespaces follow the folders under the application namespace: `App\Modules\Posts\Models\Post`, `App\Modules\Posts\Controllers\PostController` (`Acme\…` when `composer.json` maps `app/` to `Acme\`).
- Every `app/Modules/*/Routes.php` and `app/Modules/Routes.php` is loaded automatically, after the app's own routes and in alphabetical module order, with the prefix `consistentapi.api_url_prefix` (`api`) and the middleware `consistentapi.middlewares` (`['api']`). Write URIs without the prefix (`Route::get('posts', …)` serves `/api/posts`) and never register module routes in `routes/api.php`. `routes/api.php` keeps the prefix `bootstrap/app.php` gives it.
- A folder named `Middleware` in the modules folder is skipped. A missing modules folder is not an error; an invalid `modules_folder` (empty, `..`, `my-modules`: every segment must be a PHP identifier) throws.
- Migrations, factories and seeders stay in `database/`. `ConsistentModel` finds factories as `Database\Factories\{Model}Factory` and tells them which model they create; two modules with models of the same short name should use `#[UseFactory]`.

## Adding a module

1. If routes need protection, make sure the groups exist in the `accesses` section of `config/consistentapi.php` (publish with `php artisan vendor:publish --tag=consistent-api-config`).
2. Run `php artisan consistent:create Post` with the options below.
3. Write the migration yourself (the command creates none). Table name: snake_case plural (`posts`, `blog_posts`, `api_keys` for `APIKey`). With `--uuid` add `$table->uuid('public_id')->unique();`.
4. Fill the model: `#[Fillable]`, `#[Hidden]`, relations, `#[Filterable]` / `#[Sortable]` (see `consistent-api-filtering-sorting`). The generated attributes are empty, so filtering, sorting and mass assignment are disabled until you fill them.
5. Fill `rules()` of `StorePostRequest` / `UpdatePostRequest`: they start empty, and `storeLogic()` / `updateLogic()` save only `$request->validated()`. For record-level access write a policy for the model (see `consistent-api-controllers`).
6. Add fields to the resources and eager-loaded relations to the controller's `$relationFunctions`.
7. Check with `php artisan route:list --path=api/posts`.

## `consistent:create {model}`

```bash
php artisan consistent:create Post
php artisan consistent:create Post --routes=get,post --resources=l,s
php artisan consistent:create Post --accesses=all-verified,delete-admin --uuid
php artisan consistent:create Post --force
```

| Option        | Values                                                         | Default    |
| ------------- | -------------------------------------------------------------- | ---------- |
| `--routes`    | `get`, `post`, `put`, `patch`, `delete`, `all`                 | `all`      |
| `--accesses`  | `method-access` pairs: a `--routes` value or `all`, and a group from `consistentapi.accesses` | none |
| `--resources` | `l` / `light`, `s` / `standard`, `a` / `admin`, `all`          | `standard` |
| `--uuid`      | `consistentapi.uuid_column` becomes the route key              | off        |
| `--force`     | overwrite the files of an existing module                      | off        |

List options take comma-separated values or repeat (`--routes=get --routes=post`); `all` among other values means all.

| `--routes`      | Method    | Route                     | Form request        |
| --------------- | --------- | ------------------------- | ------------------- |
| `get`           | `index`   | `GET posts`               | `SearchPostRequest` |
| `get`           | `show`    | `GET posts/{post}`        | —                   |
| `post`          | `store`   | `POST posts`              | `StorePostRequest`  |
| `put` / `patch` | `update`  | `PUT\|PATCH posts/{post}` | `UpdatePostRequest` |
| `delete`        | `destroy` | `DELETE posts/{post}`     | —                   |

- Both `put` and `patch` (the default) register `update` once with `Route::match(['put', 'patch'], …)`.
- URIs are the kebab-case plural (`posts`, `blog-posts`), route names `{uri}.{action}` (`blog-posts.index`); the parameter is the snake_case model name (`{post}`, `{blog_post}`), constrained with `whereNumber()` (`whereUuid()` with `--uuid`), and the controller variable is its camelCase (`$blogPost`). Acronyms stay one word: `APIKey` → `api-keys`, `{api_key}`, `$apiKey`.
- Resources: `l` → `PostLightResource`, `s` → `PostResource`, `a` → `PostAdminResource`. Each generated resource extends the nearest lighter generated one and spreads `parent::toArray($request)`; the lightest extends `JsonResource` and returns `'id' => $this->id` (`$this->public_id` with `--uuid`).
- The controller's `$resourceClass` is the first generated of standard → light → admin. With both light and standard and an `index`, `$resourceClasses = ['index' => PostLightResource::class]`. The admin resource is only created; bind it to an action yourself.
- The model extends `ConsistentModel` and is declared with attributes only: `#[Table('posts')]`, `#[Fillable([])]`, `#[Hidden([])]`, `#[Filterable([])]`, `#[Sortable([])]`. Keep this attribute style instead of `$table` / `$fillable` properties.
- The search request extends `BaseSearchRequest` with `protected string $model = Post::class;`.

### Access groups on routes (`--accesses`)

- `all-verified` applies to every selected route; methods missing from `--routes` are skipped.
- An entry for a method replaces `all` for that method; several entries for one method are merged. `--accesses=all-admin,get-guest` keeps reads public (`guest` is an empty group) and puts writes behind `admin`.
- `get` covers `index` and `show`. `put` and `patch` share `update()`, so they must end up with the same middleware.
- The method is case-insensitive, the group name is taken as is, and the entry splits at the first dash (`delete-super-admin` → group `super-admin`).
- A route gets the union of its groups' middleware in config order, without duplicates. Routes without middleware are written at the top level; the rest are grouped by identical middleware with a comment naming the groups of all routes in the group:

```php
// verified
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('posts/{post}', [PostController::class, 'show'])->whereUuid('post')->name('posts.show');
});

// admin
Route::middleware(['auth:sanctum', 'verified', 'can:admin'])->group(function () {
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->whereUuid('post')->name('posts.destroy');
});
```

### UUID route keys (`--uuid`)

Requires `consistentapi.uuid = true`. The model gets `#[RouteKey('public_id')]`, `use HasUuids;` and `uniqueIds()` returning `['public_id']`: the primary key stays an auto-increment integer, the UUID is filled on insert, a malformed UUID in the URL is a 404. The column name is written into the generated code, so changing `uuid_column` later does not touch existing modules.

### Errors

All option problems are printed together and nothing is written (exit code 1):

- `Model name must be a valid StudlyCase class name (e.g. Post).`
- `Model name "List" is a reserved word in PHP …` — PHP keywords and type names (`Class`, `Match`, `Int`, `String`, `Null`, …).
- `Model name "Json" makes the class JsonResource, which clashes with JsonResource imported by the generated resource …` — also `Fillable`, `Table`, `HasUuids`, `ConsistentModel`, `BaseSearchRequest`, `Consistent` (→ `ConsistentController`).
- `… makes the controller variable $this / $request …` (`This`, `Request`), `… makes the module Middleware …`.
- `consistentapi.modules_folder "…" is not valid …`.
- `Unknown --routes value "head" …`, `Unknown --resources value "x" …` (also next to `all`), `--routes must list at least one of …`.
- `Invalid --accesses value "admin", expected method-access …`, `Unknown method "fetch" in --accesses …`, `Method "post" in --accesses is not selected in --routes.`, `Access name "…" in --accesses may contain only letters, digits and _ . : -`.
- `Access "x" is not defined in consistentapi.accesses (config/consistentapi.php). Publish it …`, `Access group "x" in consistentapi.accesses must be an array of middleware names.`
- `put and patch both route to update() and must have the same middleware …`.
- `--uuid requires consistentapi.uuid to be true.`, `--uuid requires consistentapi.uuid_column to be a column name.`
- `File already exists: … (use --force to overwrite)`.
- `Module Data already holds the model Datum …` — even with `--force`.

With `--force`, files of an earlier run that this run does not generate are listed (`Not generated by this run, left in place: …`), not deleted. Writes are all or nothing: a failed write puts back what was written.

## Access groups (`consistentapi.accesses`)

```php
// config/consistentapi.php
'accesses' => [
    'guest' => [],
    'unverified' => ['auth:sanctum'],
    'verified' => ['auth:sanctum', 'verified'],
    'admin' => ['auth:sanctum', 'verified', 'can:admin'],
],
```

The package ships the section with these lines commented out: declare your own groups (names: letters, digits, `_ . : -`). A request has a group when its route runs every middleware of the group:

- aliases and middleware groups are resolved first, so an alias and its class match, and a group may list `api`;
- extra route middleware do not matter; middleware removed with `withoutMiddleware()` do not count;
- parameters must match exactly (`auth` does not satisfy `auth:sanctum`);
- global HTTP kernel middleware never grant a group; an empty group is granted to every route;
- hierarchies come for free: a route with every `admin` middleware also has `verified`.

Groups protect generated routes (`--accesses`) and restrict filter/sort columns (`accesses:` on `#[Filterable]` / `#[Sortable]`). In code: `app(\Adonyarik\ConsistentApi\Support\AccessResolver::class)->current()` (groups of the current route, `[]` outside HTTP) or `->forRoute($route)`.

## `consistent:rebuild`

Moves a conventional app into modules. Run it on a clean git tree, start with `--dry-run`, and review the diff.

```bash
php artisan consistent:rebuild --dry-run   # plan only
php artisan consistent:rebuild             # plan, confirmation, write
php artisan consistent:rebuild --force     # no confirmation, also in production
php artisan consistent:rebuild --rollback  # restore the files of the last run (journal in storage/app/consistent-rebuild.json)
```

- Every concrete Eloquent model of `app/Models` (extends `Model`, `Authenticatable`, `Pivot` or `ConsistentModel`, directly or through other classes) becomes the model of module `{Plural}`. Abstract classes, scopes, casts, other non-models and files declaring several classes stay and are listed.
- Controllers, requests and resources from `app/Http/{Controllers,Requests,Resources}` (and `app/{Controllers,Requests,Resources}`) follow the model their name points to after taking off the type suffix, an action prefix (`Store`, `Update`, `Search`, …) and a variant suffix (`Light`, `Admin`, …), singular or plural: `StorePostRequest`, `PostsController`, `PostLightResource` → `Posts`. A name pointing to several models (`AdminPostController` with models `Admin` and `Post`) stays and is listed; so does a name matching no model. Subfolders are flattened.
- References are rewritten on the syntax tree in every PHP file of `app/`, `routes/`, `database/`, `tests/`, `config/`, `bootstrap/` (not `bootstrap/cache`): one-line and braced namespaces, group imports, relative names, names reached through the old namespace (an import is added), class name strings, comments and docblock types. A moved `HasFactory` model with `database/factories/{Model}Factory.php` gets `#[UseFactory]`.
- Only whole top-level `Route::…` statements of `routes/api.php` that name controllers of one module move to its `Routes.php` (groups keep their middleware; every class they name is imported). Routes inside `if` or other statements, assignments and statements naming several modules stay, with new class names; comments stay comments.
- Everything is computed and parsed in memory first: conflicting or existing destinations, an unparsable file naming a moved class, or an invalid result stop the run with exit code 1 before any write. A failed write puts every file back.
- Not handled: class names stored in the database (polymorphic `*_type`, notifications, `personal_access_tokens.tokenable_type`, queued jobs) — the command prints a `Relation::morphMap([...])` mapping old names to new classes when the app has no morph map; Blade/JSON/other non-PHP files are only listed; afterwards run `composer dump-autoload` and `php artisan optimize:clear`. Moved routes get `consistentapi.api_url_prefix` / `middlewares` instead of the `bootstrap/app.php` settings of `routes/api.php`.

## Config (`config/consistentapi.php`)

| Key                | Default     | Meaning                                                              |
| ------------------ | ----------- | -------------------------------------------------------------------- |
| `modules_folder`   | `Modules`   | modules directory under `app/`                                       |
| `request_limit`    | `60`        | requests per minute of the `api` rate limiter (per user id, else IP) |
| `api_url_prefix`   | `api`       | prefix of module routes; also the scope of `ApiJsonMiddleware`       |
| `middlewares`      | `['api']`   | middleware of every module route                                     |
| `api_json_enabled` | `true`      | prepend `ApiJsonMiddleware` to the global middleware                 |
| `without_wrapping` | `true`      | `JsonResource::withoutWrapping()` for every resource of the app      |
| `hide_model_not_found` | `true`  | 404 `{"message": "Not found."}` instead of naming the model class (debug off, API prefix) |
| `uuid`             | `true`      | allow `--uuid`                                                       |
| `uuid_column`      | `public_id` | route key column of `--uuid` modules                                 |
| `pagination`       | see below   | `data_container_name` (`items`), `meta_container_name` (`meta`), `per_page` (`[10, 15, 25, 50, 100]`), `unpaginated_limit` (`1000`) |
| `filters`          | see below   | `operators`, `validate_on_boot` (env `CONSISTENTAPI_VALIDATE_ON_BOOT`), `model_paths` |
| `accesses`         | `[]`        | access groups, see above                                             |

All settings live in this one file; a published file is merged with the defaults key by key, also inside `pagination` and `filters`.

- The `api` limiter applies to routes running `throttle:api` (`$middleware->throttleApi()` in `bootstrap/app.php`). An `api` limiter defined in the app's `AppServiceProvider` replaces the package one.
- Renaming `modules_folder` does not update `consistentapi.filters.model_paths` (`app_path('Modules/*/Models')`); change both.
