<?php

namespace AutoSwagger\Generator;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use ReflectionNamedType;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Throwable;

class SchemaGenerator
{
    private array $schemas = [];
    private array $modelProperties = [];

    /**
     * @param string $requestClass
     * @return array
     * @throws ReflectionException
     */
    public function generateFromFormRequest(string $requestClass): array
    {
        if (!class_exists($requestClass) || !is_subclass_of($requestClass, FormRequest::class)) {
            return [];
        }

        $reflection = new ReflectionClass($requestClass);
        $instance = $reflection->newInstanceWithoutConstructor();
        
        // Get rules from the rules() method
        $rulesMethod = $reflection->getMethod('rules');
        $rules = $rulesMethod->invoke($instance);

        return $this->generateSchemaFromRules($rules, $requestClass);
    }

    public function generateFromResource(string $resourceClass, bool $isCollection = false): array
    {
        if (!class_exists($resourceClass) || !is_subclass_of($resourceClass, JsonResource::class)) {
            return [];
        }

        $reflection = new ReflectionClass($resourceClass);
        
        // Try multiple methods to get properties
        $properties = [];
        
        // 1. Try to get from PHPDoc
        $properties = array_merge($properties, $this->getPropertiesFromPhpDoc($reflection));
        
        // 2. Try to get from underlying model
        $properties = array_merge($properties, $this->getPropertiesFromModel($reflection));
        
        // 3. Try to analyze toArray method
        $properties = array_merge($properties, $this->getPropertiesFromToArray($reflection));
        
        // 4. Try to get from resource attributes
        $properties = array_merge($properties, $this->getPropertiesFromAttributes($reflection));

        $schema = [
            'type' => 'object',
            'properties' => $properties
        ];

        if ($isCollection) {
            $schema = [
                'type' => 'array',
                'items' => $schema
            ];
        }

        return $schema;
    }

    private function getPropertiesFromPhpDoc(ReflectionClass $reflection): array
    {
        $properties = [];
        $docComment = $reflection->getDocComment();
        
        if (!$docComment) {
            return [];
        }

        $pattern = '/@property\s+([^\s]+)\s+\$([^\s]+)(?:\s+(.*))?/';
        preg_match_all($pattern, $docComment, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $type = $match[1];
            $name = $match[2];
            $description = $match[3] ?? null;
            
            $properties[$name] = [
                'type' => $this->convertPhpTypeToOpenApi($type),
                'description' => $description
            ];
        }

        return $properties;
    }

    private function getPropertiesFromModel(ReflectionClass $reflection): array
    {
        $properties = [];
        
        try {
            // Try to get the model class from the resource
            $modelClass = $this->getModelClass($reflection);
            if (!$modelClass || !class_exists($modelClass)) {
                return [];
            }

            $modelReflection = new ReflectionClass($modelClass);
            
            // Get fillable properties
            if ($modelReflection->hasProperty('fillable')) {
                $fillableProp = $modelReflection->getProperty('fillable');
                $fillableProp->setAccessible(true);
                $fillable = $fillableProp->getValue(new $modelClass);
                
                foreach ($fillable as $property) {
                    $type = $this->getModelPropertyType($modelClass, $property);
                    $properties[$property] = [
                        'type' => $this->convertPhpTypeToOpenApi($type)
                    ];
                }
            }

            // Get casts
            if ($modelReflection->hasProperty('casts')) {
                $castsProp = $modelReflection->getProperty('casts');
                $castsProp->setAccessible(true);
                $casts = $castsProp->getValue(new $modelClass);
                
                foreach ($casts as $property => $type) {
                    $properties[$property] = [
                        'type' => $this->convertLaravelCastToOpenApi($type)
                    ];
                }
            }
        } catch (Throwable $e) {
            // Silently fail if we can't get model properties
        }

        return $properties;
    }

