<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('date') !== null) {
            $this->merge(['date' => $this->route('date')]);
        }
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'openings' => [$this->routeIs('api.v1.days.close') ? 'prohibited' : 'sometimes', 'array', 'max:500'],
            'openings.*' => ['array:item_id,quantity'],
            'openings.*.item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'openings.*.quantity' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,3})?$/'],
            'user_id' => ['prohibited'],
        ];
    }
}
