<?php

namespace App\Modules\Production\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'production_standard_id', 'mode', 'nos_per_pouch', 'pouches_per_box',
    'nos_per_tray', 'trays_per_box', 'nos_per_box', 'is_default',
    // The Tally item THIS packing's production posts as (DEC-20260810-003).
    // Null = no identity of its own — the run uses the product's item and
    // the screen says so; an unknown identity stays editable, never guessed.
    'item_id', 'item_set_by', 'item_set_at',
])]
class ProductionStandardPackaging extends Model
{
    /**
     * Serialized on every payload so each surface showing a packaging row —
     * the Start Batch picker, the standards workspace — can say whether it
     * is runnable without re-deriving the rule client-side.
     */
    protected $appends = ['is_complete'];

    public const MODE_POUCH = 'pouch';

    public const MODE_TRAY = 'tray';

    public const MODE_DIRECT_BOX = 'direct_box';

    protected function casts(): array
    {
        return [
            'nos_per_pouch' => 'integer',
            'pouches_per_box' => 'integer',
            'nos_per_tray' => 'integer',
            'trays_per_box' => 'integer',
            'nos_per_box' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function standard(): BelongsTo
    {
        return $this->belongsTo(ProductionStandard::class, 'production_standard_id');
    }

    /** The packing's own Tally identity, when it has one. */
    public function tallyItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * Whether this row can actually run a batch: pieces per box, plus the
     * inner count its own mode calls for. The workbook import accepts
     * half-stated rows on purpose (a "120 × ?" pouch line is a real fact
     * about the sheet, worth showing on the standards page) — but a run
     * that AUTO-adopts one gets a pouch figure with no carton figure, and
     * item 423's single such row is what the Start Batch preview choked on.
     * An incomplete row is display data, never run data: resolvePackaging()
     * skips it and the run falls back to the item master's packing.
     */
    public function isComplete(): bool
    {
        $inner = match ($this->mode) {
            self::MODE_POUCH => $this->nos_per_pouch,
            self::MODE_TRAY => $this->nos_per_tray,
            // Direct-to-box has no inner container; the box figure is the
            // whole spec.
            default => $this->nos_per_box,
        };

        return $this->nos_per_box !== null && $this->nos_per_box > 0
            && $inner !== null && $inner > 0;
    }

    public function getIsCompleteAttribute(): bool
    {
        return $this->isComplete();
    }

    public function label(): string
    {
        return match ($this->mode) {
            self::MODE_POUCH => "Pouch + Box ({$this->nos_per_pouch}/pouch · {$this->nos_per_box}/box)",
            self::MODE_TRAY => "Tray + Box ({$this->nos_per_tray}/tray · {$this->nos_per_box}/box)",
            default => "Direct Box ({$this->nos_per_box}/box)",
        };
    }
}
