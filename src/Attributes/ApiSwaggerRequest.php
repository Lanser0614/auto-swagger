<?php

namespace AutoSwagger\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
class ApiSwaggerRequest
{
    public function __construct(
        public readonly ?string $request = null,
        public readonly ?string $description = null,
        public readonly ?string $mediaType = null,
        public readonly ?bool $required = true,
        public readonly ?array $schema = null,
    ) {}
}
