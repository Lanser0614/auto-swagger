<?php

namespace AutoSwagger\Generator;

use AutoSwagger\Analyzer\RouteAnalyzer;
use AutoSwagger\Analyzer\SchemaGenerator;
use AutoSwagger\Laravel\DTO\SwaggerConfigDTO;
use Illuminate\Support\Str;

class OpenApiGenerator
{
    private RouteAnalyzer $routeAnalyzer;
    private SchemaGenerator $schemaGenerator;
    private array $config;
    private array $schemas = [];

    public function __construct(array $config = [])
    {
        $this->routeAnalyzer = new RouteAnalyzer();
        $this->schemaGenerator = new SchemaGenerator();
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function generate(): array
    {
        $routes = $this->routeAnalyzer->analyzeRoutes();

        $spec = [
            'openapi' => '3.0.0',
            'info' => $this->generateInfo(),
            'servers' => $this->generateServers(),
            'paths' => $this->generatePaths($routes),
            'components' => [
                'schemas' => $this->schemas,
                'securitySchemes' => $this->generateSecuritySchemes(),
            ],
            'tags' => $this->generateTags($routes),
        ];

        // Remove empty components if no schemas or security schemes
        if (empty($spec['components']['schemas']) && empty($spec['components']['securitySchemes'])) {
            unset($spec['components']);
        }

        return $spec;
    }

    private function generateInfo(): array
    {
        return [
            'title' => $this->config['title'],
            'description' => $this->config['description'],
            'version' => $this->config['version'],
            'contact' => $this->config['contact'],
            'license' => $this->config['license'],
        ];
    }

    private function generateServers(): array
    {
        return array_map(function ($server) {
            return [
                'url' => $server['url'],
                'description' => $server['description'] ?? null,
            ];
        }, $this->config['servers']);
    }

    private function generatePaths(array $routes): array
    {
        $paths = [];

        foreach ($routes as $route) {
            $path = $route['path'];
            $method = strtolower($route['method']);

            if (!isset($paths[$path])) {
                $paths[$path] = [];
            }

            $operation = [
                'tags' => array_map(fn($tag) => $tag['name'], $route['tags']),
                'summary' => $route['summary'],
                'description' => $route['description'],
                'operationId' => $route['operationId'],
                'parameters' => $this->formatParameters($route['parameters']),
                'responses' => $this->formatResponses($route['responses']),
            ];

            // Add request body if present
            if (isset($route['requestBody'])) {
                $operation['requestBody'] = $route['requestBody'];
            }

            // Add security if route has auth middleware
            if ($this->hasAuthMiddleware($route['middleware'])) {
                $operation['security'] = $this->getSecurityRequirements($route['middleware']);
            }

            // Add deprecated flag if route is marked as deprecated
            if (isset($route['deprecated']) && $route['deprecated']) {
                $operation['deprecated'] = true;
            }
            $config = SwaggerConfigDTO::fromArray($this->config);
            if ($config->securityBearer) {
                $operation['security'] = [[
                    "bearerAuth" => []
                ]];
            }
            $paths[$path][$method] = $operation;
        }

        return $paths;
    }

    private function formatParameters(array $parameters): array
    {
        // Group parameters by 'in' type
        $grouped = [];
        foreach ($parameters as $param) {
            $key = $param['name'] . ':' . $param['in'];
            $grouped[$key] = $param;
        }

        // Sort parameters: path first, then query
        return array_values($grouped);
    }

    private function formatResponses(array $responses): array
    {
        $formatted = [];
        foreach ($responses as $code => $response) {
            $formatted[$code] = [
                'description' => $response['description']
            ];

            if (isset($response['content'])) {
                $formatted[$code]['content'] = $response['content'];
            }
        }
        return $formatted;
    }

    private function generateSecuritySchemes(): array
    {
        $schemes = [];

        // Add Bearer token auth if configured
        if ($this->config['security']['bearer']['enabled']) {
            $schemes['bearerAuth'] = [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT'
            ];
        }

        // Add OAuth2 if configured
        if ($this->config['security']['oauth2']['enabled']) {
            $schemes['oauth2'] = [
                'type' => 'oauth2',
                'flows' => $this->config['security']['oauth2']['flows']
            ];
        }

        // Add API key if configured
        if ($this->config['security']['apiKey']['enabled']) {
            $schemes['apiKey'] = [
                'type' => 'apiKey',
                'in' => $this->config['security']['apiKey']['in'],
                'name' => $this->config['security']['apiKey']['name']
            ];
        }

        return $schemes;
    }

    private function generateTags(array $routes): array
    {
        $tags = [];
        $tagNames = [];

        foreach ($routes as $route) {
            foreach ($route['tags'] as $tag) {
                if (!in_array($tag['name'], $tagNames)) {
                    $tags[] = $tag;
                    $tagNames[] = $tag['name'];
                }
            }
        }

        // Sort tags by name
        usort($tags, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $tags;
    }

    private function hasAuthMiddleware(array $middleware): bool
    {
        $authMiddleware = ['auth', 'auth:api', 'auth:sanctum'];
        return !empty(array_intersect($authMiddleware, $middleware));
    }

    private function getSecurityRequirements(array $middleware): array
    {
        $security = [];

        if ($this->hasAuthMiddleware($middleware)) {
            if ($this->config['security']['bearer']['enabled']) {
                $security[] = ['bearerAuth' => []];
            }
            if ($this->config['security']['oauth2']['enabled']) {
                $security[] = ['oauth2' => $this->config['security']['oauth2']['scopes']];
            }
        }

        if (in_array('api_key', $middleware) && $this->config['security']['apiKey']['enabled']) {
            $security[] = ['apiKey' => []];
        }

        return $security;
    }

    private function getDefaultConfig(): array
    {
        return [
            'title' => 'API Documentation',
            'description' => 'API Documentation generated by AutoSwagger',
            'version' => '1.0.0',
            'contact' => [
                'name' => '',
                'url' => '',
                'email' => ''
            ],
            'license' => [
                'name' => '',
                'url' => ''
            ],
            'servers' => [
                [
                    'url' => config('app.url') . '/api',
                    'description' => 'API Server'
                ]
            ],
            'security' => [
                'bearer' => [
                    'enabled' => true
                ],
                'oauth2' => [
                    'enabled' => false,
                    'flows' => [
                        'password' => [
                            'tokenUrl' => '/oauth/token',
                            'scopes' => []
                        ]
                    ],
                    'scopes' => []
                ],
                'apiKey' => [
                    'enabled' => false,
                    'in' => 'header',
                    'name' => 'X-API-Key'
                ]
            ]
        ];
    }
}
