<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InventoryMovement extends Model
{
    public const TYPES = ['opening', 'in', 'out', 'waste', 'adjustment'];

    /** @var string[] */
    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Inventory movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Inventory movements are immutable.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodItem::class, 'food_item_id');
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(InventoryDay::class, 'inventory_day_id');
    }
}
