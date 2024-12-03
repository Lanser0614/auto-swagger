<?php

namespace AutoSwagger\Analyzer;

use AutoSwagger\Attributes\ApiSwagger;
use AutoSwagger\Attributes\ApiProperty;
use AutoSwagger\Attributes\ApiSwaggerResponse;
use AutoSwagger\Attributes\ApiSwaggerResource;
use AutoSwagger\Attributes\ApiResponseException;
use ReflectionClass;
use ReflectionMethod;
use Illuminate\Support\Facades\Route;
use Illuminate\Routing\Route as LaravelRoute;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

class RouteAnalyzer
{
    private array $routeCache = [];
    private SchemaGenerator $schemaGenerator;

    public function __construct()
    {
        $this->schemaGenerator = new SchemaGenerator();
    }

    /**
     * Analyze all routes in the application
     */
    public function analyzeRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            try {
                if ($routeInfo = $this->analyzeRoute($route)) {
                    $routes[] = $routeInfo;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $routes;
    }

    /**
     * Analyze a single route
     */
    private function analyzeRoute(LaravelRoute $route): ?array
    {
        $action = $route->getAction();

        // Skip routes without controller actions
        if (!isset($action['controller'])) {
            return null;
        }

        // Get controller and method
        [$controller, $method] = explode('@', $action['controller']);

        try {
            $reflection = new ReflectionClass($controller);
            $methodReflection = $reflection->getMethod($method);

            $isNeedAddToSwagger = false;
            if ($methodReflection->getAttributes()) {
                foreach ($methodReflection->getAttributes() as $attribute) {
                    if ($attribute->getName() === ApiSwagger::class) {
                        $isNeedAddToSwagger = true;
                    }
                }
            }

            if ($isNeedAddToSwagger === false) {
                return null;
            }

            // Get route metadata
            $routeInfo = [
                'tags' => $this->getRouteTags($methodReflection, $route),
                'path' => $this->formatRoutePath($route->uri()),
                'method' => strtolower($route->methods()[0]),
                'summary' => $this->generateSummary($methodReflection, $route),
                'description' => $this->getRouteDescription($methodReflection, $route),
                'operationId' => $method,
                'parameters' => $this->getRouteParameters($route, $methodReflection),
                'responses' => $this->getResponseInfo($methodReflection),
                'middleware' => $route->middleware()
            ];

//            dd($routeInfo);
            // Add request body if applicable
            $requestBody = $this->getRequestBody($methodReflection, $route);
            if (!empty($requestBody)) {
                $routeInfo['requestBody'] = $requestBody;
            }

            return $routeInfo;
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    private function getRouteTags(ReflectionMethod $methodReflection, LaravelRoute $route): array
    {
        // Try to get tag from route group
        $prefix = $route->getPrefix();

        $tags = [];
        foreach ($methodReflection->getAttributes() as $attribute) {
            if ($attribute->getName() === ApiSwagger::class) {
                $tags[] = [
                    'name' => $attribute->getArguments()['tag'],
                    'description' => 'Endpoints for ' . trim($prefix, '/')
                ];
            }
        }

        if (count($tags) > 0) {
            return $tags;
        }


        if ($prefix) {
            $tags[] = [
                'name' => trim($prefix, '/'),
                'description' => 'Endpoints for ' . trim($prefix, '/')
            ];
            return $tags;
        }

        // Fallback to controller name
        $controllerName = str_replace('Controller', '', $reflection->getShortName());
        $tags[] = [
            'name' => $controllerName,
            'description' => "Endpoints for {$controllerName}"
        ];

        return $tags;
    }

    private function getRouteDescription(ReflectionMethod $method, LaravelRoute $route): string
    {
        $docComment = $method->getDocComment();
        if ($docComment) {
            // Extract description from PHPDoc
            preg_match('/@description\s+(.+)\n/i', $docComment, $matches);
            if (isset($matches[1])) {
                return trim($matches[1]);
            }
        }

        return "Handle {$route->methods()[0]} request to {$route->uri()}";
    }

    private function getRequestBody(ReflectionMethod $method, LaravelRoute $route): array
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type) continue;

            $typeName = $type->getName();

            // Check if parameter is a form request
            if (is_subclass_of($typeName, 'Illuminate\Foundation\Http\FormRequest')) {
                $schema = $this->schemaGenerator->generateFromFormRequest($typeName);
                if (!empty($schema)) {
                    return [
                        'description' => "Request data for {$route->uri()}",
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => $schema
                            ]
                        ]
                    ];
                }
            }

