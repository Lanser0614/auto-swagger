<?php

namespace AutoSwagger\Analyzer;

use AutoSwagger\Analysis\Ast\ResourceParser;
use AutoSwagger\Analysis\Ast\SourceReader;
use AutoSwagger\Analysis\Diagnostics;
use AutoSwagger\Analysis\ModelMetadata;
use AutoSwagger\Analysis\Options;
use AutoSwagger\Analysis\Schema;
use AutoSwagger\Analysis\SchemaRegistry;
use AutoSwagger\Analysis\TypeResolver;
use AutoSwagger\Attributes\ApiProperty;
use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use ReflectionClass;

class ResourceSchemaGenerator
{
    private Options $options;

    private Diagnostics $diagnostics;

    private SourceReader $source;

    private SchemaRegistry $registry;

    private TypeResolver $types;

    private ResourceParser $parser;

    public function __construct(array|Options $options = [], ?Diagnostics $diagnostics = null, ?SourceReader $source = null)
    {
        $this->options = $options instanceof Options ? $options : new Options($options);
        $this->diagnostics = $diagnostics ?? new Diagnostics($this->options);
        $this->source = $source ?? new SourceReader;
        $this->registry = new SchemaRegistry($this->options->get('use_references'));
        $this->types = new TypeResolver($this->source, fn ($class) => $this->generate($class));
        $this->parser = new ResourceParser($this->source, $this->types, new ModelMetadata($this->source, $this->types), $this->diagnostics,
            fn ($class) => $this->generate($class), fn ($class) => $this->collectionItem($class));
    }

    public function generate(array|string $resource, ?bool $reference = null): array
    {
        if (is_array($resource)) {
            return $this->fromProperties($resource);
        }
        if (! class_exists($resource)) {
            throw new \InvalidArgumentException("Resource class {$resource} does not exist.");
        }

        return $this->registry->build($resource, fn () => $this->fromClass(new ReflectionClass($resource)), $reference ?? $this->options->get('use_references'));
    }

    public function getSchemas(): array
    {
        return $this->registry->schemas();
    }

    public function getDiagnostics(): array
    {
        return $this->diagnostics->all();
    }

    public function parser(): ResourceParser
    {
        return $this->parser;
    }

    public function source(): SourceReader
    {
        return $this->source;
    }

