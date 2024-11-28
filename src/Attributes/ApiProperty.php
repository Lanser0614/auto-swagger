<?php

namespace AutoSwagger\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class ApiProperty
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $description = null,
        public readonly ?string $format = null,
        public readonly mixed $example = null,
        public readonly ?array $enum = null,
        public readonly bool $required = false,
        public readonly bool $nullable = false,
        public readonly ?string $ref = null,
        public readonly bool $isCollection = false,
        public readonly ?array $items = null
    ) {
    }
}
