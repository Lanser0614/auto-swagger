# Laravel Auto Swagger Documentation

Auto Swagger for Laravel is a package that helps you generate Swagger/OpenAPI 1.0 documentation quickly and easily for your Laravel applications.

## Installation

1. Install the package via Composer:
```bash
composer require auto-swagger/php-swagger-generator
```

2. Publish the necessary files:
```bash
# Configuration files
php artisan vendor:publish --tag=auto-swagger-config

# Views (optional)
php artisan vendor:publish --tag=auto-swagger-views

# Assets (optional)
php artisan vendor:publish --tag=auto-swagger-assets
```

## Generating Documentation

To generate the OpenAPI documentation, run:
```bash
php artisan swagger:generate
```

### Output Format Options

The generator supports both JSON and YAML formats:

- Generate JSON (default):
```bash
php artisan swagger:generate --format=json
```

- Generate YAML:
```bash
php artisan swagger:generate --format=yaml
```

When using the default JSON format, the documentation will be accessible at: `http://localhost:8000/api/documentation/json`

## Attributes

### Route Documentation

To include a route in the API documentation, use the `ApiSwagger` attribute:

```php
#[ApiSwagger(summary: 'Store user', tag: 'User')]
```

Properties:
- `summary`: Description of the route
- `tag`: Group identifier for related routes

### Request Documentation

Document request parameters using `ApiSwaggerRequest`:

On the controller method you need use Request class for validation, if you does not do this AutoSwager will not parse RequestBody
```php
#[ApiSwaggerRequest(request: UserCreateRequest::class, description: 'Store user')]
public function store(UserCreateRequest $request): UserPaginatedResource
{
    // some Logic
}
```

### Query Parameters

Use `ApiSwaggerQuery` to define filter parameters for your API endpoints:

```php
#[ApiSwaggerQuery([
name: "name", 
description: "Search by user name",
required: false
])]
```
if you need paste id Of model you need make isId parameter true
```php
#[ApiSwaggerQuery(name: "id", required: true, isId: true)]
```

The format for each query parameter is:
`'parameter_name' => 'type|required/optional|description'`

Supported types:
- string
- integer
- boolean
- date
- array
- float

Example of a complete endpoint with query parameters:

```php
#[ApiSwagger(summary: 'List users', tag: 'User')]
#[ApiSwaggerQuery([
name: "search", 
description: "Search by name or email",
required: false
])]
#[ApiSwaggerQuery([
name: "status", 
description: "Filter by user status",
required: false
])]
#[ApiSwaggerResponse(status: 200, resource: UserResource::class, isPagination: true)]
public function index(Request $request): UserPaginatedResource
{
    $users = $this->userRepository
        ->filter($request->all())
        ->paginate($request->input('perPage', 10));
    
    return new UserPaginatedResource($users);
}
```

### Response Documentation

Document API responses using `ApiSwaggerResponse`. You can specify the response structure in three ways:

1. Using an array:
```php
#[ApiSwaggerResponse(status: 200, resource: [
    'id' => 'integer',
    'name' => 'string',
    'email' => 'string',
])]
```

2. Using an API Resource class:
```php
#[ApiSwaggerResponse(status: 200, resource: ApiResource::class, description: 'User details')]
```

3. Using a Model class:
```php
#[ApiSwaggerResponse(status: 200, resource: Model::class, description: 'User details')]
```
## Resource class

```php
use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Http\Resources\Json\JsonResource;

#[ApiSwaggerResource(name: 'User', properties: [
    'id' => 'integer',
    'name' => 'string',
])]
class ApiResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'name' => $this->name,
            'id' => $this->id
        ];
    }
}
```


## Pagination Support

To implement pagination in your API documentation:

1. Create a paginated resource class that extends `PaginatedResource`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Resources\User;

use AutoSwagger\Laravel\Resources\PaginatedResource;

class UserPaginatedResource extends PaginatedResource
{
    public function initCollection()
    {
        return $this->collection->map(function ($user) {
            return new UserResource($user);
        });
    }
}
```

2. Set `isPagination` to true in your `ApiSwaggerResponse` attribute:

```php
#[ApiSwagger(summary: 'Get all users', tag: 'User')]
#[ApiSwaggerResponse(status: 200, resource: UserResource::class, isPagination: true)]
public function index(Request $request): UserPaginatedResource
{
    $users = $this->userRepository->paginate($request->input('perPage', 10));
    return new UserPaginatedResource($users);
}
```

## Support

For support, feedback, or questions, contact the maintainer at: letenantdoniyor@gmail.com
