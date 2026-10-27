<?php

namespace Tests\Fixtures\Parsing;

use AutoSwagger\Attributes\ApiSwaggerRequest;
use Illuminate\Foundation\Http\FormRequest;

#[ApiSwaggerRequest(schema: ['type' => 'object', 'properties' => ['value' => ['type' => 'number']]])]
class ExplicitRequest extends FormRequest
{
    public function __construct()
    {
        throw new \LogicException('An explicit schema must bypass runtime evaluation.');
    }

    public function rules(): array
    {
        throw new \LogicException('Do not execute.');
    }
}
