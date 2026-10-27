<?php

namespace Tests\Fixtures\Parsing;

class StructuralResource extends PlainResource
{
    public function toArray($request): array
    {
        return parent::toArray($request) + [
            'id' => 'The left array wins',
            'list' => [0 => 1, 1 => 2],
            'helpers' => [$this->label()],
            'spread' => [...[1, 2], 3],
            'optional' => 'first',
            'optional' => $this->when($request->user(), 'second'),
            'removed' => 'first',
            'removed' => $this->when(false, 'second'),
        ];
    }

    private function label(): string
    {
        throw new \LogicException('Do not execute.');
    }
}
