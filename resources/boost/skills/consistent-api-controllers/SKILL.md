---
name: consistent-api-controllers
description: "Controllers, resources and responses on top of adonyarik/consistent-api — ConsistentController with indexLogic / selectLogic / storeLogic / updateLogic / destroyLogic, $resourceClass / $resourceClasses / $relationFunctions, the indexQuery / beforeStore / beforeUpdate hooks, the paginated JSON response shape, WithoutPaginationModelContract, ConsistentModel helpers (joinOnce, factories, page size), policies and authorizeAction, the Credibility trait for nested routes, the consistent.* middleware and Route::development(). Use when adding or changing controller actions, returning different resources per action, scoping lists to the current user, setting server-side attributes in a shared trait, building nested or custom list endpoints, choosing request middleware, or writing feature tests against these endpoints."
---

# Consistent API: controllers and responses

Package `adonyarik/consistent-api`. This skill summarizes the package's behaviour, which is usually enough to work without opening its source. When unsure about a detail or the best way to do something, read the relevant class in `vendor/adonyarik/consistent-api/src` (every class and method has a phpDoc block) or the package README.

Related skills: `consistent-api-modules` (scaffolding, routes, access groups), `consistent-api-filtering-sorting` (`#[Filterable]` / `#[Sortable]`, search input, 422 errors).

## Controller

```php
namespace App\Modules\Posts\Controllers;

use Adonyarik\ConsistentApi\Controllers\ConsistentController;
use App\Modules\Posts\Models\Post;
use App\Modules\Posts\Requests\SearchPostRequest;
use App\Modules\Posts\Requests\StorePostRequest;
use App\Modules\Posts\Requests\UpdatePostRequest;
use App\Modules\Posts\Resources\PostLightResource;
use App\Modules\Posts\Resources\PostResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class PostController extends ConsistentController
{
    protected string $resourceClass = PostResource::class;              // default resource of every action

    protected array $resourceClasses = ['index' => PostLightResource::class]; // per controller method

    protected array $relationFunctions = ['user', 'tags'];             // eager loaded on every result

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
        $post = new Post;
        $post->user()->associate($request->user()); // server-side attributes survive storeLogic()

        return $this->storeLogic($request, $post);
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

| Method                                    | Does                                                          | Returns |
| ----------------------------------------- | ------------------------------------------------------------- | ------- |
| `indexLogic(BaseSearchRequest, ConsistentModel)` | authorize `viewAny` + `indexQuery($model::query())` + 422 checks + `sort()` / `filter()` + order by primary key + paginate | `PaginatedJsonResponse`, 200 |
| `selectLogic(Model)`                      | 404 if not stored, authorize `view`, loads `$relationFunctions` | resource, 200 |
| `storeLogic(FormRequest, Model)`          | authorize `create`, `fill($request->validated())`, `beforeStore()`, `save()` on the given new instance | resource, 201 |
| `updateLogic(FormRequest, Model)`         | 404 if not stored, authorize `update`, `fill($request->validated())`, `beforeUpdate()`, `save()` | resource, 200 |
| `destroyLogic(Model)`                     | 404 if not stored, authorize `delete`, `$model->delete()` (soft deletes and events work) | empty, 204 |

- `ConsistentController` extends `Illuminate\Routing\Controller` with `AuthorizesRequests` and `ValidatesRequests`, so `$this->authorize()` and `$this->validate()` are available.
- `storeLogic()` / `updateLogic()` save only `validated()` and mass-assign it: every validated field must be in the model's `#[Fillable]`. Generated Store/Update requests have empty `rules()` — fill them first. Never put an owner column (`user_id`) into rules / `#[Fillable]`: set it on the instance passed to `storeLogic()` or in `beforeStore()`.
- A write cancelled by a model event (`creating` / `updating` / `saving` / `deleting` listener returning `false`) answers 409 `{"message": "The record was not …"}`; override `writeRefused()` to change it. An existing model passed to `storeLogic()` is a `LogicException`.
- `indexLogic()` takes a `ConsistentModel` (traits `CanFilter`, `CanSort`); only its class and declarations are used. The other methods work with any Eloquent model.
- `$resourceClass` defaults to `JsonResource` (all visible attributes).

### Authorization

Every `*Logic()` calls `authorizeAction($ability, $modelOrClass)` first. When a policy exists for the model (registered, or discovered, e.g. `App\Modules\Posts\Policies\PostPolicy`), it runs `$this->authorize()` → 403 on denial or a missing policy method (a policy without `viewAny` denies the index). Without a policy everything is allowed and only route middleware protect the endpoints, so for record-level rules write a policy. Override `authorizeAction()` for custom checks, or with an empty body to switch it off.

