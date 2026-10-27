<?php

namespace Example;

use AutoSwagger\Attributes\ApiSwagger;
use AutoSwagger\Attributes\ApiSwaggerResponse;

class UserController
{
    #[ApiSwagger(summary: 'Get user by ID', tag: 'Users')]
    #[ApiSwaggerResponse(resource: ['id' => 'integer', 'name' => 'string', 'email' => 'email'])]
    #[ApiSwaggerResponse(status: 404, description: 'User not found')]
    public function getUser(int $id): array
    {
        return ['id' => $id, 'name' => 'Example user', 'email' => 'user@example.test'];
    }
}
