<?php

require_once __DIR__ . '/../vendor/autoload.php';

use AutoSwagger\Generator\OpenApiGenerator;

// Create a new instance of the OpenAPI generator
$generator = new OpenApiGenerator(
    title: 'Example API',
    version: '1.0.0',
    description: 'Example API documentation generated with AutoSwagger'
);

// Add controllers to be processed
$generator->addController(\Example\UserController::class);

// Generate the OpenAPI specification
$specification = $generator->generate();

// Output the specification as JSON
file_put_contents(
    __DIR__ . '/openapi.json',
    json_encode($specification, JSON_PRETTY_PRINT)
);
