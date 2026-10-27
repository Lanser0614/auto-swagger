<?php

namespace Tests\Feature;

use cebe\openapi\Reader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Parsing\GraphController;
use Tests\Fixtures\Parsing\InlineRequestController;
use Tests\TestCase;

class AnalysisOptionsTest extends TestCase
{
    public function test_artisan_and_http_deliver_valid_enhanced_json_and_yaml(): void
    {
        Route::get('graph/cycle', [GraphController::class, 'cycle']);
        Route::get('graph/order', [GraphController::class, 'order']);
        Route::post('inline', [InlineRequestController::class, 'store']);
        config(['auto-swagger.analysis.use_references' => true, 'auto-swagger.analysis.response_wrapping' => 'laravel']);
        $this->artisan('swagger:generate')->assertExitCode(0);
        $this->artisan('swagger:generate', ['--format' => 'yaml'])->assertExitCode(0);
        $json = File::get(public_path('swagger/openapi.json'));
        $document = Reader::readFromJson($json);
        $this->assertTrue($document->validate(), json_encode($document->getErrors()));
        $yaml = Reader::readFromYaml(File::get(public_path('swagger/openapi.yaml')));
        $this->assertTrue($yaml->validate(), json_encode($yaml->getErrors()));
        $spec = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('Order', $spec['components']['schemas']);
        $this->assertArrayHasKey('Category', $spec['components']['schemas']);
        $this->assertFalse($spec['paths']['/inline']['post']['requestBody']['required']);
        $this->assertSame('number', $spec['paths']['/inline']['post']['requestBody']['content']['application/json']['schema']['properties']['value']['type']);
        $this->get('/api/documentation/json')->assertOk();
        $this->get('/api/documentation/yaml')->assertOk();
    }

    public function test_diagnostics_can_be_displayed_suppressed_or_made_strict(): void
    {
        Route::get('graph/unknown', [GraphController::class, 'unknown']);
        $this->assertSame(0, Artisan::call('swagger:generate'));
        $this->assertStringContainsString('Unable to infer a type', Artisan::output());
        $before = File::get(public_path('swagger/openapi.json'));
        config(['auto-swagger.analysis.unknown_types' => 'strict']);
        $this->assertSame(1, Artisan::call('swagger:generate'));
        $this->assertSame($before, File::get(public_path('swagger/openapi.json')));
        config(['auto-swagger.analysis.unknown_types' => 'legacy', 'auto-swagger.analysis.diagnostics' => false]);
        $this->assertSame(0, Artisan::call('swagger:generate'));
        $this->assertStringNotContainsString('Unable to infer a type', Artisan::output());
    }
}
