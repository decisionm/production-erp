<?php

namespace App\Modules\Quality\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['item_id', 'name', 'unit_of_measure', 'target_value', 'lower_spec_limit', 'upper_spec_limit', 'is_active'])]
class SpcCharacteristic extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:4',
            'lower_spec_limit' => 'decimal:4',
            'upper_spec_limit' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(SpcMeasurement::class)->orderBy('measured_at');
    }
}
