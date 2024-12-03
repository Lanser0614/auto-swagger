<?php

namespace AutoSwagger\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class ApiResponseException
{
    public function __construct(
        public readonly int $status = 200,
        public readonly ?string $resource = null,
        public readonly ?string $description = null,
    ) {
    }
}
