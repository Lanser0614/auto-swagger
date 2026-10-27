<?php

namespace Tests\Analyzer;

use AutoSwagger\Analyzer\SchemaGenerator;
use AutoSwagger\Contracts\RuleSchemaExtension;
use AutoSwagger\Contracts\SchemaAwareRule;
use Illuminate\Validation\Rule;
use JsonSchema\Validator;
use Tests\Fixtures\Parsing\AnnotatedRequest;
use Tests\Fixtures\Parsing\ExplicitRequest;
use Tests\Fixtures\Parsing\StaticRequest;
use Tests\TestCase;

class RequestParsingTest extends TestCase
{
    public function test_regex_pipes_allowed_object_keys_and_deep_lists(): void
    {
        $schema = (new SchemaGenerator)->generateSchemaFromRules([
            'name' => 'required|string|regex:/^(foo|bar)$/|max:20',
            'profile' => 'array:name,age', 'profile.name' => 'string', 'profile.age' => 'integer',
            'orders' => 'list', 'orders.*.items' => 'list', 'orders.*.items.*.product_id' => 'required|integer',
            'v1\\.0' => 'string',
        ]);
        $this->assertSame('^(foo|bar)$', $schema['properties']['name']['pattern']);
        $this->assertSame(20, $schema['properties']['name']['maxLength']);
        $this->assertFalse($schema['properties']['profile']['additionalProperties']);
        $this->assertSame('integer', $schema['properties']['profile']['properties']['age']['type']);
        $this->assertSame('integer', $schema['properties']['orders']['items']['properties']['items']['items']['properties']['product_id']['type']);
        $this->assertArrayHasKey('v1.0', $schema['properties']);
    }

    public function test_required_conditions_apply_at_the_correct_array_item_scope(): void
    {
        $schema = (new SchemaGenerator)->generateSchemaFromRules([
            'items' => 'array', 'items.*.type' => 'string',
            'items.*.code' => 'string|required_if:items.*.type,digital',
        ]);
        $this->assertValid($schema, ['items' => [['type' => 'physical'], ['type' => 'digital', 'code' => 'A']]]);
        $this->assertInvalid($schema, ['items' => [['type' => 'digital']]]);
        $this->assertInvalid($schema, ['items' => [['type' => 'digital', 'code' => '']]]);
        $this->assertArrayHasKey('allOf', $schema['properties']['items']['items']);
    }

    public function test_required_with_and_without_are_conditional_instead_of_always_required(): void
    {
        $schema = (new SchemaGenerator)->generateSchemaFromRules([
            'email' => 'string', 'phone' => 'string',
            'name' => 'string|required_with:email,phone',
            'fallback' => 'string|required_without_all:email,phone',
        ]);
        $this->assertValid($schema, ['email' => 'a', 'name' => 'A']);
        $this->assertInvalid($schema, ['email' => 'a']);
        $this->assertValid($schema, ['fallback' => 'A']);
        $this->assertInvalid($schema, []);
    }

    public function test_excluded_fields_are_not_forbidden_input_and_prohibited_fields_are_checked(): void
    {
        $schema = (new SchemaGenerator)->generateSchemaFromRules([
            'kind' => 'string', 'value' => 'required|integer|exclude_if:kind,draft',
            'secret' => 'string|prohibited_if:kind,public',
            'removed' => 'exclude|string',
        ]);
        $this->assertValid($schema, ['kind' => 'draft', 'value' => 'Ignored input']);
        $this->assertValid($schema, ['kind' => 'draft']);
        $this->assertInvalid($schema, ['kind' => 'published']);
        $this->assertInvalid($schema, ['kind' => 'published', 'value' => 'wrong']);
        $this->assertValid($schema, ['kind' => 'public', 'value' => 1]);
        $this->assertInvalid($schema, ['kind' => 'public', 'value' => 1, 'secret' => 'private']);
        $this->assertArrayNotHasKey('removed', $schema['properties']);
    }

    public function test_foreach_callbacks_and_requiredif_callbacks_are_never_executed(): void
    {
        $executed = false;
        $callback = function () use (&$executed) {
            $executed = true;

            return ['integer', 'min:1'];
        };
        $condition = function () use (&$executed) {
            $executed = true;

            return true;
        };
        $generator = new SchemaGenerator;
        $schema = $generator->generateSchemaFromRules(['ids' => 'array', 'ids.*' => Rule::forEach($callback), 'note' => ['string', Rule::requiredIf($condition)]]);
        $this->assertFalse($executed);
        $this->assertSame(['type' => 'integer', 'minimum' => 1], $schema['properties']['ids']['items']);
        $this->assertNotContains('note', $schema['required'] ?? []);
        $this->assertNotEmpty($generator->getDiagnostics());
    }

