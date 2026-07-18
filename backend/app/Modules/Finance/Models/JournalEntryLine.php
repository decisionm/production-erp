<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['journal_entry_id', 'gl_account_id', 'debit', 'credit', 'memo'])]
class JournalEntryLine extends Model
{
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function glAccount(): BelongsTo
    {
        // Explicit FK: belongsTo()'s guesser mishandles the "GL" acronym the
        // same way the table-name guesser does (see GLAccount::$table).
        return $this->belongsTo(GLAccount::class, 'gl_account_id');
    }
}
