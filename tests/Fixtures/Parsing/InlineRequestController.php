<?php

namespace Tests\Fixtures\Parsing;

use AutoSwagger\Attributes\ApiSwagger;
use AutoSwagger\Attributes\ApiSwaggerRequest;

class InlineRequestController
{
    #[ApiSwagger('Explicit inline request')]
    #[ApiSwaggerRequest(schema: ['type' => 'object', 'properties' => ['value' => ['type' => 'number']]], required: false)]
    public function store() {}
}
