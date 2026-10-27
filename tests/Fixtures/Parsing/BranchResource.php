<?php

namespace Tests\Fixtures\Parsing;

use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray($request): array
    {
        if ($request->user()) {
            return ['id' => 1, 'secret' => 'yes'];
        }

        return ['id' => 2, 'public' => true];
    }
}
