<?php

namespace AutoSwagger\Analysis\Rules;

use AutoSwagger\Analysis\Ast\SourceReader;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use PhpParser\Node;
use ReflectionClass;

final class StaticRuleExtractor
{
    public function __construct(private readonly SourceReader $source) {}

    public function closure(\Closure $closure): ?array
    {
        $node = $this->source->closure($closure);
        if ($node === null) {
            return null;
        }
        $returns = $this->source->returns($node);
        if (count($returns) !== 1) {
            return null;
        }
        [$expression, $variables] = $returns[0];
        $scope = (new \ReflectionFunction($closure))->getClosureScopeClass();
        $value = $this->expression($expression, $scope, $variables, $known);

        return $known && is_array($value) ? $value : null;
    }

    public function method(\ReflectionMethod $method): ?array
    {
        $node = $this->source->method($method);
        $returns = $node ? $this->source->returns($node) : [];
        if (count($returns) !== 1) {
            return null;
        }
        [$expression, $variables] = $returns[0];
        $value = $this->expression($expression, $method->getDeclaringClass(), $variables, $known);

        return $known && is_array($value) ? $value : null;
    }

    private function expression(Node\Expr $expression, ?ReflectionClass $scope, array $variables, ?bool &$known = null): mixed
    {
        $value = $this->source->literal($expression, $scope, $variables, $known);
        if ($known) {
            return $value;
        }
        if ($expression instanceof Node\Expr\Array_) {
            $result = [];
            foreach ($expression->items as $item) {
                if ($item === null || $item->unpack) {
                    $known = false;

                    return null;
                }
                $value = $this->expression($item->value, $scope, $variables, $known);
                if (! $known) {
                    return null;
                }
                if ($item->key === null) {
                    $result[] = $value;
                } else {
                    $key = $this->source->literal($item->key, $scope, $variables, $known);
                    if (! $known || (! is_int($key) && ! is_string($key))) {
                        $known = false;

                        return null;
                    }
                    $result[$key] = $value;
                }
            }
            $known = true;

            return $result;
        }
        if ($expression instanceof Node\Expr\StaticCall && $expression->class instanceof Node\Name && $expression->name instanceof Node\Identifier && $scope) {
            $class = $this->source->className($expression->class, $scope);
            $method = $expression->name->toString();
            $arguments = [];
            foreach ($expression->args as $argument) {
                $arguments[] = $this->source->literal($argument->value, $scope, $variables, $known);
                if (! $known) {
                    return null;
                }
            }
            if ($class === Rule::class && in_array($method, ['in', 'notIn', 'enum'], true)) {
                $known = true;

                return Rule::$method(...$arguments);
            }
            if ($class === File::class && in_array($method, ['types', 'image'], true)) {
                $known = true;

                return File::$method(...$arguments);
            }
        }
        if ($expression instanceof Node\Expr\MethodCall && $expression->name instanceof Node\Identifier) {
            $object = $this->expression($expression->var, $scope, $variables, $known);
            if (! $known) {
                return null;
            }
            $method = $expression->name->toString();
            $arguments = [];
            foreach ($expression->args as $argument) {
                $arguments[] = $this->source->literal($argument->value, $scope, $variables, $known);
                if (! $known) {
                    return null;
                }
            }
            if (($object instanceof File && in_array($method, ['min', 'max', 'size'], true)) || ($object instanceof Enum && in_array($method, ['only', 'except'], true))) {
                $known = true;

                return $object->$method(...$arguments);
            }
        }
        $known = false;

        return null;
    }
}
