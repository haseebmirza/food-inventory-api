<?php

namespace App\Http\Requests;

use App\Models\InventoryMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryQueryRequest extends FormRequest
{
    /** Relations clients may include on movement history. */
    public const ALLOWED_INCLUDES = ['item', 'day'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'date' => [$this->routeIs('*.summary') ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'item_id' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', Rule::in(InventoryMovement::TYPES)],
            'per_page' => ['sometimes', 'integer', 'between:1,'.config('api.pagination.max')],
            'page' => ['sometimes', 'integer', 'min:1'],
            'fields' => ['sometimes', 'string', 'max:500'],
            'include' => ['sometimes', 'string', 'max:200'],
        ];
    }

    /**
     * Parse the ?include= param into validated relation names.
     *
     * @return string[]
     */
    public function includes(): array
    {
        $raw = $this->query('include');

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(array_intersect(
            array_map('trim', explode(',', $raw)),
            self::ALLOWED_INCLUDES
        ));
    }
}
