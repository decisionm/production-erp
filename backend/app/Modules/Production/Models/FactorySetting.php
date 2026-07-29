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
