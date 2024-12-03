<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Swagger Documentation Settings
    |--------------------------------------------------------------------------
    */
    'title' => env('APP_NAME', 'Laravel') . ' API',
    'version' => '1.0.0',
    'description' => 'API Documentation',

    /*
    |--------------------------------------------------------------------------
    | Route Settings
    |--------------------------------------------------------------------------
    */
    'route' => [
        'prefix' => 'api/documentation',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Controllers Paths
    |--------------------------------------------------------------------------
    | Add the paths to your controller directories that should be scanned
    | for API documentation
    */
    'controllers' => [
        app_path('Http/Controllers'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Settings
    |--------------------------------------------------------------------------
    */
    'output' => [
        'json' => public_path('swagger/openapi.json'),
        'yaml' => public_path('swagger/openapi.yaml'),
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Settings
    |--------------------------------------------------------------------------
    */
    'ui' => [
        'enabled' => true,
        'theme' => 'dark', // light or dark
    ],

    'auth' => [
        'bearer' => [
            'enabled' => true
        ],
        'oauth2' => [
            'enabled' => false
        ],
        'apiKey' => [
            'enabled' => true
        ],
    ]
];
