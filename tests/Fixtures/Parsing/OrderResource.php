<?php

namespace Tests\Fixtures\Parsing;

use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Parsing\CustomerResource as Buyer;
use Tests\Fixtures\Parsing\ProductResource as Line;

#[ApiSwaggerResource('Order', model: OrderModel::class)]
class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        $customer = Buyer::make($this->customer);

        return [
            'id' => $this->id,
            'active' => $this->active,
            'customer' => $customer,
            'items' => Line::collection($this->whenLoaded('items')),
            'note' => $this->whenNotNull($this->note),
            'nickname' => $this->when($request->user(), 'Nick', null),
            $this->mergeWhen($request->user(), ['secret' => 'Value', 'rank' => 1]),
            'count' => $this->whenCounted('items'),
        ];
    }
}
