<?php

namespace Tests\Fixtures\Parsing;

class WithMetadataResource extends PlainResource
{
    public function with($request): array
    {
        return ['meta' => ['source' => 'fixture']];
    }
}
