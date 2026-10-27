<?php

namespace Tests\Fixtures\Parsing;

use AutoSwagger\Attributes\ApiProperty;

class OverriddenResource extends UnknownResource
{
    #[ApiProperty(type: 'number', name: 'total', example: 10.5)]
    public function metadata() {}
}
