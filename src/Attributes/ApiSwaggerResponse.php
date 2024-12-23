<?php

namespace AutoSwagger\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class ApiSwaggerResponse
{
    public function __construct(
        public readonly int $status = 200,
        public readonly array|string $resource = [],
        public readonly ?string $description = null,
        public readonly ?string $mediaType = 'application/json',
        public readonly ?bool $isPagination = false
    ) {
    }
}
