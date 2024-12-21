<?php
declare(strict_types=1);

namespace AutoSwagger\Attributes;

use Attribute;

#[Attribute]
class ApiSwaggerQuery
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $description = null,
        public readonly ?bool $required = false
    ) {
    }
}