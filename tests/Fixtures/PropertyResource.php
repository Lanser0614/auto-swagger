<?php

namespace Tests\Fixtures;

use AutoSwagger\Attributes\ApiProperty;
use AutoSwagger\Attributes\ApiSwaggerResource;

#[ApiSwaggerResource('PropertyResource')]
class PropertyResource
{
    #[ApiProperty(type: 'array', nullable: true, isCollection: true, items: ['type' => 'integer'], required: true)]
    public array $ids;

    #[ApiProperty(type: 'string', format: 'email')]
    public function email() {}
}
