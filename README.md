```php
 /**
     * @param int $id
     * @return BitrixResponse
     */
    #[ApiSwagger(summary: 'Get address by coordinates', tag: 'Bitrix')]
    #[ApiSwaggerRequest(request: TestRequest::class, description: 'Get address by coordinates')]
    #[ApiSwaggerResponse(status: 200, resource: BitrixResponse::class, description: 'User details')]
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

```php
use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Http\Resources\Json\JsonResource;

#[ApiSwaggerResource(name: 'User', properties: [
    'id' => 'integer',
    'name' => 'string',
])]
class BitrixResponse extends JsonResource
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

php artisan vendor:publish --tag=auto-swagger-config

php artisan vendor:publish --tag=auto-swagger-views

php artisan vendor:publish --tag=auto-swagger-assets
```# auto-swagger
