<?php

namespace AutoSwagger\Generator;

use ReflectionClass;
use ReflectionMethod;
use ReflectionAttribute;
use ReflectionParameter;
use AutoSwagger\Attributes\ApiOperation;
use AutoSwagger\Attributes\ApiProperty;
use AutoSwagger\Attributes\ApiRequest;
use AutoSwagger\Attributes\ApiResponse;

class OpenApiGenerator
{
    private array $paths = [];
    private array $schemas = [];
    private array $controllers = [];
    private SchemaGenerator $schemaGenerator;

    public function __construct(
        private readonly string $title = 'API Documentation',
        private readonly string $version = '1.0.0',
        private readonly string $description = ''
    ) {
        $this->schemaGenerator = new SchemaGenerator();
    }

    public function addController(string $controllerClass): self
    {
        $this->controllers[] = $controllerClass;
        return $this;
    }

    public function generate(): array
    {
        foreach ($this->controllers as $controller) {
            $this->processController($controller);
        }

        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $this->title,
                'description' => $this->description,
                'version' => $this->version,
            ],
            'paths' => $this->paths,
            'components' => [
                'schemas' => $this->schemas
            ]
        ];
    }

    private function processController(string $controllerClass): void
    {
        $reflection = new ReflectionClass($controllerClass);
        
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $this->processMethod($method);
        }
    }

    private function processMethod(ReflectionMethod $method): void
    {
        $attributes = $method->getAttributes(ApiOperation::class);
        
        if (empty($attributes)) {
            return;
        }

        foreach ($attributes as $attribute) {
            /** @var ApiOperation $operation */
            $operation = $attribute->newInstance();
            
            $path = $this->getPathFromMethod($method);
            $httpMethod = $this->getHttpMethodFromMethod($method);
            
            $operationArray = [
                'summary' => $operation->summary,
                'description' => $operation->description,
                'tags' => $operation->tags,
                'operationId' => $operation->operationId ?? $method->getName(),
                'parameters' => $this->processParameters($method),
                'responses' => $this->processResponses($method),
                'deprecated' => $operation->deprecated,
            ];

            // Process request body
            $requestBody = $this->processRequestBody($method);
            if (!empty($requestBody)) {
                $operationArray['requestBody'] = $requestBody;
            }

            $this->paths[$path][$httpMethod] = $operationArray;
        }
    }

    private function processRequestBody(ReflectionMethod $method): array
    {
        $requestAttributes = $method->getAttributes(ApiRequest::class);
        if (empty($requestAttributes)) {
            return [];
        }

        /** @var ApiRequest $requestAttr */
        $requestAttr = $requestAttributes[0]->newInstance();
        
        if ($requestAttr->request) {
            $schema = $this->schemaGenerator->generateFromFormRequest($requestAttr->request);
            
            if (!empty($schema)) {
                $schemaName = class_basename($requestAttr->request);
                $this->schemas[$schemaName] = $schema;

                return [
                    'required' => $requestAttr->required,
                    'description' => $requestAttr->description,
                    'content' => [
                        $requestAttr->mediaType => [
                            'schema' => [
                                '$ref' => '#/components/schemas/' . $schemaName
                            ]
                        ]
                    ]
                ];
            }
        }

        return [];
    }

    private function processResponses(ReflectionMethod $method): array
    {
        $responses = [];
        $responseAttributes = $method->getAttributes(ApiResponse::class);

        foreach ($responseAttributes as $attribute) {
            /** @var ApiResponse $response */
            $response = $attribute->newInstance();
            
            if ($response->resource) {
                $schema = $this->schemaGenerator->generateFromResource(
                    $response->resource,
                    $response->isCollection
                );
                
                if (!empty($schema)) {
                    $schemaName = class_basename($response->resource);
                    $this->schemas[$schemaName] = $schema;

                    $responses[$response->status] = [
                        'description' => $response->description ?? 'Successful response',
                        'content' => [
                            $response->mediaType => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/' . $schemaName
                                ]
                            ]
                        ]
                    ];
                    continue;
                }
            }

            $responses[$response->status] = [
                'description' => $response->description ?? 'Successful response'
            ];
        }

        if (empty($responses)) {
            $responses['200'] = [
                'description' => 'Successful operation'
            ];
        }

        return $responses;
    }

    private function getPathFromMethod(ReflectionMethod $method): string
    {
        // This is a simple implementation. You might want to add your own logic
        // to extract the path from method/class attributes or naming conventions
        $className = $method->getDeclaringClass()->getShortName();
        $basePath = strtolower(str_replace('Controller', '', $className));
        return '/' . $basePath . '/' . $method->getName();
    }

    private function getHttpMethodFromMethod(ReflectionMethod $method): string
    {
        // This is a simple implementation. You might want to add your own logic
        // to extract the HTTP method from method attributes or naming conventions
        $methodName = strtolower($method->getName());
        $httpMethods = ['get', 'post', 'put', 'delete', 'patch'];
        
        foreach ($httpMethods as $httpMethod) {
            if (str_starts_with($methodName, $httpMethod)) {
                return $httpMethod;
            }
        }
        
        return 'get';
    }

    private function processParameters(ReflectionMethod $method): array
    {
        $parameters = [];
        
        foreach ($method->getParameters() as $param) {
            $parameter = $this->processParameter($param);
            if ($parameter) {
                $parameters[] = $parameter;
            }
        }

        return $parameters;
    }

    private function processParameter(ReflectionParameter $param): ?array
    {
        $attributes = $param->getAttributes(ApiProperty::class);
        if (empty($attributes)) {
            return null;
        }

        /** @var ApiProperty $property */
        $property = $attributes[0]->newInstance();

        return [
            'name' => $param->getName(),
            'in' => 'query',
            'description' => $property->description,
            'required' => $property->required,
            'schema' => [
                'type' => $property->type ?? $this->getParameterType($param)
            ]
        ];
    }

    private function getParameterType(ReflectionParameter $param): string
    {
        $type = $param->getType();
        if (!$type) {
            return 'string';
        }

        return match ($type->getName()) {
            'int', 'integer' => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            default => 'string'
        };
    }
}
