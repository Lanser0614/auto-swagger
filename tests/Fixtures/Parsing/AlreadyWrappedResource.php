<?php

namespace Tests\Fixtures\Parsing;

class AlreadyWrappedResource extends PlainResource
{
    public function toArray($request): array
    {
        return ['data' => parent::toArray($request), 'version' => 1];
    }
}
