<?php

namespace Tests\Fixtures\Parsing;

class PaginationCollection extends ProductCollection
{
    public function paginationInformation($request, $paginated, $default): array
    {
        return array_merge($default, ['extra' => ['version' => 2]]);
    }
}
