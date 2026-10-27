<?php

namespace Tests;

use AutoSwagger\Laravel\AutoSwaggerServiceProvider;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use Tests\Fixtures\ExampleController;

abstract class TestCase extends Orchestra
{
    protected string $temporaryDirectory;

    protected function getPackageProviders($app): array
    {
        return [AutoSwaggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/auto-swagger-test-'.bin2hex(random_bytes(8));
        $app->usePublicPath($this->temporaryDirectory.'/public');
        $app['config']->set('app.url', 'https://example.test');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('session.driver', 'array');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineRoutes($router): void
    {
        $router->get('api/items/{id}', [ExampleController::class, 'show']);
        $router->post('api/items', [ExampleController::class, 'store']);
        $router->get('api/items', [ExampleController::class, 'index'])->middleware('auth:api');
        $router->get('api/key', [ExampleController::class, 'key'])->middleware('api_key');
        $router->get('api/combined', [ExampleController::class, 'key'])->middleware(['auth', 'api_key']);
        $router->match(['PUT', 'PATCH'], 'api/items/{id}', [ExampleController::class, 'update']);
        $router->get('api/ignored', [ExampleController::class, 'ignored']);
        $router->get('api/invoke', ExampleController::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory)) {
            File::deleteDirectory($this->temporaryDirectory);
        }
        parent::tearDown();
    }
}
