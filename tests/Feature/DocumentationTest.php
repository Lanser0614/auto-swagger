<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Fixtures\BrokenController;
use Tests\TestCase;

class DocumentationTest extends TestCase
{
    public function test_generated_json_is_served_from_the_same_public_path(): void
    {
        $this->artisan('swagger:generate')->assertExitCode(0);
        $this->assertFileExists(public_path('swagger/openapi.json'));
        $this->get('/api/documentation/json')->assertOk()->assertJsonPath('openapi', '3.0.0')->assertJsonPath('info.title', config('auto-swagger.title'));
    }

    public function test_yaml_and_json_are_equivalent_and_yaml_is_served(): void
    {
        $this->artisan('swagger:generate')->assertExitCode(0);
        $this->artisan('swagger:generate', ['--format' => 'yml'])->assertExitCode(0);
        $json = json_decode(File::get(public_path('swagger/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
        $yaml = Yaml::parse(File::get(public_path('swagger/openapi.yaml')));
        $this->assertSame($json, $yaml);
        $this->get('/api/documentation/yaml')->assertOk()->assertHeader('Content-Type', 'application/yaml');
    }

    public function test_ui_uses_package_view_and_configured_title(): void
    {
        config(['auto-swagger.title' => 'Example Docs']);
        $this->get('/api/documentation')->assertOk()->assertSee('Example Docs')->assertSee('swagger-ui-bundle.js');
        config(['auto-swagger.ui.enabled' => false]);
        $this->get('/api/documentation')->assertNotFound();
    }

    public function test_missing_documentation_returns_404(): void
    {
        $this->get('/api/documentation/json')->assertNotFound();
        $this->get('/api/documentation/yaml')->assertNotFound();
    }

    public function test_generate_always_builds_the_requested_format(): void
    {
        config(['auto-swagger.generate_always' => true]);
        $this->get('/api/documentation/json')->assertOk();
        $this->get('/api/documentation/yaml')->assertOk();
        $this->assertFileExists(public_path('swagger/openapi.yaml'));
    }

    public function test_cli_overrides_and_custom_output_are_applied(): void
    {
        $path = $this->temporaryDirectory.'/custom/spec.json';
        $this->artisan('swagger:generate', [
            '--title' => 'CLI API', '--description' => 'CLI description', '--api-version' => '2.0',
            '--base-url' => 'https://api.example.test/v2/', '--output' => $path, '--view-docs' => true,
        ])->assertExitCode(0);
        $spec = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('CLI API', $spec['info']['title']);
        $this->assertSame('CLI description', $spec['info']['description']);
        $this->assertSame('2.0', $spec['info']['version']);
        $this->assertSame('https://api.example.test/v2', $spec['servers'][0]['url']);
    }

    public function test_php_configuration_and_partial_auth_configuration_are_supported(): void
    {
        File::ensureDirectoryExists($this->temporaryDirectory);
        $file = $this->temporaryDirectory.'/config.php';
        File::put($file, "<?php return ['title' => 'File API', 'auth' => ['bearer' => ['enabled' => false]], 'contact' => ['name' => 'Maintainer']];");
        $this->artisan('swagger:generate', ['--config' => $file, '--bearer' => true, '--oauth2' => true])->assertExitCode(0);
        $spec = json_decode(File::get(public_path('swagger/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('File API', $spec['info']['title']);
        $this->assertSame('Maintainer', $spec['info']['contact']['name']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
        $this->assertArrayHasKey('oauth2', $spec['components']['securitySchemes']);
    }

    public function test_absolute_documentation_directory_is_supported(): void
    {
        config(['auto-swagger.directory' => $this->temporaryDirectory.'/docs']);
        $this->artisan('swagger:generate')->assertExitCode(0);
        $this->get('/api/documentation/json')->assertOk();
    }

    public function test_empty_documentation_directory_uses_the_public_root(): void
    {
        config(['auto-swagger.directory' => '']);
        $this->artisan('swagger:generate')->assertExitCode(0);
        $this->assertFileExists(public_path('openapi.json'));
        $this->get('/api/documentation/json')->assertOk();
    }

    public function test_invalid_options_and_invalid_configuration_fail(): void
    {
        $this->assertSame(1, Artisan::call('swagger:generate', ['--format' => 'xml']));
        $this->assertSame(1, Artisan::call('swagger:generate', ['--config' => 'does-not-exist.php']));
        $this->assertFileDoesNotExist(public_path('swagger/openapi.json'));
    }

    public function test_failed_generation_preserves_the_last_document_and_returns_failure(): void
    {
        $this->artisan('swagger:generate')->assertExitCode(0);
        $before = File::get(public_path('swagger/openapi.json'));
        Route::get('api/broken', [BrokenController::class, 'show']);
        $this->assertSame(1, Artisan::call('swagger:generate'));
        $this->assertStringContainsString('api/broken', Artisan::output());
        $this->assertSame($before, File::get(public_path('swagger/openapi.json')));
        config(['auto-swagger.generate_always' => true]);
        $this->get('/api/documentation/json')->assertStatus(500);
    }

    public function test_invalid_configuration_contents_return_failure(): void
    {
        File::ensureDirectoryExists($this->temporaryDirectory);
        $path = $this->temporaryDirectory.'/invalid.php';
        File::put($path, '<?php return false;');
        $this->assertSame(1, Artisan::call('swagger:generate', ['--config' => $path]));
        $this->assertStringContainsString('must return an array', Artisan::output());
    }

    public function test_configuration_can_be_cached(): void
    {
        $this->artisan('config:cache')->assertExitCode(0);
        $this->artisan('config:clear')->assertExitCode(0);
    }
}
