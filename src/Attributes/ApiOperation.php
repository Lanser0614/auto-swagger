<?php

namespace AutoSwagger\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
class ApiOperation
{
    public function __construct(
        public readonly string $summary,
        public readonly ?string $description = null,
        public readonly ?array $tags = null,
        public readonly ?string $operationId = null,
        public readonly ?array $parameters = null,
        public readonly ?array $responses = null,
        public readonly ?bool $deprecated = false
    ) {
    }
}
