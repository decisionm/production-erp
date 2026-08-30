<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One handover of material from the store to production (Phase 7.5, WS-B).
 *
 * It is NOT a consumption and must never be read as one: the material has
 * moved from the store to Production/WIP and is standing there, unconsumed,
 * until a batch calculates its consumption or the unused part comes back.
 * All writes go through StoreIssueService.
 */
#[Fillable([
    'issue_number', 'issue_key', 'issue_payload_hash',
    'material_request_id', 'status', 'issued_by', 'received_by',
    'issued_at', 'closed_at', 'closed_by', 'cancellation_reason', 'notes',
])]
class StoreIssue extends Model
{
    protected function casts(): array
    {
        return [
            'status' => StoreIssueStatus::class,
            'issued_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StoreIssueLine::class);
    }

    public function bagScans(): HasMany
    {
        return $this->hasMany(StoreIssueBagScan::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
