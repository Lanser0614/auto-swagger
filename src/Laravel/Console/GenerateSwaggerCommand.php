<?php

namespace AutoSwagger\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use AutoSwagger\Generator\OpenApiGenerator;
use Symfony\Component\Finder\Finder;

class GenerateSwaggerCommand extends Command
{
    protected $signature = 'swagger:generate 
                          {--format=json : Output format (json or yaml)}';

    protected $description = 'Generate Swagger/OpenAPI documentation';

    public function handle(OpenApiGenerator $generator): int
    {
        $this->info('Generating Swagger documentation...');

        // Scan controllers
        $controllers = $this->scanControllers();
        
        foreach ($controllers as $controller) {
            $generator->addController($controller);
        }

        // Generate specification
        $specification = $generator->generate();

        // Ensure output directory exists
        $outputPath = config('auto-swagger.output.json');
        $directory = dirname($outputPath);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // Save specification
        if ($this->option('format') === 'yaml') {
            $outputPath = config('auto-swagger.output.yaml');
            File::put($outputPath, yaml_emit($specification));
        } else {
            File::put($outputPath, json_encode($specification, JSON_PRETTY_PRINT));
        }

        $this->info('Documentation generated successfully!');
        return Command::SUCCESS;
    }

    private function scanControllers(): array
    {
        $controllers = [];
        $paths = config('auto-swagger.controllers', []);

        foreach ($paths as $path) {
            if (!File::exists($path)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($path)->name('*Controller.php');

            foreach ($finder as $file) {
                $className = $this->getClassNameFromFile($file->getRealPath());
                if ($className) {
                    $controllers[] = $className;
                }
            }
        }

        return $controllers;
    }

    private function getClassNameFromFile(string $path): ?string
    {
        $content = File::get($path);
        if (preg_match('/namespace\s+(.+?);/s', $content, $matches) &&
            preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            return $matches[1] . '\\' . $classMatches[1];
        }
        return null;
    }
}
