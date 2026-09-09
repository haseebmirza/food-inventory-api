<?php

namespace App\Http\Requests;

use App\Models\InventoryMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['date' => $this->route('date'), 'idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'item_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(InventoryMovement::TYPES)],
            'quantity' => ['required', 'string', 'regex:/^-?(0|[1-9][0-9]{0,8})(\.[0-9]{1,3})?$/'],
            'note' => ['required_if:type,adjustment', 'nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
            'created_by' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
