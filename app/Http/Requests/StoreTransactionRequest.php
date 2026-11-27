<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

#[FailOnUnknownFields]
class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => [
                'required',
                'array',
                'min:1',
            ],

            'items.*' => [
                'required',
                'array:productId,quantity',
            ],

            'items.*.productId' => [
                'required',
                'uuid',
                'distinct:ignore_case',
            ],

            'items.*.quantity' => [
                'required',
                'integer:strict',
                'min:1',
                'max:2147483647',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.productId.distinct' => 'Each productId may appear only once in a transaction.',
            'items.*.quantity.integer' => 'quantity must be an integer between 1 and 2147483647.',
            'items.*.quantity.min' => 'quantity must be an integer between 1 and 2147483647.',
            'items.*.quantity.max' => 'quantity must be an integer between 1 and 2147483647.',
            'items.*.quantity.required' => 'quantity must be an integer between 1 and 2147483647.',
        ];
    }
}
