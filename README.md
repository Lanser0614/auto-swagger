```php
 /**
     * @param int $id
     * @return BitrixResponse
     */
    #[ApiSwagger(summary: 'Get address by coordinates', tag: 'Bitrix')]
    #[ApiSwaggerRequest(request: ApiRequest::class, description: 'Get address by coordinates')]
    #[ApiSwaggerResponse(status: 200, resource: ApiResource::class, description: 'User details')]
    #[ApiSwaggerResponse(status: 500, description: 'Error on business request')]
    #[ApiSwaggerResponse(status: 422, description: 'Error on validation request')]
    public function getHumanAddressFormat(
        TestRequest $request
    ): BitrixResponse
    {
        $model = Model::query()->first();
        
        return new BitrixResponse($model);
    }
```

### ApiSwaggerResponse response property can be

#### Model
```php
    #[ApiSwaggerResponse(status: 200, resource: User::class, description: 'User details')]
```


#### Resource
```php
    #[ApiSwaggerResponse(status: 200, resource: ApiResource::class, description: 'User details')]
```


#### Array
```php
    #[ApiSwaggerResponse(status: 200, resource: [
        'id' => 'integer',
        'name' => 'string',
        "email" => "string",
    ], description: 'User details')]
```


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
            'name' => 'name',
            'id' => 123
        ];
    }
}
```

```markdown
composer require auto-swagger/php-swagger-generator
```
```markdown
php artisan vendor:publish --tag=auto-swagger-config
```

```markdown
php artisan vendor:publish --tag=auto-swagger-views
```

```markdown
php artisan vendor:publish --tag=auto-swagger-assets
```



## Config file auto-swagger.php
```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Swagger Documentation Settings
    |--------------------------------------------------------------------------
    */
    'title' => env('APP_NAME', 'Laravel') . ' API',
    'version' => '1.0.0',
    'description' => 'API Documentation',

    /*
    |--------------------------------------------------------------------------
    | Route Settings
    |--------------------------------------------------------------------------
    */
    'route' => [
        'prefix' => 'api/documentation',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Controllers Paths
    |--------------------------------------------------------------------------
    | Add the paths to your controller directories that should be scanned
    | for API documentation
    */
    'controllers' => [
        app_path('Http/Controllers'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Settings
    |--------------------------------------------------------------------------
    */
    'output' => [
        'json' => public_path('swagger/openapi.json'),
        'yaml' => public_path('swagger/openapi.yaml'),
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Settings
    |--------------------------------------------------------------------------
    */
    'ui' => [
        'enabled' => true,
        'theme' => 'dark', // light or dark
    ],

    'auth' => [
        'bearer' => [
            'enabled' => true
        ],
        'oauth2' => [
            'enabled' => false
        ],
        'apiKey' => [
            'enabled' => true
        ],
    ]
];

```

### generate api docs

```markdown
php artisan swagger:generate
```

### generate api docs on yaml
```markdown
php artisan swagger:generate --format=yaml
```

### Link for documentation

```markdown
http://domain/api/documentation
```

