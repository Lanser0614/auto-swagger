<?php

namespace AutoSwagger\Analysis\Ast;

use AutoSwagger\Analyzer\ResourceSchemaGenerator;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class ResourceReturnParser
{
    public function __construct(private readonly ResourceSchemaGenerator $resources) {}

    public function analyze(ReflectionMethod $method): array
    {
        $class = $method->getDeclaringClass();
        $node = $this->resources->source()->method($method);
        $descriptors = [];
        foreach ($node ? $this->resources->source()->returns($node) : [] as [$expression, $variables]) {
            $descriptors = [...$descriptors, ...$this->expression($expression, $class, $variables)];
        }
        if ($descriptors === []) {
            $type = $method->getReturnType();
            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), JsonResource::class)) {
                $descriptors[] = ['class' => $type->getName(), 'collection' => is_subclass_of($type->getName(), ResourceCollection::class), 'pagination' => null, 'additional' => []];
            }
        }

        return $descriptors;
    }

    private function expression(Expr $expression, ReflectionClass $class, array $variables): array
    {
        if ($expression instanceof Expr\Variable && is_string($expression->name) && isset($variables[$expression->name])) {
            $value = $variables[$expression->name];
            unset($variables[$expression->name]);

            return $this->expression($value, $class, $variables);
        }
        if ($expression instanceof Expr\Ternary) {
            return [...$this->expression($expression->if ?? $expression->cond, $class, $variables), ...$this->expression($expression->else, $class, $variables)];
        }
        if ($expression instanceof Expr\MethodCall && $expression->name instanceof Node\Identifier && in_array($expression->name->toString(), ['additional', 'response'], true)) {
            $descriptors = $this->expression($expression->var, $class, $variables);
            if ($expression->name->toString() === 'additional' && isset($expression->args[0])) {
                $schema = $this->resources->parser()->expression($expression->args[0]->value, $class, $variables, 'additional')->schema;
                foreach ($descriptors as &$descriptor) {
                    $descriptor['additional'] = [$schema];
                }
            }

            return $descriptors;
        }
        $resource = null;
        $collection = false;
        $argument = null;
        if ($expression instanceof Expr\New_ && $expression->class instanceof Node\Name) {
            $resource = $this->resources->source()->className($expression->class, $class);
            $collection = is_subclass_of($resource, ResourceCollection::class);
            $argument = $expression->args[0]->value ?? null;
        } elseif ($expression instanceof Expr\StaticCall && $expression->class instanceof Node\Name && $expression->name instanceof Node\Identifier && in_array($expression->name->toString(), ['make', 'collection'], true)) {
            $resource = $this->resources->source()->className($expression->class, $class);
            $collection = $expression->name->toString() === 'collection';
            $argument = $expression->args[0]->value ?? null;
        }
        if ($resource === null || ! is_subclass_of($resource, JsonResource::class)) {
            return [];
        }

        return [['class' => $resource, 'collection' => $collection, 'pagination' => $collection ? $this->pagination($argument, $variables) : null, 'additional' => []]];
    }

    private function pagination(?Expr $expression, array $variables): ?string
    {
        if ($expression instanceof Expr\New_ && $expression->class instanceof Node\Name) {
            $class = ltrim($expression->class->toString(), '\\');
            if (is_a($class, LengthAwarePaginator::class, true)) {
                return 'laravel';
            }
            if (is_a($class, CursorPaginator::class, true)) {
                return 'cursor';
            }
            if (is_a($class, Paginator::class, true)) {
                return 'simple';
            }
        }
        if ($expression instanceof Expr\Variable && is_string($expression->name) && isset($variables[$expression->name])) {
            $value = $variables[$expression->name];
            unset($variables[$expression->name]);

            return $this->pagination($value, $variables);
        }
        if (($expression instanceof Expr\MethodCall || $expression instanceof Expr\StaticCall) && $expression->name instanceof Node\Identifier) {
            return match ($expression->name->toString()) {
                'paginate' => 'laravel', 'simplePaginate' => 'simple', 'cursorPaginate' => 'cursor',
                default => $expression instanceof Expr\MethodCall ? $this->pagination($expression->var, $variables) : null,
            };
        }

        return null;
    }
}
