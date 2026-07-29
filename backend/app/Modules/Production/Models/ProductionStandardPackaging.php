<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'production_standard_id', 'mode', 'nos_per_pouch', 'pouches_per_box',
    'nos_per_tray', 'trays_per_box', 'nos_per_box', 'is_default',
])]
class ProductionStandardPackaging extends Model
{
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

    public function label(): string
    {
        return match ($this->mode) {
            self::MODE_POUCH => "Pouch + Box ({$this->nos_per_pouch}/pouch · {$this->nos_per_box}/box)",
            self::MODE_TRAY => "Tray + Box ({$this->nos_per_tray}/tray · {$this->nos_per_box}/box)",
            default => "Direct Box ({$this->nos_per_box}/box)",
        };
    }
}
