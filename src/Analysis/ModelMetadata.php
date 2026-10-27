<?php

namespace AutoSwagger\Analysis;

use AutoSwagger\Analysis\Ast\SourceReader;
use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

final class ModelMetadata
{
    public function __construct(private readonly SourceReader $source, private readonly TypeResolver $types) {}

    public function property(ReflectionClass $resource, string $name): ?array
    {
        $schema = $this->declaredProperty($resource, $name);
        if ($schema !== null) {
            return $schema;
        }
        $model = null;
        for ($context = $resource; $context && $model === null; $context = $context->getParentClass()) {
            $attribute = ($context->getAttributes(ApiSwaggerResource::class)[0] ?? null)?->newInstance();
            $model = $attribute?->model;
            if ($model === null && preg_match('/@mixin\s+([^\s*]+)/', $context->getDocComment() ?: '', $match)) {
                $model = $this->source->docClass($match[1], $context);
            }
        }
        if ($resource->isSubclassOf(Model::class)) {
            $model = $resource->getName();
        }
        if ($model === null || ! class_exists($model)) {
            return null;
        }
        $model = new ReflectionClass($model);
        $schema = $this->declaredProperty($model, $name);
        if ($schema !== null) {
            return $schema;
        }
        $defaults = $model->getDefaultProperties();
        $casts = $defaults['casts'] ?? [];
        if ($model->hasMethod('casts')) {
            $method = $this->source->method($model->getMethod('casts'));
            foreach ($method ? $this->source->returns($method) : [] as [$expression, $variables]) {
                $literal = $this->source->literal($expression, $model, $variables, $known);
                if ($known && is_array($literal)) {
                    $casts = array_replace($casts, $literal);
                }
            }
        }
        $cast = isset($casts[$name]) && is_string($casts[$name]) ? explode(':', $casts[$name], 2)[0] : null;
        if ($cast !== null) {
            return match ($cast) {
                'decimal', 'hashed' => ['type' => 'string'],
                'date', 'datetime', 'immutable_date', 'immutable_datetime' => ['type' => 'string', 'format' => 'date-time'],
                'timestamp' => ['type' => 'integer'],
                'json', 'collection' => ['type' => 'array', 'items' => new \stdClass],
                default => $this->types->resolve(class_exists($cast) ? '\\'.$cast : $cast, $model),
            };
        }
        if ($name === ($defaults['primaryKey'] ?? 'id')) {
            return ['type' => in_array($defaults['keyType'] ?? 'int', ['int', 'integer'], true) ? 'integer' : 'string'];
        }
        if (($defaults['timestamps'] ?? true) && in_array($name, ['created_at', 'updated_at'], true)) {
            return ['type' => 'string', 'format' => 'date-time', 'nullable' => true];
        }

        return null;
    }

    private function declaredProperty(ReflectionClass $class, string $name): ?array
    {
        if ($class->hasProperty($name)) {
            $schema = $this->types->reflection($class->getProperty($name)->getType(), $class);
            if ($schema !== null) {
                return $schema;
            }
        }
        if (preg_match('/@property(?:-read|-write)?\s+([^\r\n]+?)\s+\$'.preg_quote($name, '/').'(?:\s|$)/', $class->getDocComment() ?: '', $match)) {
            return $this->types->resolve(trim($match[1]), $class);
        }

        return $class->getParentClass() ? $this->declaredProperty($class->getParentClass(), $name) : null;
    }
}
