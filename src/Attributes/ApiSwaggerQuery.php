<?php

declare(strict_types=1);

namespace AutoSwagger\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiSwaggerQuery
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $description = null,
        public readonly ?bool $required = false,
        public readonly ?bool $isId = false,
        public readonly string $type = 'string',
    ) {}
}
