<?php

namespace AutoSwagger\Analysis\Ast;

use AutoSwagger\Analysis\Diagnostics;
use AutoSwagger\Analysis\ModelMetadata;
use AutoSwagger\Analysis\Schema;
use AutoSwagger\Analysis\TypeResolver;
use Illuminate\Http\Resources\Json\JsonResource;
use PhpParser\Node;
use PhpParser\Node\Expr;
use ReflectionClass;

final class ResourceParser
{
    private array $analyzing = [];

    public function __construct(
        private readonly SourceReader $source,
        private readonly TypeResolver $types,
        private readonly ModelMetadata $models,
        private readonly Diagnostics $diagnostics,
        private readonly \Closure $resource,
        private readonly \Closure $collectionItem,
    ) {}

    public function method(ReflectionClass $class, string $name, array $overrides = [], array $bindings = []): ?array
    {
        if (! $class->hasMethod($name)) {
            return null;
        }
        $method = $class->getMethod($name);
        $key = $class->getName().'::'.$name;
        if (isset($this->analyzing[$key])) {
            return null;
        }
        $node = $this->source->method($method);
        if ($node === null) {
            return null;
        }
        $this->analyzing[$key] = true;
        try {
            $values = [];
            foreach ($this->source->returns($node) as [$expression, $variables]) {
                $values[] = $this->expression($expression, $class, array_replace($bindings, $variables), $name, $overrides)->schema;
            }

            return $values === [] ? null : $this->branches($values);
        } finally {
            unset($this->analyzing[$key]);
        }
    }

