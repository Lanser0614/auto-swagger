<?php

namespace App\Http\Resources;

use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Http\Resources\Json\JsonResource;

#[ApiSwaggerResource(name: 'Item', properties: [
    'id' => 'integer',
    'name' => 'string',
    'description' => 'string',
    'price' => 'number',
    'quantity' => 'integer',
    'created_at' => 'date-time',
])]
class ItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