    public function test_foreach_may_return_nested_field_rules(): void
    {
        $schema = (new SchemaGenerator)->generateSchemaFromRules([
            'items' => 'array',
            'items.*' => Rule::forEach(fn () => ['id' => ['required', 'integer']]),
        ]);
        $this->assertSame('integer', $schema['properties']['items']['items']['properties']['id']['type']);
        $this->assertSame(['id'], $schema['properties']['items']['items']['required']);
    }

    public function test_custom_rules_and_extension_adapters_can_define_schemas(): void
    {
        $rule = new class implements SchemaAwareRule
        {
            public function toOpenApiSchema(): array
            {
                return ['type' => 'number', 'minimum' => 0.01];
            }
        };
        $thirdPartyRule = new \stdClass;
        $adapter = new class implements RuleSchemaExtension
        {
            public function supports(object $rule): bool
            {
                return $rule instanceof \stdClass;
            }

            public function schema(object $rule): array
            {
                return ['type' => 'string', 'format' => 'uuid'];
            }
        };
        $schema = (new SchemaGenerator(['rule_extensions' => [$adapter]]))->generateSchemaFromRules(['price' => [$rule], 'id' => [$thirdPartyRule]]);
        $this->assertSame(['type' => 'number', 'minimum' => 0.01], $schema['properties']['price']);
        $this->assertSame('uuid', $schema['properties']['id']['format']);
    }

    public function test_static_requests_and_explicit_schemas_do_not_execute_application_code(): void
    {
        $schema = (new SchemaGenerator(['request_mode' => 'static']))->generateFromFormRequest(StaticRequest::class);
        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame(['active', 'disabled'], $schema['properties']['status']['enum']);
        $this->assertSame('number', (new SchemaGenerator)->generateFromFormRequest(ExplicitRequest::class)['properties']['value']['type']);
    }

    public function test_unknown_conditional_branches_are_not_evaluated_and_common_required_is_preserved(): void
    {
        $executed = false;
        $condition = function () use (&$executed) {
            $executed = true;

            return true;
        };
        $schema = (new SchemaGenerator)->generateSchemaFromRules([
            'value' => [Rule::when($condition, ['required', 'integer'], ['required', 'string'])],
            'optional' => [Rule::when($condition, ['integer'], [])],
        ]);
        $this->assertFalse($executed);
        $this->assertContains('value', $schema['required']);
        $this->assertNotContains('optional', $schema['required']);
        $this->assertSame('integer', $schema['properties']['value']['anyOf'][0]['type']);
        $this->assertSame('string', $schema['properties']['value']['anyOf'][1]['type']);
    }

    public function test_annotations_override_unknown_request_types_in_strict_mode(): void
    {
        $generator = new SchemaGenerator(['unknown_types' => 'strict']);
        $schema = $generator->generateFromFormRequest(AnnotatedRequest::class);
        $this->assertSame('number', $schema['properties']['total']['type']);
        $this->assertSame(10.5, $schema['properties']['total']['example']);
        $this->assertSame([], $generator->getDiagnostics());
    }

    public function test_permissive_arrays_can_be_scanned_for_uploads_and_pcre_is_not_misrepresented(): void
    {
        $generator = new SchemaGenerator(['unknown_types' => 'permissive']);
        $schema = $generator->generateSchemaFromRules(['values' => 'array', 'value' => 'string|regex:/\\Afoo\\z/i']);
        $this->assertFalse($generator->containsFile($schema));
        $this->assertInstanceOf(\stdClass::class, $schema['properties']['values']['items']);
        $this->assertArrayNotHasKey('pattern', $schema['properties']['value']);
        $this->assertNotEmpty($generator->getDiagnostics());
    }

    private function assertValid(array $schema, array $value): void
    {
        $validator = new Validator;
        $data = json_decode(json_encode($value === [] ? new \stdClass : $value, JSON_THROW_ON_ERROR));
        $validator->validate($data, json_decode(json_encode($schema, JSON_THROW_ON_ERROR)));
        $this->assertTrue($validator->isValid(), json_encode($validator->getErrors()));
    }

    private function assertInvalid(array $schema, array $value): void
    {
        $validator = new Validator;
        $data = json_decode(json_encode($value === [] ? new \stdClass : $value, JSON_THROW_ON_ERROR));
        $validator->validate($data, json_decode(json_encode($schema, JSON_THROW_ON_ERROR)));
        $this->assertFalse($validator->isValid());
    }
}
