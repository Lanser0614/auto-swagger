<?php

namespace Tests\Feature;

use AutoSwagger\Analyzer\OpenApiGenerator;
use cebe\openapi\Reader;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiValidationTest extends TestCase
{
    public function test_generated_document_passes_openapi_validation(): void
    {
        $spec = (new OpenApiGenerator(['security' => ['oauth2' => ['enabled' => true], 'apiKey' => ['enabled' => true]]]))->generate();
        $document = Reader::readFromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        $this->assertTrue($document->validate(), json_encode($document->getErrors()));
    }

    public function test_empty_document_is_valid_in_json_and_yaml(): void
    {
        Route::setRoutes(new RouteCollection);
        $spec = (new OpenApiGenerator(['security' => ['bearer' => ['enabled' => false]]]))->generate();
        $json = json_encode($spec, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"paths":{}', $json);
        $this->assertArrayNotHasKey('components', $spec);
        $document = Reader::readFromJson($json);
        $this->assertTrue($document->validate(), json_encode($document->getErrors()));
        $yaml = Yaml::dump($spec, 30, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $document = Reader::readFromYaml($yaml);
        $this->assertTrue($document->validate(), json_encode($document->getErrors()));
    }
}
