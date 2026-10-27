<?php

namespace AutoSwagger\Analyzer;

use AutoSwagger\Analysis\Ast\ResourceReturnParser;
use AutoSwagger\Analysis\Ast\SourceReader;
use AutoSwagger\Analysis\Diagnostics;
use AutoSwagger\Analysis\Options;
use AutoSwagger\Analysis\ResponseSchemaBuilder;
use AutoSwagger\Analysis\Schema;
use AutoSwagger\Attributes\ApiResponseException;
use AutoSwagger\Attributes\ApiSwagger;
use AutoSwagger\Attributes\ApiSwaggerQuery;
use AutoSwagger\Attributes\ApiSwaggerRequest;
use AutoSwagger\Attributes\ApiSwaggerResponse;
use BackedEnum;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

class RouteAnalyzer
{
    private SchemaGenerator $schemaGenerator;

    private ResourceSchemaGenerator $resourceSchemaGenerator;

    private Options $options;

    private Diagnostics $diagnostics;

    private ResourceReturnParser $returnParser;

    private ResponseSchemaBuilder $responses;

    public function __construct(array|Options $analysis = [])
    {
        $this->options = $analysis instanceof Options ? $analysis : new Options($analysis);
        $this->diagnostics = new Diagnostics($this->options);
        $source = new SourceReader;
        $this->schemaGenerator = new SchemaGenerator($this->options, $this->diagnostics, $source);
        $this->resourceSchemaGenerator = new ResourceSchemaGenerator($this->options, $this->diagnostics, $source);
        $this->returnParser = new ResourceReturnParser($this->resourceSchemaGenerator);
        $this->responses = new ResponseSchemaBuilder($this->resourceSchemaGenerator, $this->diagnostics);
    }

    public function getSchemas(): array
    {
        return $this->resourceSchemaGenerator->getSchemas();
    }

    public function getDiagnostics(): array
    {
        return $this->diagnostics->all();
    }

