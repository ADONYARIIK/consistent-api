<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Modules folder
    |--------------------------------------------------------------------------
    |
    | Folder under app/ where modules are stored. Every segment must be a PHP
    | identifier (Modules, Domain/Modules); the namespace follows the
    | application namespace (App\Modules, App\Domain\Modules).
    |
    */

    'modules_folder' => 'Modules',

    /*
    |--------------------------------------------------------------------------
    | Request amount limit
    |--------------------------------------------------------------------------
    |
    | Limit for max. amount of requests to api per minute.
    |
    */

    'request_limit' => 60,

    /*
    |--------------------------------------------------------------------------
    | Url prefix for api routes
    |--------------------------------------------------------------------------
    |
    | Prefix of the module routes and of the URLs ApiJsonMiddleware treats as
    | API requests. routes/api.php keeps the prefix set in bootstrap/app.php.
    |
    */

    'api_url_prefix' => 'api',

    /*
    |--------------------------------------------------------------------------
    | Default middlewares
    |--------------------------------------------------------------------------
    |
    | Default middleware used for API endpoints.
    |
    */

    'middlewares' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | JSON for API URLs
    |--------------------------------------------------------------------------
    |
    | Prepends ApiJsonMiddleware to the global middleware, so every request
    | under the API prefix gets JSON errors, 404 and 405 included. Turn it
    | off to attach the consistent.api-json alias yourself.
    |
    */

    'api_json_enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Resources without the "data" wrapper
    |--------------------------------------------------------------------------
    |
    | Calls JsonResource::withoutWrapping() while booting. It applies to EVERY
    | resource of the application, including resources that do not come from
    | this package: they are no longer wrapped in "data". Turn it off to keep
    | Laravel's default wrapping.
    |
    */

    'without_wrapping' => true,

    /*
    |--------------------------------------------------------------------------
    | Hide model names in 404 responses
    |--------------------------------------------------------------------------
    |
    | With app.debug off, a missing route-bound model under the API prefix
    | answers {"message": "Not found."} instead of Laravel's
    | "No query results for model [App\...] 1", which names the class.
    |
    */

    'hide_model_not_found' => true,

    /*
    |--------------------------------------------------------------------------
    | UUID route keys
    |--------------------------------------------------------------------------
    |
    | Allows `consistent:create --uuid`. The generated model uses HasUuids,
    | the column below becomes its route key, routes get whereUuid().
    |
    */

    'uuid' => true,

    'uuid_column' => 'public_id',

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | data_container_name / meta_container_name: keys of the items and of the
    | meta block in PaginatedJsonResponse.
    |
    | per_page: the values a request may pass as perpage. Without perpage the
    | page size is the model's $perPage (10 for ConsistentModel).
    |
    | unpaginated_limit: most rows paginate=false returns for a model with
    | WithoutPaginationModelContract; null removes the limit.
    |
    */

    'pagination' => [

        'data_container_name' => 'items',

        'meta_container_name' => 'meta',

        'per_page' => [10, 15, 25, 50, 100],

        'unpaginated_limit' => 1000,

    ],

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    |
    | operators: the operators filter[column][operator]=value may use.
    | Uncomment the ones you need; only basic ones are enabled by default.
    | Both the search request and the controller reject the others.
    |
    | validate_on_boot: in the local and testing environments the package
    | scans model_paths on boot and throws when a #[Filterable] / #[Sortable]
    | attribute declares an operator that is not listed in operators, a broken
    | RelationFilter / RelationSort, an access group missing from accesses
    | below, or a column that is already declared. Production never runs it.
    | Paths may contain * wildcards.
    |
    */

    'filters' => [

        'operators' => [
            'eq',
            // 'not_eq',
            'like',
            'from',
            'to',
            // 'min',
            // 'max',
            'in',
            // 'not_in',
            // 'null',
        ],

        'validate_on_boot' => env('CONSISTENTAPI_VALIDATE_ON_BOOT', true),

        'model_paths' => [
            app_path('Models'),
            app_path('Modules/*/Models'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Access groups
    |--------------------------------------------------------------------------
    |
    | 'name' => [middleware, ...]
    | Used by `consistent:create --accesses=method-name` and by the
    | `accesses:` argument of #[Filterable] / #[Sortable]. A request has
    | access "name" when its route runs every middleware of that group.
    | Names may contain letters, digits and _ . : -
    |
    */

    'accesses' => [
        // 'guest' => [],
        // 'unverified' => ['auth:sanctum'],
        // 'verified' => ['auth:sanctum', 'verified'],
        // 'admin' => ['auth:sanctum', 'verified', 'can:admin'],
    ],

];
