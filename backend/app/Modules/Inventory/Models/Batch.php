<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['item_id', 'batch_number', 'manufactured_date', 'expiry_date', 'notes'])]
class Batch extends Model
{
    protected function casts(): array
    {
        return [
            'manufactured_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