    private function fromClass(ReflectionClass $reflection): array
    {
        $schema = Schema::object();
        $properties = [];
        if ($reflection->isSubclassOf(Model::class)) {
            $model = $reflection->newInstance();
            foreach ($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()) as $column) {
                $property = $this->databaseType($column['type_name']);
                $cast = $model->getCasts()[$column['name']] ?? null;
                if (is_string($cast)) {
                    $cast = explode(':', $cast, 2)[0];
                    $property = match ($cast) {
                        'int', 'integer', 'timestamp' => ['type' => 'integer'],
                        'real', 'float', 'double' => ['type' => 'number'],
                        'decimal', 'string', 'hashed' => ['type' => 'string'],
                        'bool', 'boolean' => ['type' => 'boolean'],
                        'array', 'json', 'collection' => ['type' => 'array', 'items' => new \stdClass],
                        'object' => ['type' => 'object'],
                        'date', 'immutable_date' => ['type' => 'string', 'format' => 'date'],
                        'datetime', 'immutable_datetime' => ['type' => 'string', 'format' => 'date-time'],
                        default => $property,
                    };
                }
                $property['nullable'] = (bool) $column['nullable'];
                $properties[$column['name']] = $property;
            }
        }
        $metadata = ($reflection->getAttributes(ApiSwaggerResource::class)[0] ?? null)?->newInstance();
        if ($metadata) {
            $properties = array_replace($properties, (array) $this->fromProperties($metadata->properties)['properties']);
            if ($metadata->description !== null) {
                $schema['description'] = $metadata->description;
            }
        }
        $required = [];
        foreach ([...$reflection->getProperties(), ...$reflection->getMethods()] as $member) {
            $attribute = ($member->getAttributes(ApiProperty::class)[0] ?? null)?->newInstance();
            if (! $attribute) {
                continue;
            }
            $name = $attribute->name ?? $member->getName();
            $definition = $attribute->ref !== null
                ? (class_exists($attribute->ref) ? $this->generate($attribute->ref, true) : ['$ref' => $attribute->ref])
                : $this->property($attribute->type);
            if ($attribute->isCollection) {
                $definition = ['type' => 'array', 'items' => $attribute->items !== null ? $this->normalize($attribute->items) : ($definition['items'] ?? $definition)];
            }
            $annotations = [];
            foreach (['description', 'format', 'example', 'enum', 'items'] as $key) {
                if ($attribute->$key !== null) {
                    $annotations[$key] = $key === 'items' ? $this->normalize($attribute->$key) : $attribute->$key;
                }
            }
            if ($attribute->nullable) {
                $definition = Schema::nullable($definition);
            }
            if (isset($definition['$ref']) && $annotations !== []) {
                $definition = ['allOf' => [$definition]];
            }
            $properties[$name] = array_replace($definition, $annotations);
            if ($attribute->required) {
                $required[] = $name;
            }
        }
        $schema['properties'] = $properties === [] ? new \stdClass : $properties;
        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }
        $mode = $this->options->get('resource_inference');
        $hasExplicit = ! empty($metadata?->properties) || $this->hasPropertyAttributes($reflection);
        if ($reflection->isSubclassOf(Model::class) || $mode === 'disabled' || ($mode === 'fallback' && $hasExplicit)) {
            return $schema;
        }

        $inferred = null;
        if ($reflection->isSubclassOf(ResourceCollection::class)) {
            $method = $reflection->getMethod('toArray');
            if ($method->getDeclaringClass()->getName() === ResourceCollection::class && ($item = $this->collectionItem($reflection))) {
                $inferred = ['type' => 'array', 'items' => $this->generate($item)];
            }
        }
        if ($inferred === null && $reflection->isSubclassOf(JsonResource::class) && $reflection->hasMethod('toArray') && ! str_starts_with($reflection->getMethod('toArray')->getDeclaringClass()->getName(), 'Illuminate\\')) {
            $inferred = $this->parser->method($reflection, 'toArray', $properties);
        }
        if ($inferred === null && ! $reflection->isSubclassOf(JsonResource::class)) {
            $automatic = [];
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic() || isset($properties[$property->getName()])) {
                    continue;
                }
                $type = $this->types->reflection($property->getType(), $reflection);
                if ($type !== null) {
                    $automatic[$property->getName()] = $type;
                }
            }
            if ($automatic !== []) {
                $inferred = Schema::object($automatic);
            }
        }
        if ($inferred !== null) {
            if (($inferred['type'] ?? null) === 'object') {
                $inferred = Schema::mergeObjects($inferred, $schema);
                if ((array) $inferred['properties'] === []) {
                    $inferred['properties'] = new \stdClass;
                }
            } elseif ($properties !== []) {
                $this->diagnostics->report('Object property annotations override an inferred collection schema.', $reflection->getName());

                return $schema;
            }
            if (isset($schema['description'])) {
                $inferred['description'] = $schema['description'];
            }

            return $inferred;
        }

        return $schema;
    }

    private function hasPropertyAttributes(ReflectionClass $class): bool
    {
        foreach ([...$class->getProperties(), ...$class->getMethods()] as $member) {
            if ($member->getAttributes(ApiProperty::class) !== []) {
                return true;
            }
        }

        return false;
    }

    public function collectionItem(ReflectionClass $class): ?string
    {
        $item = $class->getDefaultProperties()['collects'] ?? null;
        if (is_string($item) && is_subclass_of($item, JsonResource::class)) {
            return $item;
        }
        if (str_ends_with($class->getName(), 'Collection')) {
            foreach ([substr($class->getName(), 0, -10), substr($class->getName(), 0, -10).'Resource'] as $candidate) {
                if (is_subclass_of($candidate, JsonResource::class)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public function fromProperties(array $properties): array
    {
        $result = [];
        foreach ($properties as $name => $property) {
            $result[$name] = $this->property($property);
        }

        return Schema::object($result);
    }

    private function property(array|string $property): array
    {
        if (is_array($property)) {
            return isset($property['type']) || isset($property['$ref']) || isset($property['oneOf']) || isset($property['allOf']) || isset($property['anyOf'])
                ? $this->normalize($property) : $this->fromProperties($property);
        }
        if (class_exists($property)) {
            return $this->generate($property);
        }

        return match ($property) {
            'string' => ['type' => 'string'],
            'int', 'integer' => ['type' => 'integer'],
            'number', 'float', 'double', 'decimal', 'numeric' => ['type' => 'number'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            'json', 'object' => ['type' => 'object'],
            'date', 'date-time', 'email', 'uuid', 'uri' => ['type' => 'string', 'format' => $property],
            default => $this->diagnostics->unknown('Schema property type '.$property),
        };
    }

    public function normalize(array|string $schema): array
    {
        if (is_string($schema)) {
            return $this->property($schema);
        }
        if (isset($schema['$ref']) && class_exists($schema['$ref'])) {
            $schema = array_replace($schema, $this->generate($schema['$ref'], true));
        }
        foreach (['items', 'additionalProperties', 'not'] as $key) {
            if (isset($schema[$key]) && (is_array($schema[$key]) || is_string($schema[$key]))) {
                $schema[$key] = $this->normalize($schema[$key]);
            }
        }
        foreach (['properties', 'allOf', 'oneOf', 'anyOf'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                foreach ($schema[$key] as &$child) {
                    if (is_array($child) || is_string($child)) {
                        $child = $this->normalize($child);
                    }
                }
                unset($child);
            }
        }

        return $schema;
    }

    private function databaseType(string $type): array
    {
        $type = strtolower($type);
        if (str_starts_with($type, '_') || str_ends_with($type, '[]')) {
            return ['type' => 'array', 'items' => $this->databaseType(trim($type, '_[]'))];
        }

        return match ($type) {
            'int', 'integer', 'int2', 'int4', 'int8', 'smallint', 'bigint', 'tinyint', 'mediumint', 'serial', 'bigserial', 'smallserial' => ['type' => 'integer'],
            'float', 'float4', 'float8', 'double', 'double precision', 'decimal', 'numeric', 'real', 'money' => ['type' => 'number'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'json', 'jsonb' => ['type' => 'object'],
            'uuid' => ['type' => 'string', 'format' => 'uuid'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime', 'timestamp', 'timestamptz', 'timestamp with time zone', 'timestamp without time zone' => ['type' => 'string', 'format' => 'date-time'],
            default => ['type' => 'string'],
        };
    }
}
