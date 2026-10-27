<?php

namespace Tests\Analyzer;

use AutoSwagger\Analyzer\OpenApiGenerator;
use AutoSwagger\Analyzer\RouteAnalyzer;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\ExampleController;
use Tests\TestCase;

class RouteAnalyzerTest extends TestCase
{
    public function test_metadata_parameters_and_response_schemas(): void
    {
        $spec = (new OpenApiGenerator)->generate();
        $operation = $spec['paths']['/api/items/{id}']['get'];
        $this->assertSame('Find item', $operation['summary']);
        $this->assertSame('Item details', $operation['description']);
        $this->assertSame('showItem', $operation['operationId']);
        $this->assertTrue($operation['deprecated']);
        $this->assertSame(['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']], $operation['parameters'][0]);
        $schema = $operation['responses'][200]['content']['application/json']['schema'];
        $this->assertSame('number', $schema['properties']['price']['type']);
        $this->assertSame('boolean', $schema['properties']['details']['properties']['active']['type']);
        $this->assertSame('Display name', $schema['properties']['name']['description']);
        $this->assertSame(['name'], $schema['required']);
        $this->assertSame(['description' => 'Not found'], $operation['responses'][404]);
        $this->assertArrayNotHasKey('/api/ignored', $spec['paths']);
    }

    public function test_request_rules_and_upload_media_type_are_inferred(): void
    {
        $operation = (new OpenApiGenerator)->generate()['paths']['/api/items']['post'];
        $body = $operation['requestBody'];
        $this->assertSame('Create data', $body['description']);
        $schema = $body['content']['multipart/form-data']['schema'];
        $this->assertSame(['type' => 'number', 'minimum' => 0.5], $schema['properties']['price']);
        $this->assertSame('integer', $schema['properties']['tags']['items']['type']);
        $this->assertSame(['id'], $schema['properties']['items']['items']['required']);
        $this->assertSame('binary', $schema['properties']['image']['format']);
        $this->assertArrayHasKey(422, $operation['responses']);
    }

    public function test_security_matches_middleware_instead_of_securing_public_routes(): void
    {
        $spec = (new OpenApiGenerator(['security' => ['apiKey' => ['enabled' => true]]]))->generate();
        $this->assertArrayNotHasKey('security', $spec['paths']['/api/items/{id}']['get']);
        $this->assertSame([['bearerAuth' => []]], $spec['paths']['/api/items']['get']['security']);
        $this->assertSame([['apiKey' => []]], $spec['paths']['/api/key']['get']['security']);
        $this->assertSame([['bearerAuth' => [], 'apiKey' => []]], $spec['paths']['/api/combined']['get']['security']);
        $list = $spec['paths']['/api/items']['get'];
        $this->assertSame('integer', $list['parameters'][0]['schema']['type']);
        $this->assertFalse($list['parameters'][1]['required']);
        $this->assertSame('array', $list['responses'][200]['content']['application/json']['schema']['properties']['data']['type']);
    }

    public function test_multi_method_and_invokable_routes_have_unique_operations(): void
    {
        $spec = (new OpenApiGenerator)->generate();
        $path = $spec['paths']['/api/items/{id}'];
        $this->assertArrayHasKey('put', $path);
        $this->assertArrayHasKey('patch', $path);
        $this->assertNotSame($path['put']['operationId'], $path['patch']['operationId']);
        $this->assertArrayHasKey('/api/invoke', $spec['paths']);
        $this->assertArrayNotHasKey('head', $path);
        $this->assertSame([200 => ['description' => 'Successful operation']], $path['patch']['responses']);
    }

    public function test_duplicate_explicit_operation_ids_are_rejected(): void
    {
        Route::get('api/duplicate/{id}', [ExampleController::class, 'show']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate operationId');
        (new OpenApiGenerator)->generate();
    }

    public function test_nested_array_resource_shorthand_is_preserved(): void
    {
        $schema = (new RouteAnalyzer)->generateResourceSchemaFromArray([
            'settings' => ['enabled' => 'bool'],
            'rows' => ['type' => 'array', 'items' => ['type' => 'number']],
        ]);
        $this->assertSame('boolean', $schema['properties']['settings']['properties']['enabled']['type']);
        $this->assertSame(['type' => 'number'], $schema['properties']['rows']['items']);
    }
}