- The resource is picked by the controller method the route points to: its `$resourceClasses` entry, else `$resourceClass`. Several actions can share one `*Logic` call with different resources (`'adminIndex' => PostAdminResource::class`). If you override `callAction()`, call `parent::callAction()`, or every action falls back to `$resourceClass`. In your own code, `$this->resourceClassFor()` returns the current action's class (or that of the method name passed).

### Hooks

Protected methods to override in a controller or in a trait shared by several controllers; the defaults do nothing.

```php
use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

trait OwnedByUser // for controllers of modules whose records belong to a user
{
    // Base query of indexLogic(): gets $model::query(); requested filters and sorts are applied to the result
    protected function indexQuery(Builder $query, BaseSearchRequest $request): Builder
    {
        return $query->whereBelongsTo($request->user());
    }

    // After authorization and fill($request->validated()), before save(): overrides the client's input
    protected function beforeStore(FormRequest $request, Model $model): void
    {
        $model->user()->associate($request->user());
    }

    // Same moment in updateLogic(); isDirty() / getOriginal() tell what the request changes
    protected function beforeUpdate(FormRequest $request, Model $model): void {}
}
```

- Constraints from `indexQuery()` hold whatever the client sends: filters only narrow them, the 422 checks stay, `meta.total` counts only allowed rows. Qualify columns (`$query->qualifyColumn('user_id')`; `whereBelongsTo()` does it), wrap `orWhere()` in `where(fn (Builder $q) => …)`; an `orderBy()` there comes before the requested sorts.
- Prefer `beforeStore()` over setting attributes before `storeLogic()` when several actions or controllers share the rule. Throw an `HttpException` in a `before*` hook to refuse the write.
- `indexQuery()` scopes only the list: `show` / `update` / `destroy` get the route-bound model, so restrict them with a policy.

## Responses

`JsonResource::withoutWrapping()` is enabled by the package for the whole application (`consistentapi.without_wrapping`, default `true`): single resources — also those unrelated to this package — are returned without a `data` key.

```jsonc
// GET /api/posts — keys from consistentapi.pagination
{
  "items": [ { "id": 1, "title": "…" } ],
  "meta": { "current_page": 1, "last_page": 3, "from": 1, "to": 10, "total": 25, "per_page": 10, "path": "http://localhost/api/posts" }
}
// GET /api/posts/1, POST, PUT/PATCH — the resource fields at the top level
{ "id": 1, "title": "…" }
```

- Page size: `perpage` (one of `10, 15, 25, 50, 100` by default) or the model's `$perPage`, which is `10` on `ConsistentModel`. `from` / `to` are `null` on an empty page.
- Rows are ordered by the primary key after the requested sorts, so pages are stable.
- A model implementing `Adonyarik\ConsistentApi\Contracts\WithoutPaginationModelContract` (no methods) returns `{"items": [...]}` without `meta` when the request has `paginate=false` or `0`, at most `consistentapi.pagination.unpaginated_limit` (1000) rows. Use it for short dictionaries.
- `meta.path` comes from the request's `Host` header; enable `$middleware->trustHosts()` in production.
- Validation errors from `indexLogic()` are standard 422 `{"message": "<first error>", "errors": {"filter.x": […]}}`, like a failed form request.
- With `app.debug` off, a missing route-bound model under the API prefix is `404 {"message": "Not found."}` (`consistentapi.hide_model_not_found`).
- `new \Adonyarik\ConsistentApi\Responses\PaginatedJsonResponse($paginator, PostResource::class)` builds the paginated shape for your own queries; it takes any `LengthAwarePaginator` contract and merges the collection's `with()` / `additional()` data.

## Scoped and custom list endpoints

For a scoped list (the current user's posts, a post's comments) override `indexQuery()` (see Hooks) and keep `indexLogic()`: route parameters are available there as `$request->route('post')`. For an endpoint `indexLogic()` cannot express, build the query with the model's scopes:

