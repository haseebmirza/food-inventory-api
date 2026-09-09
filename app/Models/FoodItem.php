<?php

namespace App\Models;

use Database\Factories\FoodItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'unit', 'minimum_stock', 'active'])]
class FoodItem extends Model
{
    /** @use HasFactory<FoodItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['minimum_stock' => 'decimal:3', 'active' => 'boolean'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
