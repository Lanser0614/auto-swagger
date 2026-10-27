<?php

namespace App\Http\Resources;

use AutoSwagger\Laravel\Resources\PaginatedResource;

class ItemPaginatedResource extends PaginatedResource
{
    public function initCollection()
    {
        return $this->collection->map(fn ($item) => new ItemResource($item));
    }
}
