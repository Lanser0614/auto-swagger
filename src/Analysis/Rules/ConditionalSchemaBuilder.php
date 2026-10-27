<?php

namespace AutoSwagger\Analysis\Rules;

use AutoSwagger\Analysis\Schema;

final class ConditionalSchemaBuilder
{
    public function apply(array $schema, array $conditions): array
    {
        $groups = [];
        foreach ($conditions as $condition) {
            $groups[$condition['field']][] = $condition;
        }
        foreach ($groups as $field => $rules) {
            $target = FieldPath::segments((string) $field);
            $scope = array_slice($target, 0, -1);
            foreach ($rules as $rule) {
                foreach ($this->otherPaths($rule) as $other) {
                    $parent = array_slice(FieldPath::segments($other), 0, -1);
                    while (array_slice($parent, 0, count($scope)) !== $scope) {
                        array_pop($scope);
                    }
                }
            }
            $relative = array_slice($target, count($scope));
            $original = $this->find($schema, $target) ?? ['description' => 'Conditionally validated field.'];
            $original['x-laravel-conditions'] = array_map(fn ($rule) => array_diff_key($rule, ['field' => true]), $rules);
            $this->set($schema, $target, $original);
            $excluded = [];
            foreach ($rules as $rule) {
                if (! $rule['dynamic'] && str_starts_with($rule['rule'], 'exclude_')) {
                    $excluded[] = $this->predicate($rule, $scope, $schema);
                }
            }
            $exclusion = $excluded === [] ? null : (count($excluded) === 1 ? $excluded[0] : ['anyOf' => $excluded]);
            if ($exclusion !== null) {
                $required = $this->isRequired($schema, $target);
                $this->removeRequired($schema, $target);
                $this->set($schema, $target, ['description' => 'Excluded from validated data when the Laravel exclusion condition matches.', 'x-laravel-conditions' => $original['x-laravel-conditions']]);
                $outcome = $required ? ['allOf' => [$this->propertyPath($relative, $original, true), $this->nonEmpty($relative)]] : $this->propertyPath($relative, $original, false);
                $this->append($schema, $scope, ['anyOf' => [$exclusion, $outcome]]);
            }
            foreach ($rules as $rule) {
                if ($rule['dynamic'] || str_starts_with($rule['rule'], 'exclude_')) {
                    continue;
                }
                $predicate = $this->predicate($rule, $scope, $schema);
                if ($exclusion !== null) {
                    $predicate = ['allOf' => [$predicate, ['not' => $exclusion]]];
                }
                $outcome = str_starts_with($rule['rule'], 'prohibited')
                    ? ['not' => $this->nonEmpty($relative)] : ['allOf' => [$this->propertyPath($relative, $original, true), $this->nonEmpty($relative)]];
                $this->append($schema, $scope, ['anyOf' => [['not' => $predicate], $outcome]]);
            }
        }

        return $schema;
    }

    private function predicate(array $rule, array $scope, array $root): array
    {
        $name = $rule['rule'];
        $parameters = $rule['parameters'];
        if ($name === 'prohibited') {
            return ['type' => 'object'];
        }
        if (str_ends_with($name, '_if') || str_ends_with($name, '_unless')) {
            $other = FieldPath::segments((string) array_shift($parameters));
            $type = $this->find($root, $other)['type'] ?? null;
            $values = array_map(fn ($value) => match ($type) {
                'integer' => is_numeric($value) ? (int) $value : $value,
                'number' => is_numeric($value) ? (float) $value : $value,
                'boolean' => match ($value) {
                    'true', '1' => true, 'false', '0' => false, default => $value
                },
                default => $value,
            }, $parameters);
            $match = $this->propertyPath(array_slice($other, count($scope)), ['enum' => $values], true);

            return str_ends_with($name, '_unless') ? ['not' => $match] : $match;
        }
        $predicates = [];
        foreach ($parameters as $field) {
            $presence = $this->nonEmpty(array_slice(FieldPath::segments($field), count($scope)));
            $predicates[] = str_contains($name, '_without') ? ['not' => $presence] : $presence;
        }
        if ($predicates === []) {
            return ['type' => 'object'];
        }

        return count($predicates) === 1 ? $predicates[0] : [str_ends_with($name, '_all') ? 'allOf' : 'anyOf' => $predicates];
    }

    private function otherPaths(array $rule): array
    {
        if ($rule['dynamic'] || $rule['rule'] === 'prohibited') {
            return [];
        }

        return str_ends_with($rule['rule'], '_if') || str_ends_with($rule['rule'], '_unless')
            ? array_slice($rule['parameters'], 0, 1) : $rule['parameters'];
    }

    private function nonEmpty(array $path): array
    {
        return $this->propertyPath($path, ['not' => ['enum' => [null, '', [], new \stdClass]]], true);
    }

    private function propertyPath(array $path, array $value, bool $required): array
    {
        if ($path === []) {
            return $value;
        }
        $name = array_shift($path);
        if ($name === '*') {
            return ['type' => 'array', 'items' => $this->propertyPath($path, $value, $required)];
        }
        $child = $this->propertyPath($path, $value, $required);

        return Schema::object([$name => $child], $required && ! in_array('*', $path, true) ? [$name] : []);
    }

    private function find(array $schema, array $path): ?array
    {
        foreach ($path as $name) {
            $schema = $name === '*' ? ($schema['items'] ?? null) : (((array) ($schema['properties'] ?? []))[$name] ?? null);
            if (! is_array($schema)) {
                return null;
            }
        }

        return $schema;
    }

    private function &node(array &$schema, array $path): array
    {
        $node = &$schema;
        foreach ($path as $name) {
            if ($name === '*') {
                if (! is_array($node['items'] ?? null)) {
                    $node['items'] = [];
                }
                $node = &$node['items'];
            } else {
                $node['properties'] = (array) ($node['properties'] ?? []);
                $node['properties'][$name] ??= [];
                $node = &$node['properties'][$name];
            }
        }

        return $node;
    }

    private function set(array &$schema, array $path, array $value): void
    {
        $node = &$this->node($schema, $path);
        $node = $value;
    }

    private function append(array &$schema, array $path, array $condition): void
    {
        $node = &$this->node($schema, $path);
        $node['allOf'][] = $condition;
    }

    private function isRequired(array $schema, array $path): bool
    {
        $name = array_pop($path);

        return in_array($name, $this->find($schema, $path)['required'] ?? [], true);
    }

    private function removeRequired(array &$schema, array $path): void
    {
        $name = array_pop($path);
        $node = &$this->node($schema, $path);
        $node['required'] = array_values(array_diff($node['required'] ?? [], [$name]));
        if ($node['required'] === []) {
            unset($node['required']);
        }
    }
}
