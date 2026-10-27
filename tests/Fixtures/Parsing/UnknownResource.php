<?php

namespace Tests\Fixtures\Parsing;

use Illuminate\Http\Resources\Json\JsonResource;

class UnknownResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['total' => $this->calculateTotal()];
    }

    private function calculateTotal()
    {
        throw new \LogicException('Do not execute.');
    }
}