    public function analyzeRoutes(): array
    {
        $routes = [];
        $errors = [];
        foreach (Route::getRoutes() as $route) {
            try {
                $info = $this->analyzeRoute($route);
                if ($info === null) {
                    continue;
                }
                foreach ($route->methods() as $method) {
                    if ($method === 'HEAD' && in_array('GET', $route->methods(), true)) {
                        continue;
                    }
                    $routes[] = array_replace($info, ['method' => strtolower($method)]);
                }
            } catch (Throwable $e) {
                $errors[] = $route->uri().': '.$e->getMessage();
                Log::error('Swagger-Error: '.end($errors), ['exception' => $e]);
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException("Unable to document routes:\n".implode("\n", $errors));
        }

        return $routes;
    }

    private function analyzeRoute(LaravelRoute $route): ?array
    {
        $controller = $route->getAction('controller');
        if (! is_string($controller)) {
            return null;
        }
        [$controller, $method] = array_pad(explode('@', $controller, 2), 2, '__invoke');
        $reflection = new ReflectionMethod($controller, $method);
        $attribute = $reflection->getAttributes(ApiSwagger::class)[0] ?? null;
        if ($attribute === null) {
            return null;
        }
        $metadata = $attribute->newInstance();
        $tag = $metadata->tag ?? trim($route->getPrefix() ?? '', '/');
        if ($tag === '') {
            $tag = preg_replace('/Controller$/', '', $reflection->getDeclaringClass()->getShortName());
        }
        $info = [
            'tags' => [['name' => $tag, 'description' => "Endpoints for {$tag}"]],
            'path' => '/'.trim(preg_replace('/\{(\w+)\?\}/', '{$1}', $route->uri()), '/'),
            'summary' => $metadata->summary,
            'description' => $metadata->description ?? $this->docDescription($reflection),
            'operationId' => $metadata->operationId,
            'operationName' => $controller.'_'.$method,
            'parameters' => [...$this->getRouteParameters($route, $reflection), ...($metadata->parameters ?? [])],
            'responses' => array_replace($metadata->responses ?? [], $this->getResponseInfo($reflection)),
            'middleware' => $route->gatherMiddleware(),
            'deprecated' => $metadata->deprecated ?? false,
        ];
        if ($info['responses'] === []) {
            $info['responses'] = [200 => ['description' => 'Successful operation']];
        }
        $requestBody = $this->getRequestBody($reflection);
        if ($requestBody !== []) {
            $info['requestBody'] = $requestBody;
        }

        return $info;
    }

    private function docDescription(ReflectionMethod $method): string
    {
        if (preg_match('/@description\s+([^\r\n]+)/', $method->getDocComment() ?: '', $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    private function getRequestBody(ReflectionMethod $method): array
    {
        $attribute = $method->getAttributes(ApiSwaggerRequest::class)[0] ?? null;
        $requestClass = null;
        foreach ($method->getParameters() as $parameter) {
            $attribute ??= $parameter->getAttributes(ApiSwaggerRequest::class)[0] ?? null;
            foreach ($this->parameterClasses($parameter) as $class) {
                if (is_subclass_of($class, 'Illuminate\\Foundation\\Http\\FormRequest')) {
                    $requestClass ??= $class;
                }
            }
        }
        $metadata = $attribute?->newInstance();
        $requestClass = $metadata?->request ?? $requestClass;
        if ($requestClass === null && $metadata?->schema === null) {
            return [];
        }
        $schema = $metadata?->schema ?? $this->schemaGenerator->generateFromFormRequest($requestClass);
        $schema = $this->resourceSchemaGenerator->normalize($schema);
        $mediaType = $metadata?->mediaType ?? ($this->schemaGenerator->containsFile($schema) ? 'multipart/form-data' : 'application/json');

        return [
            'description' => $metadata?->description ?? 'Request data',
            'required' => $metadata?->required ?? true,
            'content' => [$mediaType => ['schema' => $schema]],
        ];
    }

    private function getRouteParameters(LaravelRoute $route, ReflectionMethod $method): array
    {
        $parameters = [];
        $methodParameters = [];
        foreach ($method->getParameters() as $parameter) {
            $methodParameters[$parameter->getName()] = $parameter;
        }
        foreach ($route->parameterNames() as $name) {
            $parameters[] = [
                'name' => $name, 'in' => 'path', 'required' => true,
                'schema' => isset($methodParameters[$name]) ? $this->parameterSchema($methodParameters[$name]) : ['type' => 'string'],
            ];
        }
        foreach ($method->getAttributes(ApiSwaggerQuery::class) as $attribute) {
            $query = $attribute->newInstance();
            if ($query->name === null || $query->name === '') {
                throw new \InvalidArgumentException('ApiSwaggerQuery requires a parameter name.');
            }
            if ($query->isId && ! in_array($query->name, $route->parameterNames(), true)) {
                throw new \InvalidArgumentException("Path parameter {$query->name} does not occur in the route.");
            }
            $parameters[] = [
                'name' => $query->name,
                'in' => $query->isId ? 'path' : 'query',
                'required' => $query->isId ? true : ($query->required ?? false),
                'description' => $query->description ?? '',
                'schema' => ['type' => $query->type] + ($query->type === 'array' ? ['items' => ['type' => 'string']] : []),
            ];
        }

        return $parameters;
    }

    private function parameterClasses(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        return array_values(array_filter(array_map(fn ($type) => $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null, $types)));
    }

    private function parameterSchema(ReflectionParameter $parameter): array
    {
        foreach ($this->parameterClasses($parameter) as $class) {
            if (is_subclass_of($class, BackedEnum::class)) {
                $values = array_map(fn ($case) => $case->value, $class::cases());

                return ['type' => is_int($values[0] ?? null) ? 'integer' : 'string', 'enum' => $values];
            }
        }
        $type = $parameter->getType();

        return ['type' => match ($type instanceof ReflectionNamedType ? $type->getName() : null) {
            'int' => 'integer', 'float' => 'number', 'bool' => 'boolean', default => 'string',
        }];
    }

    private function getResponseInfo(ReflectionMethod $method): array
    {
        $responses = [];
        foreach ($method->getAttributes() as $attribute) {
            if (! in_array($attribute->getName(), [ApiSwaggerResponse::class, ApiResponseException::class], true)) {
                continue;
            }
            $response = $attribute->newInstance();
            $data = ['description' => $response->description ?? 'Successful operation'];
            if ($response->resource) {
                $schema = $this->resourceSchemaGenerator->generate($response->resource);
                $resourceClass = is_string($response->resource) ? $response->resource : null;
                if ($response instanceof ApiSwaggerResponse) {
                    if ($response->isPagination) {
                        $schema = ResponseSchemaBuilder::legacyPagination($schema);
                    } else {
                        if ($response->isCollection) {
                            $schema = ['type' => 'array', 'items' => $schema];
                        }
                        $schema = $this->responses->wrap($schema, $resourceClass, $response->wrapping ?? $this->options->get('response_wrapping'), $response->pagination, [], $response->isCollection === true);
                    }
                }
                $data['content'][$response->mediaType ?? 'application/json'] = ['schema' => $schema];
            }
            $responses[$response->status] = $data;
        }
        $metadata = ($method->getAttributes(ApiSwagger::class)[0] ?? null)?->newInstance();
        if ($responses === [] && empty($metadata?->responses) && $this->options->get('resource_inference') !== 'disabled') {
            $descriptors = $this->returnParser->analyze($method);
            if ($descriptors !== []) {
                $schemas = array_map(fn ($descriptor) => $this->responses->descriptor($descriptor, $this->options->get('response_wrapping')), $descriptors);
                $responses[200] = ['description' => 'Successful operation', 'content' => ['application/json' => ['schema' => Schema::union(...$schemas)]]];
            }
        }
        if (! isset($responses[422])) {
            foreach ($method->getParameters() as $parameter) {
                foreach ($this->parameterClasses($parameter) as $class) {
                    if (is_subclass_of($class, 'Illuminate\\Foundation\\Http\\FormRequest')) {
                        $responses[422] = ['description' => 'Validation error', 'content' => ['application/json' => ['schema' => [
                            'type' => 'object', 'properties' => [
                                'message' => ['type' => 'string'],
                                'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            ],
                        ]]]];
                    }
                }
            }
        }

        return $responses;
    }

    public function generateResourceSchemaFromArray(array $properties): array
    {
        return $this->resourceSchemaGenerator->fromProperties($properties);
    }
}
