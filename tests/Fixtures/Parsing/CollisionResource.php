<?php

namespace Tests\Fixtures\Parsing;

use AutoSwagger\Attributes\ApiSwaggerResource;

#[ApiSwaggerResource('Plain')]
class CollisionResource extends PlainResource {}
