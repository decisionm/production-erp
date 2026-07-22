<?php

namespace App\Modules\Production\Models;

use App\Modules\Production\Models\Enums\ShiftScrapType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shift_production_entry_id', 'type', 'quantity_nos', 'quantity_kg', 'scrap_reason_id'])]
class ShiftScrap extends Model
{
    protected function casts(): array
    {
        return [
            'type' => ShiftScrapType::class,
        ];
    }

    public function shiftProductionEntry(): BelongsTo
    {
        return $this->belongsTo(ShiftProductionEntry::class);
    }

    public function scrapReason(): BelongsTo
    {
        return $this->belongsTo(ScrapReason::class);
    }
}
