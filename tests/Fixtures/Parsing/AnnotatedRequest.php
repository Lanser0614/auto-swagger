<?php

namespace Tests\Fixtures\Parsing;

use AutoSwagger\Attributes\ApiProperty;
use Illuminate\Foundation\Http\FormRequest;

class AnnotatedRequest extends FormRequest
{
    #[ApiProperty(type: 'number', name: 'total', required: true, example: 10.5)]
    public function rules(): array
    {
        return ['total' => 'required'];
    }
}
