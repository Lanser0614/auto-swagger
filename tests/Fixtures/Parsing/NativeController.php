<?php

namespace Tests\Fixtures\Parsing;

use AutoSwagger\Attributes\ApiSwagger;
use AutoSwagger\Attributes\ApiSwaggerResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class NativeController
{
    #[ApiSwagger('Native single')]
    public function show(): PlainResource
    {
        return new PlainResource((object) []);
    }

    #[ApiSwagger('Native collection')]
    public function index()
    {
        return PlainResource::collection(collect([(object) []]));
    }

    #[ApiSwagger('Native pagination')]
    public function paginate()
    {
        return PlainResource::collection(new LengthAwarePaginator([(object) []], 1, 10));
    }

    #[ApiSwagger('Simple pagination')]
    public function simple()
    {
        return PlainResource::collection(new Paginator([(object) []], 10));
    }

    #[ApiSwagger('Cursor pagination')]
    public function cursor()
    {
        return PlainResource::collection(new CursorPaginator([], 10));
    }

    #[ApiSwagger('Collected pagination')]
    public function collected()
    {
        return new ProductCollection(new LengthAwarePaginator([(object) []], 1, 10));
    }

    #[ApiSwagger('Additional metadata')]
    public function metadata()
    {
        return (new WithMetadataResource((object) []))->additional(['meta' => ['request' => 'test']]);
    }

    #[ApiSwagger('Custom wrapper')]
    public function custom(): CustomWrapperResource
    {
        return new CustomWrapperResource((object) []);
    }

    #[ApiSwagger('Already wrapped')]
    public function wrapped(): AlreadyWrappedResource
    {
        return new AlreadyWrappedResource((object) []);
    }

    #[ApiSwagger('Custom collection')]
    public function customCollection(): CustomCollection
    {
        return new CustomCollection(collect([(object) []]));
    }

    #[ApiSwagger('Explicit overrides')]
    #[ApiSwaggerResponse(resource: ['value' => 'boolean'])]
    public function explicit(): PlainResource
    {
        return new PlainResource((object) []);
    }

    #[ApiSwagger('Item wrappers do not affect anonymous collections')]
    public function customItems()
    {
        return CustomWrapperResource::collection(collect([(object) []]));
    }

    #[ApiSwagger('Custom pagination information')]
    public function paginationInformation()
    {
        return new PaginationCollection(new LengthAwarePaginator([(object) []], 1, 10));
    }

    #[ApiSwagger('Metadata merge collisions')]
    public function collidedMetadata()
    {
        return (new WithMetadataResource((object) []))->additional(['meta' => ['source' => 42]]);
    }
}
