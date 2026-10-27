<?php

namespace Tests\Feature;

use AutoSwagger\Analyzer\OpenApiGenerator;
use cebe\openapi\Reader;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use JsonSchema\Validator;
use Tests\Fixtures\Parsing\GraphController;
use Tests\Fixtures\Parsing\NativeController;
use Tests\TestCase;

class ResourceInferenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['show', 'index', 'paginate', 'simple', 'cursor', 'collected', 'metadata', 'custom', 'wrapped', 'customCollection', 'explicit', 'customItems', 'paginationInformation', 'collidedMetadata'] as $method) {
            Route::get('native/'.$method, [NativeController::class, $method]);
        }
    }

    public function test_controller_return_values_are_inferred_and_explicit_responses_keep_priority(): void
    {
        $spec = (new OpenApiGenerator)->generate();
        $this->assertSame('integer', $this->schema($spec, 'show')['properties']['id']['type']);
        $this->assertSame('array', $this->schema($spec, 'index')['type']);
        $this->assertSame(['type' => 'boolean'], $this->schema($spec, 'explicit')['properties']['value']);
        $this->assertArrayNotHasKey('data', $this->schema($spec, 'show')['properties']);
    }

    public function test_laravel_wrapping_metadata_and_all_paginator_kinds(): void
    {
        $spec = (new OpenApiGenerator(['analysis' => ['response_wrapping' => 'laravel']]))->generate();
        $this->assertSame('integer', $this->schema($spec, 'show')['properties']['data']['properties']['id']['type']);
        $this->assertSame('array', $this->schema($spec, 'index')['properties']['data']['type']);
        $meta = $this->schema($spec, 'metadata')['properties']['meta']['properties'];
        $this->assertArrayHasKey('source', $meta);
        $this->assertArrayHasKey('request', $meta);
        $this->assertArrayHasKey('result', $this->schema($spec, 'custom')['properties']);
        $this->assertArrayNotHasKey('data', $this->schema($spec, 'wrapped')['properties']['data']['properties']);
        $this->assertArrayHasKey('stats', $this->schema($spec, 'customCollection')['properties']);
        $this->assertSame('array', $this->schema($spec, 'customCollection')['properties']['data']['type']);
        foreach (['paginate', 'simple', 'cursor', 'collected'] as $method) {
            $schema = $this->schema($spec, $method);
            $this->assertArrayHasKey('links', $schema['properties']);
            $this->assertArrayHasKey('meta', $schema['properties']);
            $this->assertSame('array', $schema['properties']['data']['type']);
        }
        $this->assertArrayHasKey('total', $this->schema($spec, 'paginate')['properties']['meta']['properties']);
        $this->assertArrayNotHasKey('total', $this->schema($spec, 'simple')['properties']['meta']['properties']);
        $this->assertArrayHasKey('next_cursor', $this->schema($spec, 'cursor')['properties']['meta']['properties']);
        $this->assertArrayHasKey('data', $this->schema($spec, 'customItems')['properties']);
        $this->assertArrayHasKey('extra', $this->schema($spec, 'paginationInformation')['properties']);
    }

    public function test_without_wrapping_is_observed_and_metadata_still_gets_a_data_envelope(): void
    {
        JsonResource::withoutWrapping();
        try {
            $spec = (new OpenApiGenerator(['analysis' => ['response_wrapping' => 'laravel']]))->generate();
            $this->assertArrayHasKey('id', $this->schema($spec, 'show')['properties']);
            $this->assertArrayHasKey('data', $this->schema($spec, 'metadata')['properties']);
            $this->assertArrayHasKey('data', $this->schema($spec, 'paginate')['properties']);
        } finally {
            JsonResource::wrap('data');
        }
    }

    public function test_actual_laravel_responses_conform_to_inferred_schemas(): void
    {
        $spec = (new OpenApiGenerator(['analysis' => ['response_wrapping' => 'laravel']]))->generate();
        foreach (['show', 'index', 'paginate', 'simple', 'cursor', 'collected', 'metadata', 'custom', 'wrapped', 'customCollection', 'customItems', 'paginationInformation', 'collidedMetadata'] as $method) {
            $response = $this->get('/native/'.$method)->assertOk();
            $validator = new Validator;
            $schema = $this->schema($spec, $method);
            $schema = $this->nullableToUnion($schema);
            $data = json_decode($response->getContent());
            $validator->validate($data, json_decode(json_encode($schema, JSON_THROW_ON_ERROR)));
            $this->assertTrue($validator->isValid(), $method.': '.json_encode($validator->getErrors()));
        }
    }

    public function test_reference_mode_emits_valid_components_and_does_not_create_nested_collection_arrays(): void
    {
        Route::get('native/cycle', [GraphController::class, 'cycle']);
        $spec = (new OpenApiGenerator(['analysis' => ['response_wrapping' => 'laravel', 'use_references' => true]]))->generate();
        $this->assertSame(['$ref' => '#/components/schemas/Plain'], $this->schema($spec, 'collected')['properties']['data']['items']);
        $this->assertArrayHasKey('Category', $spec['components']['schemas']);
        $document = Reader::readFromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        $this->assertTrue($document->validate(), json_encode($document->getErrors()));
    }

    private function schema(array $spec, string $method): array
    {
        return $spec['paths']['/native/'.$method]['get']['responses'][200]['content']['application/json']['schema'];
    }

    private function nullableToUnion(array $schema): array
    {
        if (($schema['nullable'] ?? false) && isset($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];
        }
        foreach (['properties', 'allOf', 'oneOf', 'anyOf'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                foreach ($schema[$key] as &$child) {
                    if (is_array($child)) {
                        $child = $this->nullableToUnion($child);
                    }
                }
                unset($child);
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->nullableToUnion($schema['items']);
        }

        return $schema;
    }
}
