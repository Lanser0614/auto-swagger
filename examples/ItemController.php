<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Http\Resources\ItemResource;
use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use AutoSwagger\Attributes\ApiController;
use AutoSwagger\Attributes\ApiOperation;
use AutoSwagger\Attributes\ApiRequest;
use AutoSwagger\Attributes\ApiResponse;
use Illuminate\Http\Request;

#[ApiController(
    name: 'Items',
    description: 'Item management endpoints'
)]
class ItemController extends Controller
{
    #[ApiOperation(
        method: 'get',
        path: '/api/items/{id}',
        summary: 'Get item by ID',
        description: 'Retrieve a specific item by its unique identifier'
    )]
    #[ApiResponse(
        status: 200,
        description: 'Item found',
        resource: ItemResource::class
    )]
    #[ApiResponse(
        status: 404,
        description: 'Item not found'
    )]
    public function show($id)
    {
        $item = Item::findOrFail($id);
        return new ItemResource($item);
    }

    #[ApiOperation(
        method: 'post',
        path: '/api/items',
        summary: 'Create a new item',
        description: 'Create a new item with the provided data'
    )]
    #[ApiRequest(
        request: StoreItemRequest::class,
        description: 'Item creation data',
        required: true
    )]
    #[ApiResponse(
        status: 201,
        description: 'Item created successfully',
        resource: ItemResource::class
    )]
    #[ApiResponse(
        status: 422,
        description: 'Validation error',
        content: [
            'application/json' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string'],
                        'errors' => [
                            'type' => 'object',
                            'additionalProperties' => [
                                'type' => 'array',
                                'items' => ['type' => 'string']
                            ]
                        ]
                    ]
                ]
            ]
        ]
    )]
    public function store(StoreItemRequest $request)
    {
        $item = Item::create($request->validated());
        return new ItemResource($item);
    }

    #[ApiOperation(
        method: 'put',
        path: '/api/items/{id}',
        summary: 'Update an item',
        description: 'Update an existing item with the provided data'
    )]
    #[ApiRequest(
        request: UpdateItemRequest::class,
        description: 'Item update data'
    )]
    #[ApiResponse(
        status: 200,
        description: 'Item updated successfully',
        resource: ItemResource::class
    )]
    #[ApiResponse(
        status: 404,
        description: 'Item not found'
    )]
    #[ApiResponse(
        status: 422,
        description: 'Validation error'
    )]
    public function update(UpdateItemRequest $request, $id)
    {
        $item = Item::findOrFail($id);
        $item->update($request->validated());
        return new ItemResource($item);
    }

    #[ApiOperation(
        method: 'post',
        path: '/api/items/{id}/image',
        summary: 'Upload item image',
        description: 'Upload a new image for an existing item'
    )]
    #[ApiRequest(
        description: 'Item image file',
        mediaType: 'multipart/form-data'
    )]
    #[ApiResponse(
        status: 200,
        description: 'Image uploaded successfully',
        content: [
            'application/json' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => [
                            'type' => 'string',
                            'example' => 'Image uploaded successfully'
                        ],
                        'url' => [
                            'type' => 'string',
                            'format' => 'uri',
                            'example' => 'https://example.com/storage/items/123.jpg'
                        ]
                    ]
                ]
            ]
        ]
    )]
    public function uploadImage(Request $request, $id)
    {
        $item = Item::findOrFail($id);
        $path = $request->file('image')->store('items');
        $item->update(['image_path' => $path]);
        
        return response()->json([
            'message' => 'Image uploaded successfully',
            'url' => url('storage/' . $path)
        ]);
    }
}
