<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

#[FailOnUnknownFields]
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:160',
                'regex:/\S/',
            ],
            'unit' => [
                'required',
                'string',
                'max:32',
                'regex:/\S/',
            ],
            'sellingPrice' => [
                'required',
                'integer',
                'min:0',
                'max:999999999999999',
            ],
            'stockQuantity' => [
                'required',
                'integer',
                'min:0',
                'max:2147483647',
            ],
            'minimumStock' => [
                'required',
                'integer',
                'min:0',
                'max:2147483647',
            ],
        ];
    }
}
