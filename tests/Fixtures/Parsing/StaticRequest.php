<?php

namespace Tests\Fixtures\Parsing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaticRequest extends FormRequest
{
    public function __construct()
    {
        throw new \LogicException('Do not construct requests in static mode.');
    }

    public function rules(): array
    {
        throw new \LogicException('Do not execute rules in static mode.');

        return ['id' => ['required', 'integer'], 'status' => ['string', Rule::in(['active', 'disabled'])]];
    }
}
