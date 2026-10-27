<?php

namespace Tests\Fixtures\Parsing;

use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Http\Resources\Json\JsonResource;

#[ApiSwaggerResource('Customer')]
class CustomerResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['name' => 'Customer', 'verified' => true];
    }
}
