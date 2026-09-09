<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FiltersFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FoodItemResource extends JsonResource
{
    use FiltersFields;

    /** Fields clients may request via ?fields= */
    public const ALLOWED_FIELDS = ['id', 'name', 'unit', 'minimum_stock', 'active', 'created_at', 'updated_at'];

    public function toArray(Request $request): array
    {
        return $this->filterFields([
            'id' => $this->id, 'name' => $this->name, 'unit' => $this->unit,
            'minimum_stock' => $this->minimum_stock, 'active' => $this->active,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ], $request);
    }
}
