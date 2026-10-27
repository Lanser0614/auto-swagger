<?php

namespace Tests\Fixtures\Parsing;

use AutoSwagger\Attributes\ApiSwagger;

class GraphController
{
    #[ApiSwagger('Recursive categories')]
    public function cycle(): CategoryResource
    {
        return new CategoryResource((object) []);
    }

    #[ApiSwagger('Nested orders')]
    public function order(): OrderResource
    {
        return new OrderResource((object) []);
    }

    #[ApiSwagger('Unknown response type')]
    public function unknown(): UnknownResource
    {
        return new UnknownResource((object) []);
    }
}
