<?php

namespace AutoSwagger\Laravel\Console;

use AutoSwagger\Analyzer\OpenApiGenerator;
use AutoSwagger\Laravel\DocumentationPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class GenerateSwaggerCommand extends Command
{
    protected $signature = 'swagger:generate
                          {--format=json : Output format (json or yaml)}
                          {--output= : Output file path, relative to the application root or absolute}
                          {--title= : API title}
                          {--description= : API description}
                          {--api-version= : API version}
                          {--base-url= : Base URL for API server}
                          {--bearer : Enable Bearer token authentication}
                          {--oauth2 : Enable OAuth2 authentication}
                          {--api-key : Enable API key authentication}
                          {--view-docs : Display the generated specification}
                          {--config= : Path to a PHP configuration file returning an array}';

    protected $description = 'Generate OpenAPI documentation from your Laravel application';

    public function handle(): int
    {
        $this->info('Generating OpenAPI documentation...');
        try {
            $format = strtolower($this->option('format'));
            if (! in_array($format, ['json', 'yaml', 'yml'], true)) {
                throw new \InvalidArgumentException("Unsupported format: {$format}");
            }
            $settings = config('auto-swagger');
            if ($path = $this->option('config')) {
                $path = DocumentationPath::isAbsolute($path) ? $path : base_path($path);
                if (! File::isFile($path)) {
                    throw new \InvalidArgumentException("Configuration file does not exist: {$path}");
                }
                $overrides = require $path;
                if (! is_array($overrides)) {
                    throw new \InvalidArgumentException('The configuration file must return an array.');
                }
                $settings = array_replace_recursive($settings, $overrides);
                // Replace lists instead of merging them by numeric index.
                foreach (['servers', 'contact', 'license'] as $key) {
                    if (array_key_exists($key, $overrides)) {
                        $settings[$key] = $overrides[$key];
                    }
                }
            }
            $config = [
                'title' => $this->option('title') ?? $settings['title'],
                'description' => $this->option('description') ?? $settings['description'],
                'version' => $this->option('api-version') ?? $settings['version'],
                'contact' => $settings['contact'] ?? [],
                'license' => $settings['license'] ?? [],
                'servers' => $settings['servers'] ?? [['url' => rtrim(config('app.url'), '/'), 'description' => 'API Server']],
                'security' => $settings['security'] ?? $settings['auth'],
                'analysis' => $settings['analysis'] ?? [],
            ];
            if ($url = $this->option('base-url')) {
                $config['servers'] = [['url' => rtrim($url, '/'), 'description' => 'API Server']];
            }
            foreach (['bearer' => 'bearer', 'oauth2' => 'oauth2', 'api-key' => 'apiKey'] as $option => $scheme) {
                if ($this->option($option)) {
                    $config['security'][$scheme]['enabled'] = true;
                }
            }
            $generator = new OpenApiGenerator($config);
            $spec = $generator->generate();
            if ($config['analysis']['diagnostics'] ?? true) {
                foreach ($generator->getDiagnostics() as $diagnostic) {
                    $location = $diagnostic['file'] ? ' ('.$diagnostic['file'].':'.$diagnostic['line'].')' : '';
                    $this->warn($diagnostic['context'].': '.$diagnostic['message'].$location);
                }
            }
            $output = $format === 'json'
                ? json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : Yaml::dump($spec, 30, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
            $path = $this->option('output');
            if ($path !== null) {
                $path = DocumentationPath::isAbsolute($path) ? $path : base_path($path);
            } else {
                $path = DocumentationPath::resolve($settings['directory'], $settings['output'][$format === 'yml' ? 'yaml' : $format]);
            }
            File::ensureDirectoryExists(dirname($path), 0755, true);
            // An unsuccessful generation must not replace the previous valid document.
            File::replace($path, $output, 0666 & ~umask());
            if ($this->option('view-docs')) {
                $this->line($output);
            }
            $this->info("Documentation saved to: {$path}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error generating documentation: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
