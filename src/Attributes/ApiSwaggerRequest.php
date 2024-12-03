<?php

namespace AutoSwagger\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_METHOD)]
class ApiSwaggerRequest
{
    public function __construct(
        public readonly ?string $request = null,
        public readonly ?string $description = null,
        public readonly ?string $mediaType = 'application/json',
        public readonly ?bool $required = true
    ) {
    }
}
