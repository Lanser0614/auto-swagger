<?php

namespace Tests\Analyzer;

use PHPUnit\Framework\TestCase;
use AutoSwagger\Analyzer\RouteAnalyzer;
use App\Http\Controllers\ItemController;

class RouteAnalyzerTest extends TestCase
{
    private RouteAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new RouteAnalyzer();
    }

    public function testAnalyzeItemController()
    {
        $routes = $this->analyzer->analyzeController(ItemController::class);

        $this->assertNotEmpty($routes);
        $this->assertCount(1, $routes); // One route for show method

        $route = $routes[0];

        // Test controller metadata
        $this->assertArrayHasKey('tags', $route);
        $this->assertEquals('Items', $route['tags'][0]['name']);
        $this->assertEquals('Item management endpoints', $route['tags'][0]['description']);

        // Test route metadata
        $this->assertEquals('GET', $route['method']);
        $this->assertEquals('/api/items/{id}', $route['path']);
        $this->assertEquals('Get item by ID', $route['summary']);
        $this->assertEquals('show', $route['operationId']);
        $this->assertFalse($route['deprecated']);

        // Test parameters
        $this->assertNotEmpty($route['parameters']);
        $param = $route['parameters'][0];
        $this->assertEquals('id', $param['name']);
        $this->assertEquals('path', $param['in']);
        $this->assertTrue($param['required']);
        $this->assertEquals('integer', $param['schema']['type']);

        // Test responses
        $this->assertArrayHasKey('responses', $route);
        $this->assertArrayHasKey(200, $route['responses']);
        $this->assertArrayHasKey(404, $route['responses']);

        // Test 200 response
        $ok = $route['responses'][200];
        $this->assertEquals('Item found', $ok['description']);
        $this->assertArrayHasKey('content', $ok);
        $this->assertArrayHasKey('application/json', $ok['content']);
        $this->assertEquals('#/components/schemas/Item', $ok['content']['application/json']['schema']['$ref']);

        // Test 404 response
        $notFound = $route['responses'][404];
        $this->assertEquals('Item not found', $notFound['description']);
        $this->assertArrayHasKey('content', $notFound);
        $this->assertArrayHasKey('application/json', $notFound['content']);
        $this->assertEquals('string', $notFound['content']['application/json']['schema']['properties']['message']['type']);
    }
}