    public function expression(Expr $expression, ReflectionClass $class, array $variables = [], string $path = '', array $overrides = []): InferredValue
    {
        if ($expression instanceof Expr\Variable && is_string($expression->name) && isset($variables[$expression->name])) {
            $value = $variables[$expression->name];
            if ($value instanceof InferredValue) {
                return $value;
            }
            unset($variables[$expression->name]);

            return $this->expression($value, $class, $variables, $path, $overrides);
        }
        if ($expression instanceof Expr\Closure || $expression instanceof Expr\ArrowFunction) {
            $schemas = [];
            foreach ($this->source->returns($expression) as [$value, $locals]) {
                $schemas[] = $this->expression($value, $class, array_replace($variables, $locals), $path, $overrides)->schema;
            }
            if ($schemas !== []) {
                return new InferredValue($this->branches($schemas));
            }
        }
        if ($expression instanceof Expr\Array_) {
            return $this->array($expression, $class, $variables, $path, $overrides);
        }
        if ($expression instanceof Expr\New_ && $expression->class instanceof Node\Name) {
            $resource = $this->source->className($expression->class, $class);
            if (is_subclass_of($resource, JsonResource::class)) {
                return new InferredValue(($this->resource)($resource), $this->optional($expression->args[0]->value ?? null, $variables));
            }
            if ($resource === 'Illuminate\\Http\\Resources\\MissingValue') {
                return new InferredValue(Schema::null(), true, true);
            }
        }
        if ($expression instanceof Expr\StaticCall && $expression->class instanceof Node\Name && $expression->name instanceof Node\Identifier) {
            $resource = $this->source->className($expression->class, $class);
            $method = $expression->name->toString();
            if (is_subclass_of($resource, JsonResource::class) && in_array($method, ['make', 'collection'], true)) {
                $schema = ($this->resource)($resource);
                if ($method === 'collection') {
                    $schema = ['type' => 'array', 'items' => $schema];
                }

                return new InferredValue($schema, $this->optional($expression->args[0]->value ?? null, $variables));
            }
            if (strtolower($expression->class->toString()) === 'parent' && $class->getParentClass()) {
                $schema = $this->method($class->getParentClass(), $method, $overrides);
                if ($schema !== null) {
                    return new InferredValue($schema);
                }
            }
            if (class_exists($resource) && method_exists($resource, $method)) {
                $schema = $this->returnType(new \ReflectionMethod($resource, $method), new ReflectionClass($resource));
                if ($schema !== null) {
                    return new InferredValue($schema);
                }
            }
        }
        if ($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall) {
            $schema = $this->methodCall($expression, $class, $variables, $path, $overrides);
            if ($schema !== null) {
                return $expression instanceof Expr\NullsafeMethodCall ? new InferredValue(Schema::nullable($schema->schema), $schema->optional, $schema->missing) : $schema;
            }
        }
        if ($expression instanceof Expr\PropertyFetch || $expression instanceof Expr\NullsafePropertyFetch) {
            if ($expression->var instanceof Expr\Variable && $expression->var->name === 'this' && $expression->name instanceof Node\Identifier) {
                $name = $expression->name->toString();
                if ($name === 'collection' && ($item = ($this->collectionItem)($class))) {
                    return new InferredValue(['type' => 'array', 'items' => ($this->resource)($item)]);
                }
                $schema = $this->models->property($class, $name);
                if ($schema !== null) {
                    return new InferredValue($schema);
                }
            }
            if ($expression->name instanceof Node\Identifier && $expression->name->toString() === 'value' && $expression->var instanceof Expr\ClassConstFetch) {
                $literal = $this->source->literal($expression->var, $class, $variables, $known);
                if ($known && $literal instanceof \BackedEnum) {
                    return new InferredValue(['type' => is_int($literal->value) ? 'integer' : 'string', 'enum' => [$literal->value]]);
                }
            }
            if ($expression->name instanceof Node\Identifier && $expression->name->toString() === 'value' && $expression->var instanceof Expr\PropertyFetch && $expression->var->var instanceof Expr\Variable && $expression->var->var->name === 'this' && $expression->var->name instanceof Node\Identifier) {
                $schema = $this->models->property($class, $expression->var->name->toString());
                if ($schema !== null && isset($schema['enum'])) {
                    return new InferredValue($schema);
                }
            }
        }
        if ($expression instanceof Expr\Ternary) {
            $condition = $this->source->literal($expression->cond, $class, $variables, $known);
            if ($known) {
                return $this->expression($condition ? ($expression->if ?? $expression->cond) : $expression->else, $class, $variables, $path, $overrides);
            }

            return new InferredValue($this->branches([
                $this->expression($expression->if ?? $expression->cond, $class, $variables, $path, $overrides)->schema,
                $this->expression($expression->else, $class, $variables, $path, $overrides)->schema,
            ]));
        }
        if ($expression instanceof Expr\BinaryOp\Coalesce) {
            $left = $this->expression($expression->left, $class, $variables, $path)->schema;
            unset($left['nullable']);

            return new InferredValue(Schema::union($left, $this->expression($expression->right, $class, $variables, $path)->schema));
        }
        if ($expression instanceof Expr\Cast) {
            $type = match (true) {
                $expression instanceof Expr\Cast\Int_ => 'integer', $expression instanceof Expr\Cast\Double => 'number',
                $expression instanceof Expr\Cast\Bool_ => 'boolean', $expression instanceof Expr\Cast\Array_ => 'array',
                $expression instanceof Expr\Cast\Object_ => 'object', default => 'string',
            };

            return new InferredValue(['type' => $type] + ($type === 'array' ? ['items' => new \stdClass] : []));
        }
        if ($expression instanceof Expr\BinaryOp\Concat || $expression instanceof Node\Scalar\InterpolatedString) {
            return new InferredValue(['type' => 'string']);
        }
        if ($expression instanceof Expr\BooleanNot || $expression instanceof Expr\Instanceof_ || $expression instanceof Expr\Isset_ || $expression instanceof Expr\Empty_ || ($expression instanceof Expr\BinaryOp && in_array($expression->getOperatorSigil(), ['==', '===', '!=', '!==', '<', '>', '<=', '>=', '&&', '||', 'and', 'or', 'xor'], true))) {
            return new InferredValue(['type' => 'boolean']);
        }
        if ($expression instanceof Expr\BinaryOp\Plus) {
            $left = $this->expression($expression->left, $class, $variables, $path, $overrides)->schema;
            $right = $this->expression($expression->right, $class, $variables, $path, $overrides)->schema;
            if (($left['type'] ?? null) === 'object' && ($right['type'] ?? null) === 'object') {
                return new InferredValue(Schema::mergeObjects($right, $left));
            }
            if (($left['type'] ?? null) === 'array' && ($right['type'] ?? null) === 'array') {
                return new InferredValue(['type' => 'array', 'items' => Schema::union((array) $left['items'], (array) $right['items'])]);
            }
        }
        if ($expression instanceof Expr\BinaryOp && in_array($expression->getOperatorSigil(), ['+', '-', '*', '/', '%', '**'], true)) {
            return new InferredValue(['type' => 'number']);
        }
        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Node\Name) {
            $name = $expression->name->getLast();
            if (in_array($name, ['array_merge', 'array_replace'], true)) {
                $schema = Schema::object();
                foreach ($expression->args as $argument) {
                    $schema = Schema::mergeObjects($schema, $this->expression($argument->value, $class, $variables, $path, $overrides)->schema);
                }

                return new InferredValue($schema);
            }
            if (in_array($name, ['route', 'url', 'asset', 'trans', '__', 'sprintf', 'strtolower', 'strtoupper', 'trim'], true)) {
                return new InferredValue(['type' => 'string']);
            }
            if ($name === 'count' || $name === 'intval') {
                return new InferredValue(['type' => 'integer']);
            }
        }
        $value = $this->source->literal($expression, $class, $variables, $known);
        if ($known) {
            return new InferredValue(match (true) {
                $value === null => Schema::null(), is_bool($value) => ['type' => 'boolean'],
                is_int($value) => ['type' => 'integer'], is_float($value) => ['type' => 'number'],
                is_string($value) => ['type' => 'string'],
                $value instanceof \BackedEnum => ['type' => is_int($value->value) ? 'integer' : 'string', 'enum' => [$value->value]],
                default => ['type' => 'object'],
            });
        }

