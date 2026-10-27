<?php

namespace Tests\Feature;

use AutoSwagger\Analyzer\OpenApiGenerator;
use AutoSwagger\Analyzer\ResourceSchemaGenerator;
use AutoSwagger\Generator\SchemaGenerator;
use Tests\Fixtures\ItemResource;
use Tests\Fixtures\Parsing\CollisionResource;
use Tests\Fixtures\Parsing\LegacyNamedResource;
use Tests\Fixtures\Parsing\PlainResource;
use Tests\TestCase;

class BackwardCompatibilityTest extends TestCase
{
    public function test_default_document_is_identical_to_the_previous_release(): void
    {
        $expected = json_decode(file_get_contents(__DIR__.'/../Fixtures/legacy-openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $actual = json_decode(json_encode((new OpenApiGenerator)->generate(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($expected, $actual);
    }

    public function test_original_generator_namespaces_and_resource_method_remain_available(): void
    {
        $this->assertSame((new OpenApiGenerator)->generate(), (new \AutoSwagger\Generator\OpenApiGenerator)->generate());
        $schemas = new SchemaGenerator;
        $this->assertSame('integer', $schemas->generateFromResource(ItemResource::class)['properties']['id']['type']);
        $this->assertSame('array', $schemas->generateFromResource(ItemResource::class, true)['type']);
        $this->assertSame([], $schemas->generateFromResource('MissingResource'));
        $this->assertSame([], $schemas->generateFromFormRequest('MissingRequest'));
    }

    public function test_repeated_generation_does_not_keep_stale_components_or_diagnostics(): void
    {
        $generator = new OpenApiGenerator;
        $first = $generator->generate();
        $this->assertSame($first, $generator->generate());
        $this->assertSame([], $generator->getDiagnostics());
    }

    public function test_legacy_inline_display_names_do_not_become_new_validation_errors(): void
    {
        $generator = new ResourceSchemaGenerator;
        $generator->generate(PlainResource::class);
        $collision = $generator->generate(CollisionResource::class);
        $legacyName = $generator->generate(LegacyNamedResource::class);
        $this->assertSame('integer', $collision['properties']['id']['type']);
        $this->assertSame('integer', $legacyName['properties']['id']['type']);
        $this->assertSame([], $generator->getSchemas());
    }
}
