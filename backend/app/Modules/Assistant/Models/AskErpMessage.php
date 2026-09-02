<?php

namespace App\Modules\Assistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AskErpMessage extends Model
{
    protected $fillable = ['conversation_id', 'role', 'question', 'sql', 'answer', 'tables_used', 'row_count', 'error', 'duration_ms'];

    protected $casts = ['tables_used' => 'array'];

    /**
     * Result rows for THIS response only; never persisted — the SQL is, and
     * is re-runnable.
     *
     * @var array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool, chart: array{type: string, x: string, y: string}|null}|null
     */
    public ?array $result = null;

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AskErpConversation::class, 'conversation_id');
    }
}
