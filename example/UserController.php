<?php

namespace Example;

use AutoSwagger\Attributes\ApiOperation;
use AutoSwagger\Attributes\ApiProperty;

class UserController
{
    #[ApiOperation(
        summary: 'Get user by ID',
        description: 'Retrieves a user by their unique identifier',
        tags: ['Users'],
        parameters: [
            [
                'name' => 'id',
                'in' => 'path',
                'required' => true,
                'description' => 'User ID',
                'type' => 'integer'
            ]
        ],
        responses: [
            '200' => [
                'description' => 'Successful operation',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'email' => ['type' => 'string']
                            ]
                        ]
                    ]
                ]
            ],
            '404' => [
                'description' => 'User not found'
            ]
        ]
    )]
    public function getUser(int $id)
    {
        // Implementation
    }

    #[ApiOperation(
        summary: 'Create new user',
        description: 'Creates a new user in the system',
        tags: ['Users'],
        responses: [
            '201' => [
                'description' => 'User created successfully'
            ],
            '400' => [
                'description' => 'Invalid input'
            ]
        ]
    )]
    public function createUser(
        #[ApiProperty(description: 'User name', required: true)] string $name,
        #[ApiProperty(description: 'User email', required: true, format: 'email')] string $email
    ) {
        // Implementation
    }
}
