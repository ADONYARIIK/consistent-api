---
name: consistent-api-filtering-sorting
description: "Filtering, sorting and pagination of list endpoints in adonyarik/consistent-api — #[Filterable] / #[Sortable] attributes on ConsistentModel, filter operators (eq, like, from, to, in and the optional ones), RelationFilter / RelationSort, columns restricted by access groups, BaseSearchRequest validation, the filter[...] / sort[...] / perpage / page / paginate query syntax, the 422 errors and the Invalid*Exception thrown by startup validation. Use when making a model filterable or sortable, adding search parameters to an index endpoint, writing tests or client requests for list endpoints, or fixing an InvalidFilterableOperatorException, InvalidRelationFilterException, InvalidRelationSortException or InvalidAccessException."
---

# Consistent API: filtering and sorting

Package `adonyarik/consistent-api`. This skill summarizes the package's behaviour, which is usually enough to work without opening its source. When unsure about a detail or the best way to do something, read the relevant class in `vendor/adonyarik/consistent-api/src` (every class and method has a phpDoc block) or the package README.

Related skills: `consistent-api-modules` (scaffolding, access groups), `consistent-api-controllers` (`indexLogic()`, response shape).

## Declaring columns

Columns are declared only on the model class with attributes; there is no `$filterable` property. The attributes are read from the class itself, not inherited from a parent model.

```php
use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationFilter;
use Adonyarik\ConsistentApi\Support\RelationSort;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Filterable([
    'title',                                      // plain: scalar value, substring match
    'status' => ['eq', 'in'],                     // operators without value rules
    'price' => [                                  // operators with value rules; min/max are off in config by default
        'min' => ['numeric', 'min:0'],
        'max' => ['numeric', 'min:0'],            // not gte:filter.price.min: it fails when min is missing
    ],
    'created_at' => ['from' => ['date'], 'to' => ['date', 'after_or_equal:filter.created_at.from']],
    'author' => new RelationFilter(relation: 'user', column: 'name'),               // like (default)
    'tags' => new RelationFilter(relation: 'tags', column: 'slug', operator: 'eq'),
    'author_id' => new RelationFilter('user', 'id', 'eq', ['integer']),            // value rules
    'nickname' => new RelationFilter('user.profile', 'nickname'),                  // dotted relation path
])]
#[Filterable(['email'], accesses: ['admin'])]    // only for routes with the admin group
#[Sortable([
    'id',
    'created_at',
    'author' => new RelationSort(relation: 'user', column: 'name'),
])]
#[Sortable(['salary'], accesses: ['admin', 'manager'])]
class Post extends ConsistentModel
{
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function tags(): BelongsToMany { return $this->belongsToMany(Tag::class); }
}
```

- Plain names are columns of the model's own table, queried as `{table}.{column}`. For a foreign key or anything on another table use `RelationFilter` / `RelationSort` with the name of a relation method.
- Operator lists and rule maps can be mixed: `'status' => ['in', 'eq' => ['string', 'max:20']]`. Write rules as arrays, not pipe strings. Rules apply to the operator's value, which is a list for `in` / `not_in`; `nullable` is added automatically, so empty values never fail them.
- Give every operator on a non-text column a type rule (`integer`, `numeric`, `date`): without one PostgreSQL cannot compare a value like `abc` and the controller answers 422 on `filter`, while MySQL / SQLite silently find nothing.
- A filterable column can be guessed character by character even if it is `#[Hidden]`: never make secrets filterable, or restrict them with `accesses`.
- The array key is the public input name, so `'author'` can point to relation `user`.
- `consistent:create` generates `#[Filterable([])]` / `#[Sortable([])]`: an empty or missing attribute rejects every `filter` / `sort` input with 422.
- Both attributes are repeatable. A column may appear in only one `#[Filterable]` and one `#[Sortable]` of a model.

## Query syntax