            // Handle file uploads
            if (str_contains($route->uri(), 'upload') ||
                str_contains($method->getName(), 'upload') ||
                $this->hasFileValidationRules($typeName)) {
                return [
                    'description' => "File upload for {$route->uri()}",
                    'required' => true,
                    'content' => [
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'file' => [
                                        'type' => 'string',
                                        'format' => 'binary'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
            }
        }

        return [];
    }

    private function hasFileValidationRules(string $requestClass): bool
    {
        try {
            $reflection = new ReflectionClass($requestClass);
            $instance = $reflection->newInstanceWithoutConstructor();

            if (!method_exists($instance, 'rules')) {
                return false;
            }

            $rules = $instance->rules();
            $fileRules = ['file', 'image', 'mimes', 'mimetypes'];

            foreach ($rules as $fieldRules) {
                $fieldRules = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
                foreach ($fieldRules as $rule) {
                    $ruleName = is_string($rule) ? explode(':', $rule)[0] : '';
                    if (in_array($ruleName, $fileRules)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore errors
        }

        return false;
    }

    private function getRouteParameters(LaravelRoute $route, ReflectionMethod $method): array
    {
        $parameters = [];

        // Get path parameters
        foreach ($route->parameterNames() as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => !str_contains($route->uri(), "{{$name}?}"),
                'schema' => [
                    'type' => 'string'
                ],
                'description' => "The {$name} parameter"
            ];
        }

        // Get query parameters from method parameters
        foreach ($method->getParameters() as $param) {
            if (!$this->shouldIncludeParameter($param, $route)) {
                continue;
            }

            $parameters[] = [
                'name' => $param->getName(),
                'in' => 'query',
                'required' => !$param->isOptional(),
                'schema' => [
                    'type' => $this->getParameterType($param)
                ],
                'description' => $this->getParameterDescription($param)
            ];
        }

        return $parameters;
    }

    private function shouldIncludeParameter(\ReflectionParameter $param, LaravelRoute $route): bool
    {
        // Skip path parameters
        if (in_array($param->getName(), $route->parameterNames())) {
            return false;
        }

        // Skip request type parameters
        $type = $param->getType();
        if ($type) {
            $typeName = $type->getName();
            if (is_a($typeName, 'Illuminate\Http\Request', true) ||
                is_a($typeName, 'Illuminate\Foundation\Http\FormRequest', true)) {
                return false;
            }
        }

        return true;
    }

    private function getParameterDescription(\ReflectionParameter $param): string
    {
        $method = $param->getDeclaringFunction();
        $docComment = $method->getDocComment();

        if ($docComment) {
            // Try to find parameter description in PHPDoc
            preg_match('/@param\s+[^\s]+\s+\$' . $param->getName() . '\s+(.+)\n/i', $docComment, $matches);
            if (isset($matches[1])) {
                return trim($matches[1]);
            }
        }

        return "The {$param->getName()} parameter";
    }

    private function getParameterType(\ReflectionParameter $param): string
    {
        if (!$param->getType()) {
            return 'string';
        }

        return match ($param->getType()->getName()) {
            'int', 'integer' => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            default => 'string'
        };
    }

    private function formatRoutePath(string $path): string
    {
        return '/' . trim(preg_replace('/\{(\w+)\?\}/', '{$1}', $path), '/');
    }

    private function generateSummary(ReflectionMethod $method, LaravelRoute $route): string
    {
        $docComment = $method->getDocComment();
        if ($docComment) {
            // Try to get summary from PHPDoc
            preg_match('/@summary\s+(.+)\n/i', $docComment, $matches);
            if (isset($matches[1])) {
                return trim($matches[1]);
            }

            // Fallback to first line of description
            preg_match('/\*\s*([^@\n]+)\n/', $docComment, $matches);
            if (isset($matches[1])) {
                return trim($matches[1]);
            }
        }

        // Generate summary from method name
        $name = $method->getName();
        return ucfirst(trim(preg_replace('/[A-Z]/', ' $0', $name)));
    }

    private function getResponseInfo(ReflectionMethod $method): array
    {
        $responses = [];

        // Get responses from ApiSwaggerResponse attributes
        $responseAttributes = $method->getAttributes();
        foreach ($responseAttributes as $attribute) {
            if ($attribute->getName() !== ApiSwaggerResponse::class) {
                continue;
            }

            $response = $attribute->newInstance();

            $responseData = [
                'description' => $response->description ?? 'Successful operation'
            ];

            if ($response->resource) {
                $schema = $this->generateResourceSchema($response->resource, $response->isCollection);
                if (!empty($schema)) {
                    $responseData['content'][$response->mediaType] = [
                        'schema' => $schema
                    ];
                }
            }



            $responses[$response->status] = $responseData;
        }

        // If no responses defined via attributes, check PHPDoc
        if (empty($responses)) {
            $docComment = $method->getDocComment();
            if ($docComment) {
                preg_match_all('/@response\s+(\d+)\s+(.+)\n/i', $docComment, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $responses[$match[1]] = [
                        'description' => trim($match[2])
                    ];
                }
            }
        }


        // Add default responses if none specified
        if (empty($responses)) {
            $responses[200] = [
                'description' => 'Successful operation'
            ];
        }
        // Add common error responses
        if (!isset($responses[422]) && $this->hasFormRequest($method)) {
            $responses[422] = [
                'description' => 'Validation error',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string'],
                                'errors' => [
                                    'type' => 'object',
                                    'additionalProperties' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];
        }

        return $responses;
    }

    private function generateResourceSchema(string $resourceClass, bool $isCollection): array
    {
        if (!class_exists($resourceClass)) {
            return [];
        }

        $reflection = new ReflectionClass($resourceClass);
        $resourceAttribute = $reflection->getAttributes(ApiSwaggerResource::class)[0] ?? null;

        if (!$resourceAttribute) {
            return [];
        }

        $resource = $resourceAttribute->newInstance();
        $schema = [
            'type' => 'object',
            'properties' => []
        ];

        // Add properties from ApiSwaggerResource attribute
        foreach ($resource->properties as $name => $property) {
            $schema['properties'][$name] = is_array($property) ? $property : ['type' => $property];
        }



        // Add properties from ApiProperty attributes on resource class properties
        foreach ($reflection->getProperties() as $property) {
            $propertyAttribute = $property->getAttributes(ApiProperty::class)[0] ?? null;
            if ($propertyAttribute) {
                $apiProperty = $propertyAttribute->newInstance();
                $schema['properties'][$property->getName()] = [
                    'type' => $apiProperty->type,
                    'description' => $apiProperty->description
                ];
            }
        }

        if ($isCollection) {
            return [
                'type' => 'array',
                'items' => $schema
            ];
        }

        return $schema;
    }

    private function hasFormRequest(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type && is_subclass_of($type->getName(), 'Illuminate\Foundation\Http\FormRequest')) {
                return true;
            }
        }
        return false;
    }
}