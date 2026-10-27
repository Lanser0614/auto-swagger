<?php

namespace AutoSwagger\Laravel\Http\Controllers;

use AutoSwagger\Laravel\DocumentationPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class SwaggerController extends Controller
{
    public function ui(): Response
    {
        abort_unless(config('auto-swagger.ui.enabled', true), 404);

        return response()->view('auto-swagger::swagger', [
            'title' => config('auto-swagger.title'),
            'theme' => config('auto-swagger.ui.theme', 'dark'),
        ]);
    }

    public function json(): JsonResponse
    {
        return response()->json(json_decode(File::get($this->documentation('json')), false, 512, JSON_THROW_ON_ERROR));
    }

    public function yaml(): Response
    {
        return response(File::get($this->documentation('yaml')))->header('Content-Type', 'application/yaml');
    }

    private function documentation(string $format): string
    {
        if (config('auto-swagger.generate_always', false)) {
            $status = Artisan::call('swagger:generate', ['--format' => $format]);
            abort_if($status !== 0, 500, 'Swagger documentation generation failed. Check the application logs.');
        }
        $path = DocumentationPath::resolve(config('auto-swagger.directory'), config("auto-swagger.output.{$format}"));
        abort_unless(File::isFile($path), 404, 'Swagger documentation not generated. Run php artisan swagger:generate --format='.$format);

        return $path;
    }
}
