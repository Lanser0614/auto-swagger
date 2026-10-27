<?php

namespace Tests\Fixtures\Parsing;

use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Http\Resources\Json\JsonResource;

#[ApiSwaggerResource('Category')]
class CategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['name' => 'Category', 'children' => static::collection($this->whenLoaded('children'))];
    }
}
