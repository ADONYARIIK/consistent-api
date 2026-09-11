<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed filter operators
    |--------------------------------------------------------------------------
    |
    | List of operators that can be used in filter[column][operator]=value.
    | Uncomment the ones you need — only basic ones are enabled by default.
    | Note: the operator must be implemented in CanFilter::applyArrayFilter(),
    | otherwise it will simply be silently ignored at the trait level.
    |
    */
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

    /*
    |--------------------------------------------------------------------------
    | Startup validation
    |--------------------------------------------------------------------------
    |
    | When enabled, the package will scan the directories below on boot
    | and throw an exception if any #[Filterable] attribute declares
    | an operator that is not listed in "operators" above.
    |
    | Disable in production to skip the filesystem scan on every request
    | (or cache config via `php artisan config:cache`, which is
    | recommended anyway and makes this check run only once).
    |
    */
    'validate_on_boot' => env('FILTERS_VALIDATE_ON_BOOT', true),

    'model_paths' => [
        app_path('Models'),
        app_path('Modules/*/Models'),
    ],

];
