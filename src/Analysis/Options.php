<?php

namespace AutoSwagger\Analysis;

final class Options
{
    public const DEFAULTS = [
        'resource_inference' => 'fallback',
        'use_references' => false,
        'response_wrapping' => 'legacy',
        'unknown_types' => 'legacy',
        'diagnostics' => true,
        'rule_extensions' => [],
        'request_mode' => 'runtime',
    ];

    public readonly array $values;

    public function __construct(array $values = [])
    {
        $this->values = array_replace(self::DEFAULTS, $values);
        foreach (['resource_inference' => ['fallback', 'merge', 'disabled'], 'response_wrapping' => ['legacy', 'laravel'], 'unknown_types' => ['legacy', 'permissive', 'strict'], 'request_mode' => ['runtime', 'static']] as $name => $allowed) {
            if (! in_array($this->values[$name], $allowed, true)) {
                throw new \InvalidArgumentException("Invalid analysis option {$name}.");
            }
        }
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }
}
