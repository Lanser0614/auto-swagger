<?php

namespace AutoSwagger\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;

class SwaggerController extends Controller
{
    public function ui(): Response
    {
        if (!config('auto-swagger.ui.enabled', true)) {
            abort(404);
        }

        return response()->view('auto-swagger::swagger', [
            'title' => config('auto-swagger.title'),
            'theme' => config('auto-swagger.ui.theme', 'dark'),
        ]);
    }

    public function json(): JsonResponse
    {
        $path = config('auto-swagger.output.json');
        if (!File::exists($path)) {
            abort(404, 'Swagger documentation not generated. Run php artisan swagger:generate');
        }

        return response()->json(
            json_decode(File::get($path), true)
        );
    }

    public function yaml(): Response
    {
        $path = config('auto-swagger.output.yaml');
        if (!File::exists($path)) {
            abort(404, 'Swagger documentation not generated. Run php artisan swagger:generate');
        }

        return response(File::get($path))
            ->header('Content-Type', 'application/x-yaml');
    }
}