```http
GET /api/posts?filter[title]=hello                        plain column: LIKE '%hello%' (ILIKE on PostgreSQL; case per collation elsewhere)
GET /api/posts?filter[status][eq]=published
GET /api/posts?filter[status][in]=draft,published         comma string or array
GET /api/posts?filter[status][]=draft&filter[status][]=published    plain list = in
GET /api/posts?filter[price][min]=10&filter[price][max]=50
GET /api/posts?filter[created_at][from]=2024-01-01&filter[created_at][to]=2024-12-31
GET /api/posts?filter[author]=ann                         relation: whereHas on users.name
GET /api/posts?sort[created_at]=desc&sort[id]=asc         ORDER BY in input order
GET /api/posts?perpage=25&page=2
```

| Operator | SQL                                              | Enabled by default |
| -------- | ------------------------------------------------ | ------------------ |
| `eq`     | `= value`                                        | yes |
| `like`   | escaped `LIKE '%value%'` / `ILIKE`               | yes |
| `from`   | `>= 'YYYY-MM-DD'` of the value's day             | yes |
| `to`     | `< 'YYYY-MM-DD'` of the next day                 | yes |
| `in`     | `IN (values)`                                    | yes |
| `not_eq` | `!= value`                                       | no  |
| `min`    | `>= value`                                       | no  |
| `max`    | `<= value`                                       | no  |
| `not_in` | `NOT IN (values)`                                | no  |
| `null`   | `true/1/on/yes` → `whereNull`, `false/0/off/no` → `whereNotNull` | no |

An operator must be enabled in `consistentapi.filters.operators` (uncomment it in `config/consistentapi.php` after publishing) before a model may declare it; otherwise boot fails in local/testing, and requests get 422 from both the search request and the controller.

- `from` / `to` take `YYYY-MM-DD` with an optional time (`2024-01-10T08:30:00+02:00`); only the day counts. Impossible dates (`2024-02-30`) and words (`next monday`) are 422. Works on date and datetime columns of every database, fractional seconds included.

- Substring matches (plain columns, `like`, relation filters with `like`) escape `%` and `_`: the value is matched literally.
- Empty values (`filter[title]=`, `filter[price][min]=`, `sort[id]=`) mean "not set" and are ignored.
- A plain list (`filter[status][]=a&filter[status][]=b`) is rewritten to `in` before validation, so the rules declared for `in` apply to it.
- `RelationFilter` runs `whereHas()` (no duplicate rows, any relation type, dotted paths) and takes a single scalar; its operator is only `eq` or `like`, written exactly so; the optional fourth argument holds value rules.
- `RelationSort` orders by a correlated subquery that takes the smallest non-null related value for `asc` and the largest for `desc`; no join, no duplicate rows, correct `total`. It respects the relation's own `where`, `ofMany()`, pivot conditions, soft deletes and other global scopes, custom keys and morph types, compares values by their type (`9` before `100`), and works on every database including SQLite. Every relation type works except `MorphTo`.
- Ties: add another sort; `indexLogic()` always adds the primary key last.
- The SQL dialect follows the model's connection, not the default connection.

## Validation and 422 errors

`ConsistentController::indexLogic()` checks all input before the query runs, independently of the search request's rules. Errors use Laravel's shape:

```json
{"message": "Filtering by email is not allowed.", "errors": {"filter.email": ["Filtering by email is not allowed."]}}
```

| Input                                                    | Error key / message |
| -------------------------------------------------------- | ------------------- |
| undeclared column, or column restricted to other groups  | `filter.x`: `Filtering by x is not allowed.` (`sort.x`: `Sorting by x is not allowed.`) |
| model has no column available                            | `filter`: `Filtering on this object is not allowed.` (`Sorting on this object …`) |
| operator not declared for the column, or disabled in config (request without operator rules) | `filter.x.op`: `Filtering by x.op is not allowed.` |
| nested value on a plain or relation column               | `Nested filtering by x is not allowed.` |
| scalar on a column declared with operators               | `The filter.x field must be an array.` (`Filtering by x requires an operator.` without `$model`) |
| array for a single-value operator / nested arrays in `in`| `Filtering by x.op takes a single value.` / `takes a list of values.` |
| invalid date for `from`/`to`, non-boolean for `null`     | `takes a date.` / `takes true or false.` |
| value the column type rejects (PostgreSQL)               | `filter`: `A filter value does not match the type of its column.` |
| operator disabled in config                              | `The filter operators [op] for "x" are not enabled.` |
| sort direction other than `asc` / `desc`                 | 422 on `sort.x` |
| `perpage` not in config values, `page` < 1 or > 1,000,000, bad `paginate` | Laravel validation 422 |

