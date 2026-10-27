<?php

namespace AutoSwagger\Generator;

use Illuminate\Foundation\Http\FormRequest;

/** @deprecated Import AutoSwagger\Analyzer\SchemaGenerator instead. */
class SchemaGenerator extends \AutoSwagger\Analyzer\SchemaGenerator
{
    public function generateFromFormRequest(string $requestClass): array
    {
        if (! is_subclass_of($requestClass, FormRequest::class)) {
            return [];
        }

        return parent::generateFromFormRequest($requestClass);
    }
}
