<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Models\Enums\PurchaseRequisitionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['status', 'requested_by', 'needed_by_date', 'notes'])]
class PurchaseRequisition extends Model
{
    /** The requisition as every list names it: "PR-{id}" (the list's `q` grammar). */
    public function documentNumber(): string
    {
        return "PR-{$this->id}";
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseRequisitionStatus::class,
            'needed_by_date' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Deliberately NOT fillable — written only by the service at the moment of decision. */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** Deliberately NOT fillable — written only by the service at the moment of withdrawal. */
    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    /** The orders raised FROM this requisition (purchase_orders.purchase_requisition_id). */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
