<?php

namespace Tests\Fixtures\Parsing;

use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Parsing\PlainResource as Entry;

class GenericResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['entries' => $this->entries()];
    }

    /** @return array<int, Entry> Result entries. */
    public function entries(): array
    {
        throw new \LogicException('Do not execute.');
    }
}
