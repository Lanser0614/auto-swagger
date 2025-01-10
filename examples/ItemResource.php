<?php

namespace App\Http\Resources;

use AutoSwagger\Attributes\ApiProperty;
use AutoSwagger\Attributes\ApiResource;
use Illuminate\Http\Resources\Json\JsonResource;

#[ApiResource(
    name: 'Item',
    description: 'An item resource'
)]
class ItemResource extends JsonResource
{
    #[ApiProperty(type: 'integer', description: 'The item ID')]
    private $id;

    #[ApiProperty(type: 'string', description: 'The item name')]
    private $name;

    #[ApiProperty(type: 'string', description: 'The item description')]
    private $description;

    #[ApiProperty(type: 'number', format: 'float', description: 'The item price')]
    private $price;

    #[ApiProperty(type: 'integer', description: 'Available quantity')]
    private $quantity;

    #[ApiProperty(type: 'string', format: 'date-time', description: 'When the item was created')]
    private $created_at;

    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'created_at' => $this->created_at->toISOString()
        ];
    }
}
