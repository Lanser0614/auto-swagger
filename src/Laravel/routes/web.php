<?php

use AutoSwagger\Laravel\Http\Controllers\SwaggerController;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => config('auto-swagger.route.prefix', 'api/documentation'),
    'middleware' => config('auto-swagger.route.middleware', ['web']),
], function () {
    Route::get('/', [SwaggerController::class, 'ui'])->name('swagger.ui');
    Route::get('/json', [SwaggerController::class, 'json'])->name('swagger.json');
    Route::get('/yaml', [SwaggerController::class, 'yaml'])->name('swagger.yaml');
});
