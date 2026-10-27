<?php

namespace Tests\Fixtures\Parsing;

use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Http\Resources\Json\JsonResource;

#[ApiSwaggerResource('Plain')]
class PlainResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => 1, 'name' => 'Example'];
    }
}
