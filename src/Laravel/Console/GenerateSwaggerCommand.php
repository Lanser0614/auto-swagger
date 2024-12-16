<?php

namespace AutoSwagger\Laravel\Console;

use Illuminate\Console\Command;
use AutoSwagger\Generator\OpenApiGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

class GenerateSwaggerCommand extends Command
{
    protected $signature = 'swagger:generate
                          {--format=json : Output format (json or yaml)}
                          {--output= : Output file path}
                          {--title= : API title}
                          {--description= : API description}
                          {--api-version= : API version}
                          {--base-url= : Base URL for API server}
                          {--bearer : Enable Bearer token authentication}
                          {--oauth2 : Enable OAuth2 authentication}
                          {--api-key : Enable API key authentication}
                          {--config= : Path to configuration file}';

    protected $description = 'Generate OpenAPI documentation from your Laravel application';

    public function handle(): int
    {
        $this->info('Generating OpenAPI documentation...');

        try {
            // Get configuration
            $config = $this->getConfiguration();


            // Create generator
            $generator = new OpenApiGenerator($config);

            // Generate specification
            $spec = $generator->generate();

            // Convert to desired format
            $output = $this->formatOutput($spec);

            // Save or display output
            $this->handleOutput($output);

            $this->info('OpenAPI documentation generated successfully!');
            return 0;
        } catch (\Throwable $e) {
            $this->error('Error generating documentation: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }

    private function getConfiguration(): array
    {
        // Start with default configuration
        $config = [
            'title' => config('auto-swagger.title'),
            'description' => config('auto-swagger.description'),
            'version' => config('auto-swagger.version'),
            'servers' => [],
            'security' => config('auto-swagger.auth'),
        ];


        // Configure base URL
        $baseUrl = config('app.url');
        $config['servers'][] = [
            'url' => rtrim($baseUrl, '/'),
            'description' => 'API Server'
        ];

        // Configure security
        if ($config['security']['bearer']['enabled']) {
            $config['security']['bearer']['enabled'] = true;
        }

        if ($config['security']['oauth2']['enabled']) {
            $config['security']['oauth2'] = [
                'enabled' => true,
                'flows' => [
                    'password' => [
                        'tokenUrl' => '/oauth/token',
                        'scopes' => []
                    ]
                ],
                'scopes' => []
            ];
        }

        if ($config['security']['apiKey']['enabled']) {
            $config['security']['apiKey'] = [
                'enabled' => true,
                'in' => 'header',
                'name' => 'X-API-Key'
            ];
        }

        return $config;
    }


    private function formatOutput(array $spec): string
    {
        $format = strtolower($this->option('format'));

        return match ($format) {
            'yaml', 'yml' => Yaml::dump($spec, 10, 2),
            'json' => json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}")
        };
    }

    private function handleOutput(string $output): void
    {
        $this->line($output);

        $format = strtolower($this->option('format') ?? 'json');

        $fileName = match ($format) {
            'yaml', 'yml' => config('auto-swagger.output.yaml'),
            'json' => config('auto-swagger.output.json'),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}")
        };

        $directory = config('auto-swagger.directory');

        File::makeDirectory(public_path($directory), 0777, true, true);
        File::put(public_path($directory . '/' . $fileName), $output);

        $this->info("Documentation saved to: {$directory}");
    }

    private function createExampleConfig(): void
    {
        $stub = File::get(__DIR__ . '/stubs/swagger-config.stub');
        $configPath = config_path('swagger.php');

        if (File::exists($configPath)) {
            if (!$this->confirm('Configuration file already exists. Do you want to overwrite it?')) {
                return;
            }
        }

        File::put($configPath, $stub);
        $this->info('Example configuration file created at: ' . $configPath);
    }
}
