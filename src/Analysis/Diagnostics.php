<?php

namespace AutoSwagger\Analysis;

final class Diagnostics
{
    private array $messages = [];

    public function __construct(private readonly Options $options = new Options) {}

    public function report(string $message, string $context = '', ?string $file = null, ?int $line = null): void
    {
        $entry = ['message' => $message, 'context' => $context, 'file' => $file, 'line' => $line];
        $this->messages[json_encode($entry, JSON_THROW_ON_ERROR)] = $entry;
    }

    public function unknown(string $context, ?string $file = null, ?int $line = null): array
    {
        $message = 'Unable to infer a type; add ApiProperty or an explicit schema.';
        $this->report($message, $context, $file, $line);
        if ($this->options->get('unknown_types') === 'strict') {
            throw new \RuntimeException($context.': '.$message);
        }

        return $this->options->get('unknown_types') === 'legacy'
            ? ['type' => 'string'] : ['description' => 'Type could not be inferred.'];
    }

    public function all(): array
    {
        return array_values($this->messages);
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
