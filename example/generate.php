<?php

// Run from an installed Laravel application:
// php vendor/auto-swagger/php-swagger-generator/example/generate.php /path/to/laravel

use AutoSwagger\Analyzer\OpenApiGenerator;
use Example\UserController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;

$applicationRoot = $argv[1] ?? getcwd();
$bootstrap = rtrim($applicationRoot, '/').'/bootstrap/app.php';
if (! is_file($bootstrap)) {
    fwrite(STDERR, "Provide the path to a Laravel application containing bootstrap/app.php.\n");
    exit(1);
}
require_once rtrim($applicationRoot, '/').'/vendor/autoload.php';
require_once __DIR__.'/UserController.php';
$app = require $bootstrap;
$app->make(Kernel::class)->bootstrap();
Route::get('api/example/users/{id}', [UserController::class, 'getUser']);
$generator = new OpenApiGenerator([
    'title' => 'Example API',
    'version' => '1.0.0',
    'description' => 'Example API documentation generated with AutoSwagger',
    'servers' => [['url' => config('app.url')]],
]);
echo json_encode($generator->generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