        return new InferredValue($this->diagnostics->unknown($class->getName().'.'.$path, $class->getFileName() ?: null, $expression->getStartLine()));
    }

    private function array(Expr\Array_ $array, ReflectionClass $class, array $variables, string $path, array $overrides): InferredValue
    {
        $object = false;
        $position = 0;
        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }
            if ($item->unpack) {
                $spread = $this->expression($item->value, $class, $variables, $path, $overrides)->schema;
                if (($spread['type'] ?? null) !== 'array') {
                    $object = true;
                    break;
                }
            } elseif ($item->key !== null) {
                $key = $this->source->literal($item->key, $class, $variables, $known);
                if (! $known || $key !== $position) {
                    $object = true;
                    break;
                }
                $position++;
            } elseif ($item->value instanceof Expr\MethodCall && $item->value->name instanceof Node\Identifier && in_array($item->value->name->toString(), ['merge', 'mergeWhen'], true)) {
                $object = true;
                break;
            } else {
                $position++;
            }
        }
        $properties = [];
        $required = [];
        $items = [];
        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }
            if ($item->key === null || $item->unpack) {
                $value = $this->expression($item->value, $class, $variables, $path, $overrides);
                if ($value->missing) {
                    continue;
                }
                if ($object && isset($value->schema['properties'])) {
                    $properties = array_replace($properties, (array) $value->schema['properties']);
                    $required = [...$required, ...($value->optional ? [] : ($value->schema['required'] ?? []))];
                } elseif (! $object) {
                    $items[] = $item->unpack && ($value->schema['type'] ?? null) === 'array' ? (array) $value->schema['items'] : $value->schema;
                } else {
                    $this->diagnostics->report('Cannot determine keys of a merged resource value.', $class->getName().'.'.$path, $class->getFileName() ?: null, $item->getStartLine());
                }

                continue;
            }
            $key = $this->source->literal($item->key, $class, $variables, $known);
            if (! $known || (! is_string($key) && ! is_int($key))) {
                $this->diagnostics->report('Dynamic resource field name requires an explicit schema.', $class->getName(), $class->getFileName() ?: null, $item->getStartLine());

                continue;
            }
            $required = array_values(array_diff($required, [$key]));
            if (array_key_exists($key, $overrides)) {
                $properties[$key] = $overrides[$key];

                continue;
            }
            $value = $this->expression($item->value, $class, $variables, $path === '' ? (string) $key : $path.'.'.$key);
            if ($value->missing) {
                unset($properties[$key]);

                continue;
            }
            if (! $object) {
                $items[] = $value->schema;

                continue;
            }
            $properties[$key] = $value->schema;
            if (! $value->optional) {
                $required[] = $key;
            }
        }

        return new InferredValue($object || $array->items === [] ? Schema::object($properties, $required) : ['type' => 'array', 'items' => $items === [] ? new \stdClass : Schema::union(...$items)]);
    }

    private function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $call, ReflectionClass $class, array $variables, string $path, array $overrides): ?InferredValue
    {
        if (! $call->name instanceof Node\Identifier) {
            return null;
        }
        $name = $call->name->toString();
        $onThis = $call->var instanceof Expr\Variable && $call->var->name === 'this';
        if ($onThis && in_array($name, ['when', 'unless', 'mergeWhen', 'whenLoaded', 'whenHas', 'whenAppended', 'whenPivotLoaded', 'whenNotNull', 'whenNull', 'whenCounted', 'whenAggregated', 'whenExistsLoaded'], true)) {
            $conditionMethods = ['when', 'unless', 'mergeWhen'];
            $valueIndex = in_array($name, ['whenNotNull', 'whenNull'], true) ? 0 : ($name === 'whenAggregated' ? 3 : 1);
            $defaultIndex = $valueIndex + 1;
            $default = $call->args[$defaultIndex]->value ?? null;
            if (in_array($name, $conditionMethods, true)) {
                $condition = $this->source->literal($call->args[0]->value ?? null, $class, $variables, $known);
                if ($known) {
                    $chosen = ($name === 'unless' ? ! $condition : $condition) ? ($call->args[1]->value ?? null) : $default;

                    return $chosen ? $this->expression($chosen, $class, $variables, $path, $overrides) : new InferredValue(Schema::null(), true, true);
                }
            }
            if (isset($call->args[$valueIndex])) {
                $value = $this->expression($call->args[$valueIndex]->value, $class, $variables, $path, $overrides);
            } else {
                $attribute = $this->source->literal($call->args[0]->value ?? null, $class, $variables, $known);
                $schema = match ($name) {
                    'whenCounted' => ['type' => 'integer'], 'whenAggregated' => ['type' => 'number'], 'whenExistsLoaded' => ['type' => 'boolean'],
                    default => $known && is_string($attribute) ? $this->models->property($class, $attribute) : null,
                };
                $value = new InferredValue($schema ?? $this->diagnostics->unknown($class->getName().'.'.$path, $class->getFileName() ?: null, $call->getStartLine()));
            }
            $schema = $value->schema;
            if ($name === 'whenNotNull') {
                unset($schema['nullable']);
            }
            if ($default !== null) {
                $fallback = $this->expression($default, $class, $variables, $path, $overrides);
                if (! $fallback->missing) {
                    return new InferredValue($this->branches([$schema, $fallback->schema]));
                }
            }

            return new InferredValue($schema, true);
        }
        if ($onThis && $name === 'merge' && isset($call->args[0])) {
            return $this->expression($call->args[0]->value, $class, $variables, $path, $overrides);
        }
        if (in_array($name, ['toISOString', 'toIso8601String', 'toAtomString'], true)) {
            return new InferredValue(['type' => 'string', 'format' => 'date-time']);
        }
        if ($name === 'toDateString') {
            return new InferredValue(['type' => 'string', 'format' => 'date']);
        }
        if ($name === 'count') {
            return new InferredValue(['type' => 'integer']);
        }
        if ($name === 'format') {
            $format = $this->source->literal($call->args[0]->value ?? null, $class, $variables, $known);

            return new InferredValue(['type' => 'string'] + ($known && $format === 'Y-m-d' ? ['format' => 'date'] : []));
        }
        if (in_array($name, ['map', 'mapWithKeys'], true) && isset($call->args[0]) && ($call->args[0]->value instanceof Expr\Closure || $call->args[0]->value instanceof Expr\ArrowFunction)) {
            $item = $this->expression($call->args[0]->value, $class, $variables, $path);

            return new InferredValue($name === 'map' ? ['type' => 'array', 'items' => $item->schema] : ['type' => 'object', 'additionalProperties' => $item->schema]);
        }
        if (in_array($name, ['all', 'values', 'toArray', 'additional'], true) && ! $onThis) {
            return $this->expression($call->var, $class, $variables, $path, $overrides);
        }
        if ($onThis && $class->hasMethod($name)) {
            $method = $class->getMethod($name);
            $schema = $this->returnType($method, $class);
            if ($schema === null || ($schema['type'] ?? null) === 'array') {
                $schema = $this->method($class, $name, $overrides) ?? $schema;
            }
            if ($schema !== null) {
                return new InferredValue($schema);
            }
        }

        return null;
    }

    public function optional(?Expr $expression, array $variables = []): bool
    {
        if ($expression instanceof Expr\Variable && is_string($expression->name) && isset($variables[$expression->name])) {
            $value = $variables[$expression->name];
            unset($variables[$expression->name]);

            return $this->optional($value, $variables);
        }
        if ($expression instanceof Expr\MethodCall && $expression->name instanceof Node\Identifier) {
            $name = $expression->name->toString();
            if (in_array($name, ['when', 'unless', 'whenLoaded', 'whenHas', 'whenAppended', 'whenPivotLoaded', 'whenCounted', 'whenExistsLoaded'], true)) {
                return ! isset($expression->args[2]);
            }
            if (in_array($name, ['whenNotNull', 'whenNull'], true)) {
                return ! isset($expression->args[1]);
            }
        }

        return false;
    }

    private function returnType(\ReflectionMethod $method, ReflectionClass $class): ?array
    {
        if (preg_match('/@return\s+([^\r\n*]+)/', $method->getDocComment() ?: '', $match)) {
            $schema = $this->types->resolve(TypeResolver::docType(trim($match[1])), $class);
            if ($schema !== null) {
                return $schema;
            }
        }

        return $this->types->reflection($method->getReturnType(), $class);
    }

    private function branches(array $schemas): array
    {
        if (count($schemas) === 1) {
            return $schemas[0];
        }
        if (count(array_filter($schemas, fn ($schema) => ($schema['type'] ?? null) === 'object' && isset($schema['properties']))) === count($schemas)) {
            $properties = [];
            $required = $schemas[0]['required'] ?? [];
            foreach ($schemas as $schema) {
                foreach ((array) $schema['properties'] as $name => $property) {
                    $properties[$name] = isset($properties[$name]) ? Schema::union($properties[$name], $property) : $property;
                }
                $required = array_values(array_intersect($required, $schema['required'] ?? []));
            }

            return Schema::object($properties, $required);
        }

        return Schema::union(...$schemas);
    }
}
