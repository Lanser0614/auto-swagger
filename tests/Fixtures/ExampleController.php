<?php

namespace Tests\Fixtures;

use AutoSwagger\Attributes\ApiResponseException;
use AutoSwagger\Attributes\ApiSwagger;
use AutoSwagger\Attributes\ApiSwaggerQuery;
use AutoSwagger\Attributes\ApiSwaggerRequest;
use AutoSwagger\Attributes\ApiSwaggerResponse;

class ExampleController
{
    #[ApiSwagger(summary: 'Find item', description: 'Item details', tag: 'Items', operationId: 'showItem', deprecated: true)]
    #[ApiSwaggerResponse(status: 200, resource: ItemResource::class)]
    #[ApiSwaggerResponse(status: 404, description: 'Not found')]
    public function show(int $id) {}

    #[ApiSwagger(summary: 'Create item', tag: 'Items')]
    #[ApiSwaggerRequest(description: 'Create data')]
    #[ApiSwaggerResponse(status: 201, resource: ['id' => 'integer'])]
    public function store(StoreRequest $request) {}

    #[ApiSwagger('List items', tag: 'Items')]
    #[ApiSwaggerQuery(name: 'page', type: 'integer')]
    #[ApiSwaggerQuery('search')]
    #[ApiSwaggerResponse(resource: ItemResource::class, isPagination: true)]
    public function index() {}

    #[ApiSwagger('API key route')]
    #[ApiResponseException(status: 403, description: 'Forbidden')]
    public function key() {}

    #[ApiSwagger('Update item')]
    public function update(int $id) {}

    #[ApiSwagger('Invoke')]
    public function __invoke() {}

    public function ignored() {}
}