```php
use Adonyarik\ConsistentApi\Responses\PaginatedJsonResponse;
use Adonyarik\ConsistentApi\Support\AccessResolver;

public function index(SearchCommentRequest $request, Post $post): JsonResponse
{
    $input = $request->validated();
    $accesses = app(AccessResolver::class)->current();

    $comments = Comment::query()
        ->where('comments.post_id', $post->id)
        ->filter($input['filter'] ?? [], $accesses)
        ->sort($input['sort'] ?? [], $accesses)
        ->orderBy('comments.id')
        ->with($this->relationFunctions)
        ->paginate(isset($input['perpage']) ? (int) $input['perpage'] : null, page: isset($input['page']) ? (int) $input['page'] : null);

    return new PaginatedJsonResponse($comments, $this->resourceClassFor());
}
```

The `filter()` / `sort()` scopes skip undeclared columns and malformed values silently; only `indexLogic()` answers 422 for them. Qualify your own columns (`comments.post_id`) and end with an `orderBy` on a unique column for stable pages. Authorize the action yourself (`$this->authorize('viewAny', Comment::class)`).

## Nested routes (`Credibility`)

```php
use Adonyarik\ConsistentApi\Traits\Credibility;

class Comment extends ConsistentModel
{
    use Credibility;
}

// Route::get('posts/{post}/comments/{comment}', [CommentController::class, 'show']);
public function show(Post $post, Comment $comment): JsonResource
{
    $comment->checkModelCredibility($post, 'post_id'); // 404 "The model is not credible." on mismatch

    return $this->selectLogic($comment);
}
```

`isCredible($post, 'post_id')` returns the bool instead. It compares the foreign key with the parent's primary key (`getKey()`, not the route key), so it works with UUID route keys; pass `ownerKey: 'uuid'` when the foreign key references another column. Optional arguments: status, message, owner key.

## `ConsistentModel` helpers

- `joinOnce()`, `leftJoinOnce()`, `rightJoinOnce()` — protected, for scopes: `$this->joinOnce($query, 'users', 'users.id', '=', 'posts.user_id')`. Skipped when the query already joins that table (any join type, table compared as written, so `'users as u'` differs). Relation sorts add no joins, so they combine with any scope. A single column comparison only; use `join()` for closures.
- `getAllColumns()` — column names of the table on the model's own connection (one schema query per call).
- Factories: a `$factory` property or `#[UseFactory]` wins, then `Database\Factories\{Model}Factory` (told which model it creates), then Laravel's resolver. Module models need no `newFactory()`; two models with the same short name should use `#[UseFactory]`.
- `$perPage = 10`.

## Middleware

| Alias                         | Behaviour |
| ----------------------------- | --------- |
| `consistent.api-json`         | sets `Accept: application/json` on URLs under `api_url_prefix` unless the client asks for a specific type (`text/csv`, `application/vnd.api+json` are kept; none, `*/*` and browser HTML are replaced); global by default (`api_json_enabled`), so 404 / 405 / 413 / maintenance errors there are JSON |
| `consistent.ensure-json`      | 415 `{"message": …}` for POST / PUT / PATCH with a body but without a JSON `Content-Type`; body-less action requests pass |
| `consistent.ensure-multipart` | 415 `{"message": …}` for anything but a POST (also POST with `_method=PUT/PATCH`) with `multipart/form-data`; attach only to upload routes |

```php
Route::middleware('consistent.ensure-json')->group(function () { /* … */ });
Route::post('files', UploadController::class)->middleware('consistent.ensure-multipart');
```

With `api_json_enabled = false`, attaching `consistent.api-json` as route middleware leaves unmatched-route 404 / 405 as HTML.

## `Route::development()`

```php
Route::development(function () {
    Route::get('_debug/ping', fn () => ['ok' => true]);
});
```

The callback runs only in the `local` environment; elsewhere the routes do not exist, and routes from a route cache built in `local` answer 404 elsewhere. Works in `routes/*.php` and module `Routes.php` files; in a module the URI gets the API prefix (`/api/_debug/ping`).

## Feature tests

- Module URLs carry the prefix: `$this->getJson('/api/posts?filter[title]=a&sort[id]=desc&perpage=15')`.
- Assert lists with `->assertJsonPath('meta.total', 3)` / `->assertJsonCount(3, 'items')`, single resources without `data.`: `->assertJsonPath('id', $post->id)`.
- Store returns 201, destroy 204, rejected filter/sort input 422 with `assertJsonValidationErrors(['filter.email'])`, a policy denial 403, a cancelled write 409.
- Startup validation runs in `testing`: a broken `#[Filterable]` / `#[Sortable]` makes every test fail at boot with the exception message.
- Relation sorts work on SQLite too.
- Routes protected by access groups need the group's middleware satisfied (`Sanctum::actingAs($user)` for `auth:sanctum`).
