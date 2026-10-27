<?php

namespace AutoSwagger\Analysis;

use AutoSwagger\Attributes\ApiSwaggerResource;
use ReflectionClass;

final class SchemaRegistry
{
    private array $names = [];

    private array $owners = [];

    private array $schemas = [];

    private array $building = [];

    private bool $referenced = false;

    public function __construct(private readonly bool $strictNames = false) {}

    public function name(string $class): string
    {
        if (isset($this->names[$class])) {
            return $this->names[$class];
        }
        $reflection = new ReflectionClass($class);
        $metadata = ($reflection->getAttributes(ApiSwaggerResource::class)[0] ?? null)?->newInstance();
        $name = $metadata?->name ?? $reflection->getShortName().'_'.substr(sha1($class), 0, 8);
        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            $name = (trim(preg_replace('/[^a-zA-Z0-9._-]/', '_', $name), '_') ?: 'Schema').'_'.substr(sha1($name), 0, 8);
        }
        if (isset($this->owners[$name]) && $this->owners[$name] !== $class) {
            if ($this->strictNames) {
                throw new \RuntimeException("Schema name {$name} is used by both {$this->owners[$name]} and {$class}.");
            }
            // Inline schemas previously allowed duplicate display names. Give their
            // internal cache entries distinct names without changing legacy output.
            $name .= '_'.substr(sha1($class), 0, 8);
        }
        $this->owners[$name] = $class;

        return $this->names[$class] = $name;
    }

    public function build(string $class, \Closure $builder, bool $reference = false): array
    {
        $name = $this->name($class);
        if (isset($this->building[$class])) {
            return $this->reference($class);
        }
        if (! isset($this->schemas[$name])) {
            $this->building[$class] = true;
            try {
                $this->schemas[$name] = $builder();
            } finally {
                unset($this->building[$class]);
            }
        }

        return $reference ? $this->reference($class) : $this->schemas[$name];
    }

    public function reference(string $class): array
    {
        $this->referenced = true;

        return ['$ref' => '#/components/schemas/'.$this->name($class)];
    }

    public function schemas(): array
    {
        if (! $this->referenced) {
            return [];
        }
        $schemas = $this->schemas;
        ksort($schemas);

        return $schemas;
    }
}
