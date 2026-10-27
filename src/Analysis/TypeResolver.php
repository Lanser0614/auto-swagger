<?php

namespace AutoSwagger\Analysis;

use AutoSwagger\Analysis\Ast\SourceReader;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

final class TypeResolver
{
    public function __construct(private readonly SourceReader $source, private readonly \Closure $resource) {}

    public function reflection(?ReflectionType $type, ReflectionClass $context): ?array
    {
        if ($type instanceof ReflectionUnionType) {
            $schemas = [];
            foreach ($type->getTypes() as $part) {
                $schema = $this->reflection($part, $context);
                if ($schema === null) {
                    return null;
                }
                $schemas[] = $schema;
            }

            return Schema::union(...$schemas);
        }
        if (! $type instanceof ReflectionNamedType) {
            return null;
        }
        $schema = $this->resolve(($type->isBuiltin() ? '' : '\\').$type->getName(), $context);

        return $schema !== null && $type->allowsNull() && $type->getName() !== 'null' ? Schema::nullable($schema) : $schema;
    }

    public function resolve(string $type, ReflectionClass $context): ?array
    {
        $type = trim($type);
        $parts = self::split($type, '|');
        if (count($parts) > 1) {
            $schemas = array_map(fn ($part) => $this->resolve($part, $context), $parts);

            return in_array(null, $schemas, true) ? null : Schema::union(...$schemas);
        }
        if (str_starts_with($type, '?')) {
            $schema = $this->resolve(substr($type, 1), $context);

            return $schema === null ? null : Schema::nullable($schema);
        }
        if (str_ends_with($type, '[]')) {
            return ['type' => 'array', 'items' => $this->resolve(substr($type, 0, -2), $context) ?? new \stdClass];
        }
        if (preg_match('/^([^<>]+)<(.+)>$/', $type, $match) && in_array(strtolower(substr(strrchr('\\'.$match[1], '\\'), 1)), ['array', 'list', 'non-empty-array', 'non-empty-list', 'collection'], true)) {
            $arguments = self::split($match[2], ',');
            $value = $this->resolve(end($arguments), $context) ?? new \stdClass;

            return count($arguments) === 2 && trim($arguments[0]) === 'string'
                ? ['type' => 'object', 'additionalProperties' => $value] : ['type' => 'array', 'items' => $value];
        }
        if (preg_match('/^array\{(.*)\}$/', $type, $match)) {
            $properties = [];
            $required = [];
            foreach (self::split($match[1], ',') as $field) {
                [$name, $value] = array_pad(explode(':', $field, 2), 2, 'mixed');
                $name = trim($name, " '\"");
                $optional = str_ends_with($name, '?');
                $name = rtrim($name, '?');
                $properties[$name] = $this->resolve($value, $context) ?? new \stdClass;
                if (! $optional) {
                    $required[] = $name;
                }
            }

            return Schema::object($properties, $required);
        }
        $builtin = match (strtolower($type)) {
            'int', 'integer', 'positive-int', 'non-negative-int' => ['type' => 'integer'],
            'float', 'double', 'number', 'numeric' => ['type' => 'number'],
            'string', 'non-empty-string', 'class-string' => ['type' => 'string'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'true', 'false' => ['type' => 'boolean', 'enum' => [$type === 'true']],
            'array', 'list', 'iterable', 'collection' => ['type' => 'array', 'items' => new \stdClass],
            'object', 'stdclass' => ['type' => 'object'],
            'null' => Schema::null(),
            'mixed' => ['description' => 'Any value.'],
            default => null,
        };
        if ($builtin !== null) {
            return $builtin;
        }
        $class = $this->source->docClass($type, $context);
        if (enum_exists($class) && is_subclass_of($class, \BackedEnum::class)) {
            $values = array_map(fn ($case) => $case->value, $class::cases());

            return ['type' => is_int($values[0] ?? null) ? 'integer' : 'string', 'enum' => $values];
        }
        if (is_a($class, \DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }
        if (is_subclass_of($class, JsonResource::class)) {
            return ($this->resource)($class);
        }

        return null;
    }

    public static function split(string $value, string $delimiter): array
    {
        $parts = [];
        $part = '';
        $depth = 0;
        for ($i = 0; $i < strlen($value); $i++) {
            $character = $value[$i];
            if (str_contains('<{([', $character)) {
                $depth++;
            }
            if (str_contains('>})]', $character)) {
                $depth--;
            }
            if ($character === $delimiter && $depth === 0) {
                $parts[] = trim($part);
                $part = '';
            } else {
                $part .= $character;
            }
        }
        $parts[] = trim($part);

        return $parts;
    }

    public static function docType(string $value): string
    {
        $depth = 0;
        for ($i = 0; $i < strlen($value); $i++) {
            if (str_contains('<{([', $value[$i])) {
                $depth++;
            } elseif (str_contains('>})]', $value[$i])) {
                $depth--;
            } elseif (ctype_space($value[$i]) && $depth === 0) {
                return substr($value, 0, $i);
            }
        }

        return $value;
    }
}
