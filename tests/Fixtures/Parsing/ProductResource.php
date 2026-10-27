<?php

namespace Tests\Fixtures\Parsing;

use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Http\Resources\Json\JsonResource;

#[ApiSwaggerResource('Product', model: OrderModel::class)]
class ProductResource extends JsonResource
{
    public function __construct($resource)
    {
        throw new \LogicException('Resource construction is forbidden during analysis.');
    }

    public function toArray($request): array
    {
        throw new \LogicException('Resource execution is forbidden during analysis.');

        return ['id' => $this->id, 'title' => $this->title, 'price' => $this->price, 'quantity' => $this->quantity];
    }
}