A restricted column answers exactly like a nonexistent one, so responses do not reveal it.

## Search request (`BaseSearchRequest`)

```php
use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;

class SearchPostRequest extends BaseSearchRequest
{
    protected string $model = Post::class; // value rules come from the model's #[Filterable]

    public function rules(): array
    {
        return [...parent::rules(), 'filter.title' => ['nullable', 'string', 'max:100']];
    }
}
```

Base rules: `perpage` — one of `config('consistentapi.pagination.per_page')` (`10, 15, 25, 50, 100` by default); `page` — integer from 1 to `BaseSearchRequest::MAX_PAGE` (1,000,000); `paginate` — `true`/`false`/`0`/`1`; `sort.*` — `asc`/`desc`; `filter.*` — scalar or keys enabled in config. With `$model`, per-operator rules from `#[Filterable]` apply to `filter.{column}.{operator}` and `RelationFilter` rules to `filter.{column}`, only for columns the route may use. Override `rules()` by merging `parent::rules()`; an overridden `prepareForValidation()` must call the parent.

## Pagination

- Without `perpage` the page size is the model's `$perPage`: `10` for `ConsistentModel`. Override `protected $perPage` on the model.
- `paginate=false` returns the rows without meta (at most `consistentapi.pagination.unpaginated_limit`, default 1000) only when the model implements `Adonyarik\ConsistentApi\Contracts\WithoutPaginationModelContract`; otherwise it is ignored.
- Response shape: see `consistent-api-controllers`.

## Access-restricted columns

A column of an attribute with `accesses: [...]` exists only for routes having at least one of those groups from `consistentapi.accesses` (a route has a group when it runs all of the group's middleware). Outside HTTP (jobs, commands, tinker) there is no route, so only unrestricted columns apply unless you pass the groups:

```php
Post::query()->filter($filters, ['admin'])->sort($sorts, ['admin'])->get();
(new Post)->getAllowedFilters(['admin']);   // list of column names
```

The scopes `filter()` / `sort()` and `isFilterable()`, `getAllowedFilters()`, `getAllowedFilterKeys()`, `filterRulesMap()`, `isSortable()`, `getAllowedSorts()` take the groups as the last argument; `null` means the current route's groups. Called directly, the scopes skip unknown columns, malformed values and directions other than `asc` / `desc` (case-insensitive) silently instead of throwing; only `indexLogic()` answers 422. `filter()` and `sort()` select `{table}.*` when nothing is selected yet. `$model->filterValueError($operator, $value)` returns why a value would be rejected, or `null`.

## Startup validation

With `consistentapi.filters.validate_on_boot` (env `CONSISTENTAPI_VALIDATE_ON_BOOT`, default `true`) and the app in `local` or `testing`, the package scans `consistentapi.filters.model_paths` (`app/Models`, `app/Modules/*/Models`; `*` wildcards allowed; every class of every file; abstract classes skipped) on every boot and throws on the first bad declaration — tests fail at boot, not on a request:

| Exception                              | Cause and fix |
| -------------------------------------- | ------------- |
| `InvalidFilterableOperatorException`   | a declared operator is not enabled in `consistentapi.filters.operators` — enable it or remove it |
| `InvalidRelationFilterException`       | `RelationFilter` operator other than `eq`/`like`, or a relation method of its (dotted) path is missing or does not return an Eloquent `Relation` |
| `InvalidRelationSortException`         | `RelationSort` relation method missing, not a relation, or a `MorphTo` |
| `InvalidAccessException`               | a group in `accesses:` is missing from `consistentapi.accesses`, or a column is declared in two `#[Filterable]` (or two `#[Sortable]`) |

All live in `Adonyarik\ConsistentApi\Exceptions`. The scan does not check that plain columns exist in the table. Production never runs it. If you rename `consistentapi.modules_folder`, update `consistentapi.filters.model_paths` too.
