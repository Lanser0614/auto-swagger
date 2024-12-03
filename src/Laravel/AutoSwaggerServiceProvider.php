<?php

namespace AutoSwagger\Laravel;

use AutoSwagger\Laravel\Console\GenerateSwaggerCommand;
use Illuminate\Support\ServiceProvider;

class AutoSwaggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/auto-swagger.php', 'auto-swagger'
        );
    }

    public function boot(): void
    {
        // Register commands
        $this->commands([
            GenerateSwaggerCommand::class,
        ]);

        // Register routes
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        
        // Register views
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'auto-swagger');

        // Publish configuration
        $this->publishes([
            __DIR__ . '/config/auto-swagger.php' => config_path('auto-swagger.php'),
        ], 'auto-swagger-config');

        // Publish views
        $this->publishes([
            __DIR__ . '/resources/views' => resource_path('views/vendor/auto-swagger'),
        ], 'auto-swagger-views');

        // Publish assets
        $this->publishes([
            __DIR__ . '/resources/assets' => public_path('vendor/auto-swagger'),
        ], 'auto-swagger-assets');
    }
}
