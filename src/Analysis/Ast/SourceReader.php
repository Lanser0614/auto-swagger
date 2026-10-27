<?php

namespace AutoSwagger\Analysis\Ast;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;

final class SourceReader
{
    private array $cache = [];

    public function nodes(string $file): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new \RuntimeException("Unable to read PHP source: {$file}");
        }
        $hash = sha1($source);
        if (($this->cache[$file]['hash'] ?? null) !== $hash) {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
            $traverser = new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true]));
            $this->cache[$file] = ['hash' => $hash, 'nodes' => $traverser->traverse($nodes)];
        }

        return $this->cache[$file]['nodes'];
    }

    public function method(ReflectionMethod $method): ?Node\Stmt\ClassMethod
    {
        if (! $method->getFileName()) {
            return null;
        }
        $matches = (new NodeFinder)->find($this->nodes($method->getFileName()), fn ($node) => $node instanceof Node\Stmt\ClassMethod && $node->name->toString() === $method->getName() && $node->getStartLine() <= $method->getStartLine() && $node->getEndLine() === $method->getEndLine());

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    public function closure(\Closure $closure): Node\Expr\Closure|Node\Expr\ArrowFunction|null
    {
        $reflection = new ReflectionFunction($closure);
        if (! $reflection->getFileName() || ! is_file($reflection->getFileName())) {
            return null;
        }
        $matches = (new NodeFinder)->find($this->nodes($reflection->getFileName()), fn ($node) => ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) && $node->getStartLine() === $reflection->getStartLine() && $node->getEndLine() === $reflection->getEndLine());

        // Two closures on one line cannot be distinguished reliably by Reflection.
        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    public function className(Node\Name $name, ReflectionClass $context): string
    {
        return match (strtolower($name->toString())) {
            'self', 'static' => $context->getName(),
            'parent' => $context->getParentClass()?->getName() ?? $context->getName(),
            default => ltrim($name->toString(), '\\'),
        };
    }

    public function docClass(string $name, ReflectionClass $context): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return $this->className(new Node\Name($name), $context);
        }
        $namespace = $context->getNamespaceName();
        $nodes = $context->getFileName() ? $this->nodes($context->getFileName()) : [];
        foreach ($nodes as $node) {
            $statements = $node instanceof Node\Stmt\Namespace_ && ($node->name?->toString() ?? '') === $namespace ? $node->stmts : ($namespace === '' ? [$node] : []);
            foreach ($statements as $statement) {
                if ($statement instanceof Node\Stmt\Use_ || $statement instanceof Node\Stmt\GroupUse) {
                    foreach ($statement->uses as $use) {
                        if (($use->type ?: $statement->type) !== Node\Stmt\Use_::TYPE_NORMAL) {
                            continue;
                        }
                        $import = ($statement instanceof Node\Stmt\GroupUse ? $statement->prefix->toString().'\\' : '').$use->name->toString();
                        $alias = $use->alias?->toString() ?? $use->name->getLast();
                        $parts = explode('\\', $name, 2);
                        if (strcasecmp($parts[0], $alias) === 0) {
                            return $import.(isset($parts[1]) ? '\\'.$parts[1] : '');
                        }
                    }
                }
            }
        }

        return $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    /** Return expressions with local bindings for each control-flow path. */
    public function returns(Node\FunctionLike $node): array
    {
        if ($node instanceof Node\Expr\ArrowFunction) {
            return [[$node->expr, []]];
        }
        $returns = [];
        $walk = function (array $statements, array $states) use (&$walk, &$returns): array {
            foreach ($statements as $statement) {
                $next = [];
                foreach ($states as $variables) {
                    if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign) {
                        $assignment = $statement->expr;
                        $bound = $this->bind($assignment->expr, $variables);
                        if ($assignment->var instanceof Node\Expr\Variable && is_string($assignment->var->name)) {
                            $variables[$assignment->var->name] = $bound;
                        } elseif ($assignment->var instanceof Node\Expr\ArrayDimFetch) {
                            $root = $assignment->var;
                            $dimensions = [];
                            while ($root instanceof Node\Expr\ArrayDimFetch) {
                                array_unshift($dimensions, $root->dim);
                                $root = $root->var;
                            }
                            if ($root instanceof Node\Expr\Variable && is_string($root->name)) {
                                $array = $variables[$root->name] ?? new Node\Expr\Array_;
                                $variables[$root->name] = $this->arrayAssignment($array, $dimensions, $bound, $variables);
                            }
                        }
                        $next[] = $variables;
                    } elseif ($statement instanceof Node\Stmt\Return_ && $statement->expr !== null) {
                        $returns[] = [$this->bind($statement->expr, $variables), $variables];
                    } elseif ($statement instanceof Node\Stmt\If_) {
                        $branches = [];
                        $remaining = true;
                        foreach ([$statement, ...$statement->elseifs] as $branch) {
                            $condition = $this->literal($this->bind($branch->cond, $variables), null, [], $known);
                            if (! $known || $condition) {
                                $branches = [...$branches, ...$walk($branch->stmts, [$variables])];
                            }
                            if ($known && $condition) {
                                $remaining = false;
                                break;
                            }
                        }
                        if ($remaining) {
                            $branches = [...$branches, ...($statement->else ? $walk($statement->else->stmts, [$variables]) : [$variables])];
                        }
                        $next = [...$next, ...$branches];
                    } elseif ($statement instanceof Node\Stmt\Switch_) {
                        $default = false;
                        foreach ($statement->cases as $case) {
                            $default = $default || $case->cond === null;
                            $next = [...$next, ...$walk($case->stmts, [$variables])];
                        }
                        if (! $default) {
                            $next[] = $variables;
                        }
                    } elseif ($statement instanceof Node\Stmt\Break_ || $statement instanceof Node\Stmt\Continue_) {
                        return [...$next, $variables];
                    } else {
                        if ($statement instanceof Node\Stmt\Foreach_ || $statement instanceof Node\Stmt\For_ || $statement instanceof Node\Stmt\While_ || $statement instanceof Node\Stmt\Do_) {
                            // A loop may change both values and keys. Invalidate affected
                            // bindings rather than emitting the pre-loop value as certain.
                            foreach ((new NodeFinder)->findInstanceOf($statement->stmts, Node\Expr\Assign::class) as $assignment) {
                                $root = $assignment->var;
                                while ($root instanceof Node\Expr\ArrayDimFetch) {
                                    $root = $root->var;
                                }
                                if ($root instanceof Node\Expr\Variable && is_string($root->name)) {
                                    $variables[$root->name] = new Node\Expr\Variable($root->name, $root->getAttributes());
                                }
                            }
                        }
                        $next[] = $variables;
                    }
                }
                $states = $next;
                if (count($states) > 256) {
                    throw new \RuntimeException('Too many PHP control-flow paths; provide an explicit schema.');
                }
                if ($states === []) {
                    break;
                }
            }

            return $states;
        };
        $walk($node->getStmts() ?? [], [[]]);

        return $returns;
    }

    private function bind(Node\Expr $expression, array $variables): Node\Expr
    {
        $traverser = new NodeTraverser(new class($variables) extends NodeVisitorAbstract
        {
            public function __construct(private readonly array $variables) {}

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\FunctionLike) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                return null;
            }

            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\Expr\Variable && is_string($node->name) && isset($this->variables[$node->name])) {
                    return clone $this->variables[$node->name];
                }

                return null;
            }
        });
        // Cached ASTs are immutable. Clone every node before applying substitutions.
        $clone = new NodeTraverser(new CloningVisitor);

        return $traverser->traverse($clone->traverse([$expression]))[0];
    }

    private function arrayAssignment(Node\Expr $array, array $dimensions, Node\Expr $value, array $variables): Node\Expr
    {
        if (! $array instanceof Node\Expr\Array_) {
            return new Node\Expr\Variable('__dynamic_array');
        }
        $array = clone $array;
        $dimension = array_shift($dimensions);
        if ($dimension !== null) {
            $dimension = $this->bind($dimension, $variables);
        }
        if ($dimensions === []) {
            $array->items[] = new Node\ArrayItem($value, $dimension);

            return $array;
        }
        $key = $this->literal($dimension, null, [], $known);
        if (! $known) {
            return new Node\Expr\Variable('__dynamic_array');
        }
        foreach (array_reverse($array->items, true) as $index => $item) {
            if ($item && $item->key && $this->literal($item->key, null, [], $itemKnown) === $key && $itemKnown) {
                $array->items[$index] = new Node\ArrayItem($this->arrayAssignment($item->value, $dimensions, $value, $variables), $dimension);

                return $array;
            }
        }
        $array->items[] = new Node\ArrayItem($this->arrayAssignment(new Node\Expr\Array_, $dimensions, $value, $variables), $dimension);

        return $array;
    }

    /** Resolve only literals/constants/arrays. Never invoke application functions. */
    public function literal(?Node\Expr $expression, ?ReflectionClass $context = null, array $variables = [], ?bool &$known = null): mixed
    {
        $known = true;
        if ($expression instanceof Node\Scalar\String_ || $expression instanceof Node\Scalar\Int_ || $expression instanceof Node\Scalar\Float_) {
            return $expression->value;
        }
        if ($expression instanceof Node\Expr\ConstFetch) {
            $name = strtolower($expression->name->toString());
            if (in_array($name, ['null', 'true', 'false'], true)) {
                return match ($name) {
                    'true' => true, 'false' => false, default => null
                };
            }
        }
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name) && isset($variables[$expression->name])) {
            $next = $variables[$expression->name];
            unset($variables[$expression->name]);

            return $this->literal($next, $context, $variables, $known);
        }
        if ($expression instanceof Node\Expr\UnaryMinus || $expression instanceof Node\Expr\UnaryPlus) {
            $value = $this->literal($expression->expr, $context, $variables, $known);

            return $known && is_numeric($value) ? ($expression instanceof Node\Expr\UnaryMinus ? -$value : +$value) : null;
        }
        if ($expression instanceof Node\Expr\ClassConstFetch && $expression->class instanceof Node\Name && $expression->name instanceof Node\Identifier && $context) {
            $class = $this->className($expression->class, $context);
            if ($expression->name->toLowerString() === 'class') {
                return $class;
            }
            if (class_exists($class) && (new ReflectionClass($class))->hasConstant($expression->name->toString())) {
                return (new ReflectionClass($class))->getReflectionConstant($expression->name->toString())->getValue();
            }
        }
        if ($expression instanceof Node\Expr\Array_) {
            $result = [];
            foreach ($expression->items as $item) {
                if ($item === null) {
                    continue;
                }
                $value = $this->literal($item->value, $context, $variables, $known);
                if (! $known) {
                    return null;
                }
                if ($item->unpack) {
                    if (! is_array($value)) {
                        $known = false;

                        return null;
                    }
                    $result = array_merge($result, $value);
                } elseif ($item->key === null) {
                    $result[] = $value;
                } else {
                    $key = $this->literal($item->key, $context, $variables, $known);
                    if (! $known || (! is_int($key) && ! is_string($key))) {
                        $known = false;

                        return null;
                    }
                    $result[$key] = $value;
                }
            }

            return $result;
        }
        $known = false;

        return null;
    }
}
