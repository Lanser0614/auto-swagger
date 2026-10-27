<?php

namespace Tests\Fixtures\Parsing;

use Illuminate\Http\Resources\Json\ResourceCollection;

class ProductCollection extends ResourceCollection
{
    public $collects = PlainResource::class;
}
