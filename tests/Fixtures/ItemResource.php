<?php

namespace Tests\Fixtures;

use AutoSwagger\Attributes\ApiProperty;
use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Http\Resources\Json\JsonResource;

#[ApiSwaggerResource(name: 'Item', properties: ['id' => 'integer', 'price' => 'number', 'details' => ['active' => 'bool']])]
class ItemResource extends JsonResource
{
    #[ApiProperty(type: 'string', description: 'Display name', required: true, example: 'Pizza')]
    public $name;
}
