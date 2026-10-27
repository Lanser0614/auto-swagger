<?php

namespace Tests\Fixtures;

use AutoSwagger\Attributes\ApiSwagger;
use AutoSwagger\Attributes\ApiSwaggerResponse;

class BrokenController
{
    #[ApiSwagger('Broken route')]
    #[ApiSwaggerResponse(resource: 'App\\MissingResource')]
    public function show() {}
}
