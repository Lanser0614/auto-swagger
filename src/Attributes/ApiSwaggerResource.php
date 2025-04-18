<?php

namespace AutoSwagger\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
class ApiSwaggerResource
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly array $properties = []
    ) {
    }
}
