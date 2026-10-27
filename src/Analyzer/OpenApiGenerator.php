<?php

namespace AutoSwagger\Analyzer;

use AutoSwagger\Analysis\Options;

class OpenApiGenerator
{
    private RouteAnalyzer $routeAnalyzer;

    private array $config;

    public function __construct(array $config = [])
    {
        $defaults = $this->getDefaultConfig();
        $this->config = array_replace($defaults, $config);
        $this->config['security'] = array_replace_recursive($defaults['security'], $config['security'] ?? []);
        $this->routeAnalyzer = new RouteAnalyzer($this->config['analysis']);
    }

    public function getDiagnostics(): array
    {
        return $this->routeAnalyzer->getDiagnostics();
    }

    public function generate(): array
    {
        // Request/resource rules may depend on route context and source files can change.
        // Keep registry and diagnostics scoped to a single document generation.
        $this->routeAnalyzer = new RouteAnalyzer($this->config['analysis']);
        $routes = $this->routeAnalyzer->analyzeRoutes();
        $paths = [];
        $tags = [];
        $operationIds = [];
        foreach ($routes as $route) {
            $id = $route['operationId'] ?? preg_replace('/[^a-zA-Z0-9_]/', '_', $route['operationName'])
                .'_'.$route['method'].'_'.substr(sha1($route['path']), 0, 8);
            if (isset($operationIds[$id])) {
                throw new \RuntimeException("Duplicate operationId: {$id}.");
            }
            $operationIds[$id] = true;
            $operation = [
                'tags' => array_column($route['tags'], 'name'),
                'summary' => $route['summary'],
                'description' => $route['description'],
                'operationId' => $id,
                'responses' => $route['responses'],
            ];
            $parameters = [];
            foreach ($route['parameters'] as $parameter) {
                if (($parameter['in'] ?? null) === 'path') {
                    $parameter['required'] = true;
                }
                $parameters[$parameter['name'].':'.$parameter['in']] = $parameter;
            }
            if ($parameters !== []) {
                $operation['parameters'] = array_values($parameters);
            }
            if (isset($route['requestBody'])) {
                $operation['requestBody'] = $route['requestBody'];
            }
            $security = $this->getSecurityRequirements($route['middleware']);
            if ($security !== []) {
                $operation['security'] = $security;
            }
            if ($route['deprecated']) {
                $operation['deprecated'] = true;
            }
            $paths[$route['path']][$route['method']] = $operation;
            foreach ($route['tags'] as $tag) {
                $tags[$tag['name']] = $tag;
            }
        }
        ksort($tags);
        $info = array_intersect_key($this->config, array_flip(['title', 'description', 'version', 'contact', 'license']));
        foreach (['contact', 'license'] as $field) {
            if (empty($info[$field])) {
                unset($info[$field]);
            }
        }
        $spec = [
            'openapi' => '3.0.0',
            'info' => $info,
            'servers' => $this->config['servers'],
            'paths' => $paths === [] ? new \stdClass : $paths,
            'tags' => array_values($tags),
        ];
        $schemes = $this->generateSecuritySchemes();
        if ($schemes !== []) {
            $spec['components'] = ['securitySchemes' => $schemes];
        }
        $schemas = $this->routeAnalyzer->getSchemas();
        if ($schemas !== []) {
            $spec['components']['schemas'] = $schemas;
        }

        return $spec;
    }

    private function getSecurityRequirements(array $middleware): array
    {
        $auth = false;
        $apiKey = false;
        foreach ($middleware as $name) {
            if (! is_string($name)) {
                continue;
            }
            $alias = explode(':', $name, 2)[0];
            $auth = $auth || $alias === 'auth' || $alias === 'Illuminate\\Auth\\Middleware\\Authenticate';
            $apiKey = $apiKey || $alias === 'api_key';
        }
        $requirements = [];
        if ($auth) {
            if ($this->config['security']['bearer']['enabled']) {
                $requirements[] = ['bearerAuth' => []];
            }
            if ($this->config['security']['oauth2']['enabled']) {
                $requirements[] = ['oauth2' => $this->config['security']['oauth2']['scopes']];
            }
        }
        if ($apiKey && $this->config['security']['apiKey']['enabled']) {
            if ($requirements === []) {
                $requirements[] = ['apiKey' => []];
            } else {
                foreach ($requirements as &$requirement) {
                    $requirement['apiKey'] = [];
                }
            }
        }

        return $requirements;
    }

    private function generateSecuritySchemes(): array
    {
        $schemes = [];
        if ($this->config['security']['bearer']['enabled']) {
            $schemes['bearerAuth'] = ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'];
        }
        if ($this->config['security']['oauth2']['enabled']) {
            $flows = $this->config['security']['oauth2']['flows'];
            foreach ($flows as &$flow) {
                $flow['scopes'] = (object) ($flow['scopes'] ?? []);
            }
            $schemes['oauth2'] = ['type' => 'oauth2', 'flows' => $flows];
        }
        if ($this->config['security']['apiKey']['enabled']) {
            $schemes['apiKey'] = [
                'type' => 'apiKey',
                'in' => $this->config['security']['apiKey']['in'],
                'name' => $this->config['security']['apiKey']['name'],
            ];
        }

        return $schemes;
    }

    private function getDefaultConfig(): array
    {
        return [
            'title' => 'API Documentation',
            'description' => 'API Documentation generated by AutoSwagger',
            'version' => '1.0.0',
            'contact' => [],
            'license' => [],
            'servers' => [['url' => '/', 'description' => 'API Server']],
            'analysis' => Options::DEFAULTS,
            'security' => [
                'bearer' => ['enabled' => true],
                'oauth2' => [
                    'enabled' => false,
                    'flows' => ['password' => ['tokenUrl' => '/oauth/token', 'scopes' => new \stdClass]],
                    'scopes' => [],
                ],
                'apiKey' => ['enabled' => false, 'in' => 'header', 'name' => 'X-API-Key'],
            ],
        ];
    }
}
