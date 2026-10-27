<?php

namespace App\Http\Resources;

use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Http\Resources\Json\JsonResource;

#[ApiSwaggerResource(name: 'User', properties: [
    'id' => 'integer',
    'name' => 'string',
    'email' => 'email',
    'is_active' => 'boolean',
    'roles' => ['type' => 'array', 'items' => [
        'type' => 'object',
        'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
    ]],
    'created_at' => 'date-time',
])]
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'roles' => $this->roles->map(fn ($role) => ['id' => $role->id, 'name' => $role->name]),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
