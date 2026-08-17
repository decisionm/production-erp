<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One packing line of a completed batch — the validated wire line
 * (CompleteBatchRequest `packing_lines.*`), stored figure for figure in the
 * completion's transaction and replaced on amendment. See the create
 * migration for why the columns carry the wire names.
 */
#[Fillable([
    'shift_production_entry_id', 'production_standard_packaging_id', 'position',
    'mode', 'boxes', 'nos_per_box', 'loose_inner', 'nos_per_inner',
    'derived_pieces', 'actual_pieces', 'override_reason',
])]
class ShiftProductionEntryPackingLine extends Model
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'boxes' => 'integer',
            'nos_per_box' => 'integer',
            'loose_inner' => 'integer',
            'nos_per_inner' => 'integer',
            'derived_pieces' => 'integer',
            'actual_pieces' => 'integer',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ShiftProductionEntry::class, 'shift_production_entry_id');
    }

    /** The packaging option the line cited, if it cited one. */
    public function packaging(): BelongsTo
    {
        return $this->belongsTo(ProductionStandardPackaging::class, 'production_standard_packaging_id');
    }
}
