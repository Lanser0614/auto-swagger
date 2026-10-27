<?php

namespace Tests\Fixtures\Parsing;

use Illuminate\Http\Resources\Json\JsonResource;

class DataFlowResource extends JsonResource
{
    public function toArray($request): array
    {
        $version = 1;
        $savedVersion = $version;
        $version = 'latest';
        $data = ['id' => $savedVersion, 'version' => $version];
        if ($request->user()) {
            $data['secret'] = 'yes';
        }
        $data['nested']['count'] = 2;

        return $data;
    }
}
