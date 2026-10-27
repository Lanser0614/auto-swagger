<?php

namespace AutoSwagger\Analysis;

use AutoSwagger\Analysis\Ast\InferredValue;
use AutoSwagger\Analyzer\ResourceSchemaGenerator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use ReflectionClass;

final class ResponseSchemaBuilder
{
    public function __construct(private readonly ResourceSchemaGenerator $resources, private readonly Diagnostics $diagnostics) {}

    public function descriptor(array $descriptor, string $wrapping): array
    {
        $schema = $this->resources->generate($descriptor['class']);
        if ($descriptor['pagination'] !== null && is_subclass_of($descriptor['class'], ResourceCollection::class)) {
            $schema = $this->resources->generate($descriptor['class'], false);
        }
        if ($descriptor['collection'] && ! is_subclass_of($descriptor['class'], ResourceCollection::class)) {
            $schema = ['type' => 'array', 'items' => $schema];
        }

        return $this->wrap($schema, $descriptor['class'], $wrapping, $descriptor['pagination'], $descriptor['additional'], $descriptor['collection'] && ! is_subclass_of($descriptor['class'], ResourceCollection::class));
    }

    public function wrap(array $schema, ?string $class, string $mode, ?string $pagination = null, array $additional = [], bool $anonymousCollection = false): array
    {
        if (! in_array($mode, ['legacy', 'laravel'], true)) {
            throw new \InvalidArgumentException("Unsupported response wrapping: {$mode}");
        }
        if ($pagination !== null && ! in_array($pagination, ['legacy', 'laravel', 'simple', 'cursor'], true)) {
            throw new \InvalidArgumentException("Unsupported pagination mode: {$pagination}");
        }
        if ($pagination === 'legacy') {
            return self::legacyPagination($schema);
        }
        if ($mode === 'legacy') {
            return $schema;
        }
        $isResource = $class !== null && is_subclass_of($class, JsonResource::class);
        $reflection = $isResource ? new ReflectionClass($anonymousCollection ? AnonymousResourceCollection::class : $class) : null;
        if ($pagination !== null) {
            $schema = $this->pagination($schema, $pagination);
            if ($reflection && $reflection->hasMethod('paginationInformation')) {
                $default = Schema::object(array_intersect_key($schema['properties'], array_flip(['links', 'meta'])));
                $metadata = $this->resources->parser()->method($reflection, 'paginationInformation', [], ['default' => new InferredValue($default)]);
                if ($metadata !== null) {
                    $schema = Schema::mergeObjects(Schema::object(['data' => $schema['properties']['data']], ['data']), $metadata);
                }
            }
        }
        if ($reflection && $reflection->hasMethod('with') && ! str_starts_with($reflection->getMethod('with')->getDeclaringClass()->getName(), 'Illuminate\\')) {
            $metadata = $this->resources->parser()->method($reflection, 'with');
            if ($metadata !== null) {
                array_unshift($additional, $metadata);
            }
        }
        $hasMetadata = count(array_filter($additional, fn ($value) => (array) ($value['properties'] ?? []) !== [])) > 0;
        $wrapper = $reflection && $reflection->hasProperty('wrap') ? $reflection->getStaticPropertyValue('wrap') : null;
        $force = $reflection && $reflection->hasProperty('forceWrapping') ? $reflection->getStaticPropertyValue('forceWrapping') : false;
        $inner = isset($schema['$ref']) && $class ? $this->resources->generate($class, false) : $schema;
        if ($pagination === null && $isResource && (($wrapper !== null && ($force || ! array_key_exists($wrapper, (array) ($inner['properties'] ?? [])))) || ($hasMetadata && ($wrapper === null || ! array_key_exists($wrapper, (array) ($inner['properties'] ?? [])))))) {
            $schema = Schema::object([$wrapper ?? 'data' => $schema], [$wrapper ?? 'data']);
        }
        foreach ($additional as $metadata) {
            if (isset($metadata['properties'])) {
                $schema = $this->mergeMetadata($schema, $metadata);
            } else {
                $this->diagnostics->report('Response metadata must have statically known object keys.', $class ?? 'response');
            }
        }

        return $schema;
    }

    private function mergeMetadata(array $base, array $addition): array
    {
        foreach ((array) $addition['properties'] as $name => $property) {
            if (isset($base['properties'][$name]['properties'], $property['properties'])) {
                $base['properties'][$name] = $this->mergeMetadata($base['properties'][$name], $property);
            } elseif (isset($base['properties'][$name])) {
                $previous = $base['properties'][$name];
                $base['properties'][$name] = ['type' => 'array', 'items' => Schema::union(($previous['type'] ?? null) === 'array' ? (array) $previous['items'] : $previous, ($property['type'] ?? null) === 'array' ? (array) $property['items'] : $property)];
            } else {
                $base['properties'][$name] = $property;
            }
        }
        $required = array_values(array_unique([...($base['required'] ?? []), ...($addition['required'] ?? [])]));
        if ($required !== []) {
            $base['required'] = $required;
        }

        return $base;
    }

    private function pagination(array $schema, string $mode): array
    {
        if (($schema['type'] ?? null) !== 'array') {
            $this->diagnostics->report('Pagination expects a resource collection; the supplied schema is used as collection items.');
            $schema = ['type' => 'array', 'items' => $schema];
        }
        $link = ['type' => 'string', 'nullable' => true];
        $meta = ['path' => ['type' => 'string'], 'per_page' => ['type' => 'integer']];
        if ($mode === 'cursor') {
            $meta += ['next_cursor' => $link, 'prev_cursor' => $link];
        } else {
            $meta += ['current_page' => ['type' => 'integer'], 'from' => ['type' => 'integer', 'nullable' => true], 'to' => ['type' => 'integer', 'nullable' => true]];
            if ($mode === 'laravel') {
                $meta += ['last_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'links' => ['type' => 'array', 'items' => Schema::object(['url' => $link, 'label' => ['type' => 'string'], 'active' => ['type' => 'boolean']])]];
            }
        }

        return Schema::object([
            'data' => $schema,
            'links' => Schema::object(array_fill_keys(['first', 'last', 'prev', 'next'], $link)),
            'meta' => Schema::object($meta),
        ], ['data', 'links', 'meta']);
    }

    public static function legacyPagination(array $schema): array
    {
        return ['type' => 'object', 'properties' => [
            'pagination' => ['type' => 'object', 'properties' => array_fill_keys(['total', 'count', 'per_page', 'current_page', 'total_pages'], ['type' => 'integer'])],
            'data' => ['type' => 'array', 'items' => $schema],
        ]];
    }
}
