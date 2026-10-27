<?php

namespace Tests\Analyzer;

use AutoSwagger\Analyzer\ResourceSchemaGenerator;
use Tests\Fixtures\Parsing\BranchResource;
use Tests\Fixtures\Parsing\CategoryResource;
use Tests\Fixtures\Parsing\CollisionResource;
use Tests\Fixtures\Parsing\DataFlowResource;
use Tests\Fixtures\Parsing\GenericResource;
use Tests\Fixtures\Parsing\InheritedOrderResource;
use Tests\Fixtures\Parsing\OrderResource;
use Tests\Fixtures\Parsing\OverriddenResource;
use Tests\Fixtures\Parsing\PlainResource;
use Tests\Fixtures\Parsing\StructuralResource;
use Tests\Fixtures\Parsing\UnknownResource;
use Tests\TestCase;

class ResourceParsingTest extends TestCase
{
    public function test_nested_resources_aliases_conditionals_and_model_casts_are_inferred_without_execution(): void
    {
        $generator = new ResourceSchemaGenerator;
        $schema = $generator->generate(OrderResource::class);
        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame('boolean', $schema['properties']['active']['type']);
        $this->assertSame('string', $schema['properties']['customer']['properties']['name']['type']);
        $this->assertSame('array', $schema['properties']['items']['type']);
        $this->assertSame('string', $schema['properties']['items']['items']['properties']['price']['type']);
        $this->assertSame('integer', $schema['properties']['items']['items']['properties']['quantity']['type']);
        $this->assertNotContains('items', $schema['required']);
        $this->assertNotContains('note', $schema['required']);
        $this->assertArrayNotHasKey('nullable', $schema['properties']['note']);
        $this->assertTrue($schema['properties']['nickname']['nullable']);
        $this->assertContains('nickname', $schema['required']);
        $this->assertNotContains('secret', $schema['required']);
        $this->assertSame('integer', $schema['properties']['count']['type']);
        $this->assertSame([], $generator->getDiagnostics());
    }

    public function test_references_are_opt_in_and_cycles_work_in_both_modes(): void
    {
        $generator = new ResourceSchemaGenerator(['use_references' => true]);
        $this->assertSame(['$ref' => '#/components/schemas/Order'], $generator->generate(OrderResource::class));
        $this->assertArrayHasKey('Product', $generator->getSchemas());
        $this->assertSame(['$ref' => '#/components/schemas/Customer'], $generator->getSchemas()['Order']['properties']['customer']);
        $inline = new ResourceSchemaGenerator;
        $category = $inline->generate(CategoryResource::class);
        $this->assertSame(['$ref' => '#/components/schemas/Category'], $category['properties']['children']['items']);
        $this->assertArrayHasKey('Category', $inline->getSchemas());
    }

    public function test_unknown_types_are_reported_and_can_be_permissive_or_strict(): void
    {
        $legacy = new ResourceSchemaGenerator;
        $this->assertSame('string', $legacy->generate(UnknownResource::class)['properties']['total']['type']);
        $this->assertStringContainsString('total', $legacy->getDiagnostics()[0]['context']);
        $this->assertIsInt($legacy->getDiagnostics()[0]['line']);
        $permissive = new ResourceSchemaGenerator(['unknown_types' => 'permissive']);
        $this->assertArrayNotHasKey('type', $permissive->generate(UnknownResource::class)['properties']['total']);
        $this->expectException(\RuntimeException::class);
        (new ResourceSchemaGenerator(['unknown_types' => 'strict']))->generate(UnknownResource::class);
    }

    public function test_explicit_overrides_win_without_unknown_diagnostics(): void
    {
        $generator = new ResourceSchemaGenerator(['resource_inference' => 'merge', 'unknown_types' => 'strict']);
        $schema = $generator->generate(OverriddenResource::class);
        $this->assertSame('number', $schema['properties']['total']['type']);
        $this->assertSame(10.5, $schema['properties']['total']['example']);
        $this->assertSame([], $generator->getDiagnostics());
    }

    public function test_branch_dependent_properties_are_optional(): void
    {
        $schema = (new ResourceSchemaGenerator)->generate(BranchResource::class);
        $this->assertSame(['id'], $schema['required']);
        $this->assertArrayHasKey('secret', $schema['properties']);
        $this->assertArrayHasKey('public', $schema['properties']);
    }

    public function test_array_assignments_and_alias_bindings_are_analyzed_per_branch(): void
    {
        $schema = (new ResourceSchemaGenerator)->generate(DataFlowResource::class);
        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame('string', $schema['properties']['version']['type']);
        $this->assertSame('integer', $schema['properties']['nested']['properties']['count']['type']);
        $this->assertNotContains('secret', $schema['required']);
        $this->assertContains('nested', $schema['required']);
    }

    public function test_phpdoc_generics_resolve_aliases(): void
    {
        $schema = (new ResourceSchemaGenerator)->generate(GenericResource::class);
        $this->assertSame('array', $schema['properties']['entries']['type']);
        $this->assertSame('integer', $schema['properties']['entries']['items']['properties']['id']['type']);
    }

    public function test_explicit_schema_name_collisions_are_rejected(): void
    {
        $generator = new ResourceSchemaGenerator(['use_references' => true]);
        $generator->generate(PlainResource::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Schema name Plain');
        $generator->generate(CollisionResource::class);
    }

    public function test_array_union_lists_spreads_and_overwritten_optional_fields(): void
    {
        $schema = (new ResourceSchemaGenerator)->generate(StructuralResource::class);
        $this->assertSame('integer', $schema['properties']['id']['type']);
        foreach (['list', 'spread'] as $name) {
            $this->assertSame(['type' => 'integer'], $schema['properties'][$name]['items']);
        }
        $this->assertSame('string', $schema['properties']['helpers']['items']['type']);
        $this->assertNotContains('optional', $schema['required']);
        $this->assertArrayNotHasKey('removed', $schema['properties']);
    }

    public function test_inherited_resources_keep_their_parent_model_hint(): void
    {
        $generator = new ResourceSchemaGenerator;
        $schema = $generator->generate(InheritedOrderResource::class);
        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame([], $generator->getDiagnostics());
    }
}
