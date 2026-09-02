<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'key', 'value', 'data_type', 'scope', 'label', 'description',
    'confirmation_status', 'is_active', 'effective_from', 'changed_by', 'change_reason',
])]
class FactorySetting extends Model
{
    /**
     * The keys some part of the application actually READS.
     *
     * The Factory Rules screen lists every row of this table, and most rows
     * are the workbook's System Config sheet loaded as data: nothing in the
     * codebase reads GLOBAL_CYCLE_TIME_MIN or REQUIRE_OVERRIDE_REASON, so
     * editing them changes no behaviour. The screen must say so rather than
     * present a switch that does nothing. A key is added here in the same
     * change that adds its reader — FactorySettingsTest pins the two sides.
     */
    public const READ_BY_SOFTWARE = [
        'masterbatch_colour_map',
    ];

    /** True when a screen or rule reads this value; false for reference-only rows. */
    public function isReadBySoftware(): bool
    {
        return in_array($this->key, self::READ_BY_SOFTWARE, true);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'effective_from' => 'date',
        ];
    }

    /** The stored text cast to its declared type. */
    public function typedValue(): mixed
    {
        return match ($this->data_type) {
            'integer' => (int) $this->value,
            'decimal' => (string) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
