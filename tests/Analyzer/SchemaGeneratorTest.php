<?php

namespace Tests\Analyzer;

use AutoSwagger\Analyzer\SchemaGenerator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use PHPUnit\Framework\TestCase;

enum Status: int
{
    case Active = 1;
    case Disabled = 2;
}

class SchemaGeneratorTest extends TestCase
{
    public function test_nested_fields_are_independent_of_rule_order(): void
    {
        $rules = ['rows.*.count' => 'required|integer', 'rows' => 'required|array|min:2', 'rows.*.labels.*' => 'string', 'rows.*.labels' => 'array'];
        $generator = new SchemaGenerator;
        $schema = $generator->generateSchemaFromRules($rules);
        $this->assertEquals($schema, $generator->generateSchemaFromRules(array_reverse($rules, true)));
        $this->assertSame(2, $schema['properties']['rows']['minItems']);
        $this->assertSame(['count'], $schema['properties']['rows']['items']['required']);
        $this->assertSame('string', $schema['properties']['rows']['items']['properties']['labels']['items']['type']);
    }

    public function test_bounds_enums_nullable_and_conditional_required(): void
    {
        $schema = (new SchemaGenerator)->generateSchemaFromRules([
            'price' => 'between:0.25,99.95|numeric',
            'status' => [Rule::enum(Status::class)],
            'quantity' => 'in:1,2|integer',
            'name' => 'sometimes|required|string|max:20|nullable',
            'optional.child' => 'required|string',
        ]);
        $this->assertSame(['type' => 'number', 'minimum' => 0.25, 'maximum' => 99.95], $schema['properties']['price']);
        $this->assertSame(['type' => 'integer', 'enum' => [1, 2]], $schema['properties']['status']);
        $this->assertSame([1, 2], $schema['properties']['quantity']['enum']);
        $this->assertTrue($schema['properties']['name']['nullable']);
        $this->assertArrayNotHasKey('required', $schema);
        $this->assertSame(['child'], $schema['properties']['optional']['required']);
    }

    public function test_files_are_binary_without_incorrect_string_length_constraints(): void
    {
        $schema = (new SchemaGenerator)->generateSchemaFromRules(['files' => 'array', 'files.*' => [File::types(['jpg'])->max(2048)]]);
        $this->assertSame(['type' => 'string', 'format' => 'binary'], $schema['properties']['files']['items']);
        $this->assertTrue((new SchemaGenerator)->containsFile($schema));
    }

    public function test_empty_rules_serialize_properties_as_an_object(): void
    {
        $this->assertSame('{"type":"object","properties":{}}', json_encode((new SchemaGenerator)->generateSchemaFromRules([])));
    }

    public function test_fluent_rules_keep_enum_types_and_restrictions(): void
    {
        $schema = (new SchemaGenerator)->generateSchemaFromRules([
            'status' => [Rule::enum(Status::class)->only([Status::Active])],
            'quantity' => ['integer', Rule::in([1, 2])],
            'profile' => 'array|min:1',
            'profile.name' => 'required|string',
        ]);
        $this->assertSame(['type' => 'integer', 'enum' => [1]], $schema['properties']['status']);
        $this->assertSame([1, 2], $schema['properties']['quantity']['enum']);
        $this->assertSame(1, $schema['properties']['profile']['minProperties']);
        $this->assertArrayNotHasKey('minItems', $schema['properties']['profile']);
    }

    public function test_single_rule_objects_are_supported_without_array_wrapping(): void
    {
        $schema = (new SchemaGenerator)->generateSchemaFromRules(['status' => Rule::enum(Status::class)]);
        $this->assertSame(['type' => 'integer', 'enum' => [1, 2]], $schema['properties']['status']);
    }
}
