<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Http\Resources\ItemPaginatedResource;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use AutoSwagger\Attributes\ApiSwagger;
use AutoSwagger\Attributes\ApiSwaggerQuery;
use AutoSwagger\Attributes\ApiSwaggerRequest;
use AutoSwagger\Attributes\ApiSwaggerResponse;

class ItemController extends Controller
{
    #[ApiSwagger(summary: 'Get item by ID', tag: 'Items')]
    #[ApiSwaggerResponse(resource: ItemResource::class, description: 'Item found')]
    #[ApiSwaggerResponse(status: 404, description: 'Item not found')]
    public function show(int $id): ItemResource
    {
        return new ItemResource(Item::findOrFail($id));
    }

    #[ApiSwagger(summary: 'List items', tag: 'Items')]
    #[ApiSwaggerQuery(name: 'page', type: 'integer')]
    #[ApiSwaggerQuery(name: 'per_page', type: 'integer')]
    #[ApiSwaggerResponse(resource: ItemResource::class, isPagination: true)]
    public function index(): ItemPaginatedResource
    {
        return new ItemPaginatedResource(Item::paginate(10));
    }

    #[ApiSwagger(summary: 'Create an item', tag: 'Items')]
    #[ApiSwaggerRequest(description: 'Item creation data')]
    #[ApiSwaggerResponse(status: 201, resource: ItemResource::class)]
    public function store(StoreItemRequest $request): ItemResource
    {
        return new ItemResource(Item::create($request->validated()));
    }

    #[ApiSwagger(summary: 'Update an item', tag: 'Items')]
    #[ApiSwaggerResponse(resource: ItemResource::class)]
    public function update(UpdateItemRequest $request, int $id): ItemResource
    {
        $item = Item::findOrFail($id);
        $item->update($request->validated());

        return new ItemResource($item);
    }
}
