<?php

use AutoSwagger\Analysis\Options;

return [
    'title' => env('APP_NAME', 'Laravel').' API',
    'version' => '1.0.0',
    'description' => 'API Documentation',
    'contact' => [],
    'license' => [],

    'route' => [
        'prefix' => 'api/documentation',
        'middleware' => ['web'],
    ],

    // Null uses app.url. Route paths already include their API prefix.
    'servers' => null,

    'output' => [
        'json' => 'openapi.json',
        'yaml' => 'openapi.yaml',
    ],
    // Relative directories are resolved under public_path(). Absolute paths work too.
    'directory' => 'swagger',

    'ui' => [
        'enabled' => true,
        'theme' => 'dark',
    ],

    // Enabling a scheme makes it available in Swagger UI. Route middleware
    // determines which operations require it.
    'auth' => [
        'bearer' => ['enabled' => true],
        'oauth2' => [
            'enabled' => false,
            'flows' => [
                'password' => ['tokenUrl' => '/oauth/token', 'scopes' => []],
            ],
            'scopes' => [],
        ],
        'apiKey' => ['enabled' => true, 'in' => 'header', 'name' => 'X-API-Key'],
    ],

    // Defaults preserve annotated schemas and response shapes.
    'analysis' => Options::DEFAULTS,

    'generate_always' => env('SWAGGER_GENERATE_ALWAYS', false),
];
