<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per POST /exports/{kind} — who asked for what, with which
 * filters, and what happened: a file streamed to its end (completed, with
 * its row count and the sha256 of the bytes), or a REFUSAL (blocked kind,
 * cap exceeded — refusal_reason says which, completed stays false). A run
 * that began streaming and never finished (client gone mid-file) is left
 * completed=false with no refusal_reason: the honest state, not a claim.
 *
 * created_at only — a run is a record of an attempt, never edited beyond
 * the one completion stamp the streamer writes when the last byte is out.
 * Written by ExportService only.
 */
#[Fillable([
    'user_id', 'kind', 'filters', 'row_count', 'file_name',
    'sha256', 'completed', 'refusal_reason', 'created_at',
])]
class ExportRun extends Model
{
    /** No updated_at column: the completion stamp is the only later write. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'row_count' => 'integer',
            'completed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
