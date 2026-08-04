<?php

namespace App\Modules\TallySync\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reading of Tally's godown-wise closing stock, as at a date.
 *
 * Stored so it can be READ before it is used. Applying it is a separate act —
 * see TallyOpeningStockService — and this row is where "who applied it, and
 * when" lives afterwards.
 */
#[Fillable([
    'company', 'as_of', 'lines', 'totals', 'status',
    'created_by', 'applied_at', 'applied_by', 'applied_line_count',
])]
class TallyStockSnapshot extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'lines' => 'array',
            'totals' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }

    /**
     * The reference every movement this snapshot writes will carry.
     *
     * Deterministic on purpose: it is what makes a double-apply findable in
     * the stock ledger by anyone, not just by reading this table's status.
     */
    public function movementReference(): string
    {
        return 'Tally opening '.$this->as_of->toDateString();
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
