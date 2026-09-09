<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FoodItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['required', Rule::in(['piece', 'kg', 'liter', 'portion'])],
            'minimum_stock' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,3})?$/'],
            'active' => ['sometimes', 'boolean'],
            'user_id' => ['prohibited'],
        ];
    }
}
