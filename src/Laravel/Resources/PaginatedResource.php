<?php
declare(strict_types=1);

namespace AutoSwagger\Laravel\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

abstract class PaginatedResource extends ResourceCollection
{
    public function toArray($request)
    {
        $collection = $this->initCollection();
        
        return [
            'data' => $collection,
            'pagination' => [
                'total' => $this->total(),
                'count' => $this->count(),
                'per_page' => $this->perPage(),
                'current_page' => $this->currentPage(),
                'total_pages' => $this->lastPage(),
            ],
        ];
    }

    public abstract function initCollection();
}