    private function getPropertiesFromToArray(ReflectionClass $reflection): array
    {
        $properties = [];
        
        try {
            $toArrayMethod = $reflection->getMethod('toArray');
            $fileName = $reflection->getFileName();
            
            if (!$fileName) {
                return [];
            }

            // Parse the PHP file
            $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
            $ast = $parser->parse(file_get_contents($fileName));

            // Resolve names
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $ast = $traverser->traverse($ast);

            // Find the toArray method and analyze its return statement
            foreach ($ast as $node) {
                if ($node instanceof ClassMethod && $node->name->toString() === 'toArray') {
                    $returnProps = $this->analyzeReturnArray($node);
                    foreach ($returnProps as $name => $type) {
                        $properties[$name] = [
                            'type' => $this->convertPhpTypeToOpenApi($type)
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // Silently fail if we can't parse the toArray method
        }

        return $properties;
    }

    private function getPropertiesFromAttributes(ReflectionClass $reflection): array
    {
        $properties = [];
        
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(ApiProperty::class);
            if (!empty($attributes)) {
                /** @var ApiProperty $apiProperty */
                $apiProperty = $attributes[0]->newInstance();
                $properties[$property->getName()] = [
                    'type' => $apiProperty->type ?? $this->getPropertyType($property),
                    'description' => $apiProperty->description,
                    'format' => $apiProperty->format ?? null,
                    'example' => $apiProperty->example ?? null
                ];
            }
        }

        return $properties;
    }

    private function getModelClass(ReflectionClass $resourceReflection): ?string
    {
        // Try to get from constructor parameter type
        $constructor = $resourceReflection->getConstructor();
        if ($constructor) {
            $params = $constructor->getParameters();
            if (!empty($params)) {
                $type = $params[0]->getType();
                if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
                    return $type->getName();
                }
            }
        }

        // Try to get from parent resource class
        $parentClass = $resourceReflection->getParentClass();
        if ($parentClass) {
            $constructor = $parentClass->getConstructor();
            if ($constructor) {
                $params = $constructor->getParameters();
                if (!empty($params)) {
                    $type = $params[0]->getType();
                    if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
                        return $type->getName();
                    }
                }
            }
        }

        return null;
    }

    private function getModelPropertyType(string $modelClass, string $property): string
    {
        if (isset($this->modelProperties[$modelClass][$property])) {
            return $this->modelProperties[$modelClass][$property];
        }

        try {
            $docComment = (new ReflectionClass($modelClass))->getProperty($property)->getDocComment();
            if ($docComment && preg_match('/@var\s+([^\s]+)/', $docComment, $matches)) {
                return $matches[1];
            }
        } catch (Throwable $e) {
            // Silently fail
        }

        return 'string';
    }

    private function convertLaravelCastToOpenApi(string $cast): string
    {
        return match ($cast) {
            'integer', 'int' => 'integer',
            'real', 'float', 'double' => 'number',
            'decimal' => 'number',
            'string' => 'string',
            'boolean', 'bool' => 'boolean',
            'object', 'array', 'json' => 'object',
            'collection' => 'array',
            'date', 'datetime', 'custom_datetime' => 'string',
            default => 'string'
        };
    }

    private function analyzeReturnArray(ClassMethod $method): array
    {
        $properties = [];
        
        // Find return statement in the method
        $returnStmt = null;
        foreach ($method->stmts as $stmt) {
            if ($stmt instanceof Return_) {
                $returnStmt = $stmt;
                break;
            }
        }

        if ($returnStmt && $returnStmt->expr instanceof Array_) {
            foreach ($returnStmt->expr->items as $item) {
                if ($item->key instanceof String_) {
                    $propertyName = $item->key->value;
                    $propertyType = $this->inferTypeFromValue($item->value);
                    $properties[$propertyName] = $propertyType;
                }
            }
        }

        return $properties;
    }

    private function inferTypeFromValue(Expr $value): string
    {
        return match (true) {
            $value instanceof LNumber => 'integer',
            $value instanceof DNumber => 'number',
            $value instanceof String_ => 'string',
            $value instanceof ConstFetch && in_array($value->name->toString(), ['true', 'false']) => 'boolean',
            $value instanceof Array_ => 'array',
            default => 'string'
        };
    }

    private function generateSchemaFromRules(array $rules, string $className): array
    {
        $properties = [];
        
        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);
            $property = $this->generatePropertyFromRules($fieldRules);
            
            if ($property) {
                $properties[$field] = $property;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $this->getRequiredFields($rules)
        ];
    }

    private function generatePropertyFromRules(array $rules): array
    {
        $property = [];
        $property['type'] = $this->determineType($rules);

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $this->processStringRule($rule, $property);
            } elseif ($rule instanceof Rule) {
                $this->processRuleObject($rule, $property);
            }
        }

        return $property;
    }

    private function determineType(array $rules): string
    {
        foreach ($rules as $rule) {
            $rule = is_string($rule) ? $rule : (string) $rule;
            
            switch (true) {
                case str_contains($rule, 'integer'):
                case str_contains($rule, 'numeric'):
                    return 'integer';
                case str_contains($rule, 'boolean'):
                    return 'boolean';
                case str_contains($rule, 'array'):
                    return 'array';
                case str_contains($rule, 'file'):
                case str_contains($rule, 'image'):
                    return 'string';
                    break;
            }
        }

        return 'string';
    }

    private function processStringRule(string $rule, array &$property): void
    {
        if (str_starts_with($rule, 'max:')) {
            $property['maxLength'] = (int) substr($rule, 4);
        } elseif (str_starts_with($rule, 'min:')) {
            $property['minLength'] = (int) substr($rule, 4);
        } elseif ($rule === 'email') {
            $property['format'] = 'email';
        } elseif ($rule === 'url') {
            $property['format'] = 'uri';
        } elseif ($rule === 'date') {
            $property['format'] = 'date';
        } elseif ($rule === 'datetime') {
            $property['format'] = 'date-time';
        } elseif (str_starts_with($rule, 'regex:')) {
            $property['pattern'] = substr($rule, 6);
        }
    }

    private function processRuleObject(Rule $rule, array &$property): void
    {
        $ruleClass = get_class($rule);
        
        switch ($ruleClass) {
            case Rule\Enum::class:
                $property['enum'] = $rule->values;
                break;
            case Rule\In::class:
                $property['enum'] = $rule->values;
                break;
        }
    }

    private function getRequiredFields(array $rules): array
    {
        $required = [];
        
        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);
            
            if (in_array('required', $fieldRules)) {
                $required[] = $field;
            }
        }

        return $required;
    }

    private function parseResourceProperties(?string $docComment): array
    {
        if (!$docComment) {
            return [];
        }

        $properties = [];
        $pattern = '/@property\s+([^\s]+)\s+\$([^\s]+)(?:\s+(.*))?/';
        
        preg_match_all($pattern, $docComment, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $type = $match[1];
            $name = $match[2];
            $description = $match[3] ?? null;
            
            $properties[$name] = [
                'type' => $this->convertPhpTypeToOpenApi($type),
                'description' => $description
            ];
        }

        return $properties;
    }

    private function convertPhpTypeToOpenApi(string $type): string
    {
        return match ($type) {
            'int', 'integer' => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            default => 'string'
        };
    }
}
