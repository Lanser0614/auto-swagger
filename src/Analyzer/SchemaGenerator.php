<?php

namespace AutoSwagger\Analyzer;

use ReflectionClass;

class SchemaGenerator
{
    public function generateFromFormRequest(string $requestClass): array
    {
        try {
            $reflection = new ReflectionClass($requestClass);
            $instance = $reflection->newInstanceWithoutConstructor();
            
            if (!method_exists($instance, 'rules')) {
                return [];
            }

            $rules = $instance->rules();
            return $this->generateSchemaFromRules($rules);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function generateSchemaFromRules(array $rules): array
    {
        $properties = [];
        $required = [];

        foreach ($rules as $field => $fieldRules) {
            // Convert string rules to array
            if (is_string($fieldRules)) {
                $fieldRules = explode('|', $fieldRules);
            }

            $fieldRules = is_array($fieldRules) ? $fieldRules : [$fieldRules];
            $schema = $this->processFieldRules($fieldRules);

            // Handle array notation (e.g., tags.*)
            if (str_contains($field, '.*')) {
                $parentField = str_replace('.*', '', $field);
                if (!isset($properties[$parentField])) {
                    $properties[$parentField] = [
                        'type' => 'array',
                        'items' => $schema
                    ];
                }
                continue;
            }

            $properties[$field] = $schema;

            // Check if field is required
            if (in_array('required', $fieldRules)) {
                $required[] = $field;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties
        ];

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private function processFieldRules(array $rules): array
    {
        $schema = ['type' => 'string']; // Default type

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $this->processStringRule($rule, $schema);
            } elseif (is_object($rule)) {
                $this->processObjectRule($rule, $schema);
            }
        }

        return $schema;
    }

    private function processStringRule(string $rule, array &$schema): void
    {
        // Extract rule name and parameters
        $parts = explode(':', $rule);
        $ruleName = $parts[0];
        $parameters = isset($parts[1]) ? explode(',', $parts[1]) : [];

        switch ($ruleName) {
            case 'integer':
            case 'numeric':
                $schema['type'] = 'integer';
                break;
            case 'numeric':
                $schema['type'] = 'number';
                break;
            case 'boolean':
                $schema['type'] = 'boolean';
                break;
            case 'array':
                $schema['type'] = 'array';
                if (!isset($schema['items'])) {
                    $schema['items'] = ['type' => 'string'];
                }
                break;
            case 'min':
                if ($schema['type'] === 'string') {
                    $schema['minLength'] = (int)$parameters[0];
                } else {
                    $schema['minimum'] = (int)$parameters[0];
                }
                break;
            case 'max':
                if ($schema['type'] === 'string') {
                    $schema['maxLength'] = (int)$parameters[0];
                } else {
                    $schema['maximum'] = (int)$parameters[0];
                }
                break;
            case 'between':
                if ($schema['type'] === 'string') {
                    $schema['minLength'] = (int)$parameters[0];
                    $schema['maxLength'] = (int)$parameters[1];
                } else {
                    $schema['minimum'] = (int)$parameters[0];
                    $schema['maximum'] = (int)$parameters[1];
                }
                break;
            case 'in':
                $schema['enum'] = $parameters;
                break;
            case 'date':
                $schema['type'] = 'string';
                $schema['format'] = 'date';
                break;
            case 'date_format':
                $schema['type'] = 'string';
                $schema['format'] = 'date-time';
                break;
            case 'email':
                $schema['type'] = 'string';
                $schema['format'] = 'email';
                break;
            case 'url':
                $schema['type'] = 'string';
                $schema['format'] = 'uri';
                break;
            case 'ip':
                $schema['type'] = 'string';
                $schema['format'] = 'ipv4';
                break;
            case 'ipv4':
                $schema['type'] = 'string';
                $schema['format'] = 'ipv4';
                break;
            case 'ipv6':
                $schema['type'] = 'string';
                $schema['format'] = 'ipv6';
                break;
            case 'json':
                $schema['type'] = 'object';
                break;
            case 'nullable':
                $schema['nullable'] = true;
                break;
            case 'required':
                // Handled at parent level
                break;
        }
    }

    private function processObjectRule(object $rule, array &$schema): void
    {
        $ruleName = get_class($rule);
        
        // Handle common Laravel validation rule objects
        switch ($ruleName) {
            case 'Illuminate\Validation\Rules\Enum':
                if (method_exists($rule, 'type')) {
                    $enumClass = $rule->type;
                    if (method_exists($enumClass, 'cases')) {
                        $schema['enum'] = array_map(
                            fn($case) => $case->value,
                            $enumClass::cases()
                        );
                    }
                }
                break;
            case 'Illuminate\Validation\Rules\Dimensions':
                $schema['type'] = 'string';
                $schema['format'] = 'binary';
                break;
            case 'Illuminate\Validation\Rules\File':
                $schema['type'] = 'string';
                $schema['format'] = 'binary';
                break;
        }
    }
}
