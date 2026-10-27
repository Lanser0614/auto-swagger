<?php

namespace Tests\Fixtures\Parsing;

use Illuminate\Http\Resources\Json\ResourceCollection;

class CustomCollection extends ResourceCollection
{
    public $collects = PlainResource::class;

    public function toArray($request): array
    {
        return ['data' => $this->collection, 'stats' => ['count' => $this->collection->count()]];
    }
}
