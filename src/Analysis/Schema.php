<?php

namespace AutoSwagger\Analysis;

final class Schema
{
    public static function object(array $properties = [], array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass : $properties];
        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }

    public static function null(): array
    {
        return ['type' => 'object', 'nullable' => true, 'enum' => [null]];
    }

    public static function nullable(array $schema): array
    {
        if (isset($schema['$ref']) || isset($schema['allOf']) || isset($schema['anyOf']) || isset($schema['oneOf'])) {
            return ['anyOf' => [$schema, self::null()]];
        }
        $schema['nullable'] = true;

        return $schema;
    }

    public static function union(array ...$schemas): array
    {
        $schemas = array_values(array_unique($schemas, SORT_REGULAR));
        if (count($schemas) === 1) {
            return $schemas[0];
        }
        $nonNull = array_values(array_filter($schemas, fn ($schema) => ($schema['enum'] ?? false) !== [null]));
        if (count($nonNull) !== count($schemas) && count($nonNull) === 1) {
            return self::nullable($nonNull[0]);
        }

        return ['anyOf' => $schemas];
    }

    public static function mergeObjects(array $base, array $addition, bool $optional = false): array
    {
        $base['type'] = 'object';
        $base['properties'] = array_replace((array) ($base['properties'] ?? []), (array) ($addition['properties'] ?? []));
        $required = array_values(array_unique([...($base['required'] ?? []), ...($optional ? [] : ($addition['required'] ?? []))]));
        if ($required !== []) {
            $base['required'] = $required;
        } else {
            unset($base['required']);
        }

        return $base;
    }
}
