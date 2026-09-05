<?php

return [

    /*
        |--------------------------------------------------------------------------
        | Data container name
    |--------------------------------------------------------------------------
    |
    | PaginatedJsonResponse data object name in response.
    |
    */
    'data_container_name' => 'items',

    /*
    |--------------------------------------------------------------------------
    | Meta container name
    |--------------------------------------------------------------------------
    |
    | PaginatedJsonResponse meta info object name in response.
    |
    */
    'meta_container_name' => 'meta',

    /*
    |--------------------------------------------------------------------------
    | Per page amount
    |--------------------------------------------------------------------------
    |
    | Amount of records to display per page.
    |
    */
    'per_page' => [
        'sm' => 10,
        'default' => 15,
        'md' => 25,
        'lg' => 50,
        'xl' => 100,
    ],

];
