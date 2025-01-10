<?php

namespace App\Http\Resources;

use AutoSwagger\Attributes\ApiProperty;
use AutoSwagger\Attributes\ApiResource;
use Illuminate\Http\Resources\Json\JsonResource;

#[ApiResource(
    name: 'User',
    description: 'A user resource'
)]
class UserResource extends JsonResource
{
    #[ApiProperty(type: 'integer', description: 'The user ID')]
    private $id;

    #[ApiProperty(type: 'string', description: 'The user\'s full name')]
    private $name;

    #[ApiProperty(type: 'string', format: 'email', description: 'The user\'s email address')]
    private $email;

    #[ApiProperty(type: 'boolean', description: 'Whether the user is active', example: true)]
    private $is_active;

    #[ApiProperty(
        type: 'array',
        isCollection: true,
        items: [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string']
            ]
        ],
        description: 'User\'s roles'
    )]
    private $roles;

    #[ApiProperty(type: 'string', format: 'date-time', description: 'When the user was created')]
    private $created_at;

    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'roles' => $this->roles->map(fn($role) => [
                'id' => $role->id,
                'name' => $role->name
            ]),
            'created_at' => $this->created_at->toISOString()
        ];
    }
}
