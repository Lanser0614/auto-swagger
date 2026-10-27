<?php

namespace Tests\Fixtures;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        throw new \LogicException('Documentation must never authorize requests.');
    }

    public function rules(Repository $config): array
    {
        return [
            'name' => 'required|string|max:255',
            'price' => 'min:0.5|required|numeric',
            'tags' => 'array',
            'tags.*' => 'integer',
            'items.*.id' => 'required|integer',
            'items' => 'required|array|min:1',
            'image' => 'nullable|image|max:2048',
        ];
    }
}
