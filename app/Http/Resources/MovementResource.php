<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FiltersFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovementResource extends JsonResource
{
    use FiltersFields;

    /** Fields clients may request via ?fields= */
    public const ALLOWED_FIELDS = ['id', 'item_id', 'item', 'date', 'type', 'quantity', 'note', 'created_by', 'idempotency_key', 'created_at', 'updated_at'];

    public function toArray(Request $request): array
    {
        return $this->filterFields([
            'id' => $this->id, 'item_id' => $this->food_item_id,
            'item' => new FoodItemResource($this->whenLoaded('item')),
            'date' => $this->whenLoaded('day', fn () => $this->day->date->format('Y-m-d')),
            'type' => $this->type, 'quantity' => $this->quantity, 'note' => $this->note,
            'created_by' => $this->created_by, 'idempotency_key' => $this->idempotency_key,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ], $request);
    }
}
