<?php

namespace AutoSwagger\Laravel;

use Illuminate\Support\ServiceProvider;
use AutoSwagger\Generator\OpenApiGenerator;

class AutoSwaggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/auto-swagger.php',
            'auto-swagger'
        );

        $this->app->singleton(OpenApiGenerator::class, function ($app) {
            return new OpenApiGenerator(
                title: config('auto-swagger.title', 'Laravel API'),
                version: config('auto-swagger.version', '1.0.0'),
                description: config('auto-swagger.description', '')
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/auto-swagger.php' => config_path('auto-swagger.php'),
            ], 'auto-swagger-config');

            $this->commands([
                Console\GenerateSwaggerCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'auto-swagger');
    }
}
