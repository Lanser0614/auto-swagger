# AutoSwagger PHP

AutoSwagger is a PHP package that automatically generates OpenAPI/Swagger documentation from PHP attributes, similar to NestJS/Swagger.

## Installation

```bash
composer require auto-swagger/php-swagger-generator
```

## Usage

### 1. Add Attributes to Your Controllers

```php
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
        ]
    )]
    public function getUser(int $id)
    {
        // Implementation
    }

    #[ApiOperation(
        summary: 'Create new user',
        description: 'Creates a new user in the system',
        tags: ['Users']
    )]
    public function createUser(
        #[ApiProperty(description: 'User name', required: true)] string $name,
        #[ApiProperty(description: 'User email', required: true, format: 'email')] string $email
    ) {
        // Implementation
    }
}
```

### 2. Generate OpenAPI Documentation

```php
use AutoSwagger\Generator\OpenApiGenerator;

// Create a new instance of the OpenAPI generator
$generator = new OpenApiGenerator(
    title: 'Your API',
    version: '1.0.0',
    description: 'Your API description'
);

// Add controllers to be processed
$generator->addController(UserController::class);

// Generate the OpenAPI specification
$specification = $generator->generate();

// Output the specification as JSON
file_put_contents('openapi.json', json_encode($specification, JSON_PRETTY_PRINT));
```

## Available Attributes

### ApiOperation
Used to document controller methods:
- `summary`: A brief summary of the operation
- `description`: A detailed description of the operation
- `tags`: Array of tags for grouping operations
- `parameters`: Array of operation parameters
- `responses`: Array of possible responses
- `deprecated`: Boolean indicating if the operation is deprecated

### ApiProperty
Used to document model properties and method parameters:
- `description`: Property description
- `type`: Property type
- `format`: Property format (e.g., 'email', 'date-time')
- `required`: Whether the property is required
- `example`: Example value
- `enum`: Array of possible values

## Example
Check the `example` directory for a complete working example.

## License
MIT
# auto-swagger
