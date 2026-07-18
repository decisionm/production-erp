<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\Finance\Models\Enums\JournalEntryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['entry_date', 'reference', 'memo', 'status', 'created_by'])]
class JournalEntry extends Model
{
    protected function casts(): array
    {
        return [
            'status' => JournalEntryStatus::class,
            'entry_date' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
