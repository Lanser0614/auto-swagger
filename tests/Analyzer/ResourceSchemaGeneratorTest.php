<?php

namespace Tests\Analyzer;

use AutoSwagger\Analyzer\ResourceSchemaGenerator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ItemModel;
use Tests\Fixtures\PropertyResource;
use Tests\TestCase;

class ResourceSchemaGeneratorTest extends TestCase
{
    public function test_resource_properties_and_collection_metadata(): void
    {
        $schema = (new ResourceSchemaGenerator)->generate(PropertyResource::class);
        $this->assertSame(['ids'], $schema['required']);
        $this->assertTrue($schema['properties']['ids']['nullable']);
        $this->assertSame(['type' => 'integer'], $schema['properties']['ids']['items']);
        $this->assertSame('email', $schema['properties']['email']['format']);
    }

    public function test_model_introspection_uses_its_own_connection(): void
    {
        config(['database.connections.catalog' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::connection('catalog')->getSchemaBuilder()->create('items', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('price', 8, 2);
            $table->boolean('active');
            $table->json('details')->nullable();
        });
        $schema = (new ResourceSchemaGenerator)->generate(ItemModel::class);
        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame('number', $schema['properties']['price']['type']);
        $this->assertSame('boolean', $schema['properties']['active']['type']);
        $this->assertSame('object', $schema['properties']['details']['type']);
        $this->assertTrue($schema['properties']['details']['nullable']);
    }

    public function test_top_level_resource_field_named_type_is_a_property(): void
    {
        $schema = (new ResourceSchemaGenerator)->generate(['type' => 'string']);
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['type' => 'string'], $schema['properties']['type']);
    }
}
