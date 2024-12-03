<?php

namespace AutoSwagger\Laravel;

use AutoSwagger\Laravel\Console\GenerateSwaggerCommand;
use Illuminate\Support\ServiceProvider;

class AutoSwaggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {

    }

    public function boot(): void
    {
        $this->commands([
            GenerateSwaggerCommand::class,
        ]);

        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'auto-swagger');
    }
}
