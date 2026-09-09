<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryDay extends Model
{
    /** @var string[] */
    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'closed_at' => 'immutable_datetime'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
