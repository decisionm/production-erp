<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Support\Tally\CanonicalTallyStaging;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'vendor_id', 'purchase_requisition_id', 'status',
    'order_date', 'expected_date', 'notes', 'created_by',
    'source', 'tally_order_no',
    // When the order reached the vendor — null while it is a Draft. A
    // lifecycle fact only: the requisition's coverage no longer consults it
    // (DEC-20260831-006 counts material received and accepted by Quality,
    // and an unsent order received nothing whatever its status says).
    'sent_at',
    // Phase 6 lifecycle record — written once each by close() / cancel();
    // tally_staging only through PurchaseOrderService::recordTallyStaging.
    'closed_reason', 'closed_by', 'closed_at',
    'cancelled_reason', 'cancelled_by', 'cancelled_at',
    'tally_staging',
])]
class PurchaseOrder extends Model
{
    /**
     * Read-side decoration set by PurchaseOrderService and read by
     * PurchaseOrderResource — a plain property, never an attribute: not
     * persisted, not in toArray(), null on a bare model.
     *
     *   tallyLink  the TallyLink for this order's Purchase Order entry
     *              (TallySyncLinkService), or null when none exists. The
     *              service decorates every row it returns (list, show,
     *              create, every lifecycle action), so a null here means
     *              "no entry", never "not looked up".
     *   can        {amend, close, cancel, send} — PurchaseOrderService::
     *              abilities(), stamped by the same decoration; the resource
     *              falls back to asking the service when a caller hands it
     *              an undecorated row.
     */
    public ?array $tallyLink = null;

    /** @var array{amend: bool, close: bool, cancel: bool, send: bool}|null */
    public ?array $can = null;

    /**
     * The order as every document and every trace names it: "PO-{id}" —
     * the list's `q` grammar (ProcurementDocumentQuery::documentId) and
     * the reference a staged Tally voucher would carry (Q35(c) pending).
     */
    public function documentNumber(): string
    {
        return "PO-{$this->id}";
    }

    /** A read-only mirror of an order that lives in Tally — corrected there, never here. */
    public function isTallyMirror(): bool
    {
        return $this->source === 'tally';
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'sent_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            // Canonical key order on every read/write (the GRN's cast, same
            // latent defect): MySQL's JSON type reorders object keys.
            'tally_staging' => CanonicalTallyStaging::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The arrivals booked against this order, oldest first. */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceiptNote::class)->orderBy('id');
    }

    /** The append-only lifecycle history (amend / close snapshots), in revision order. */
    public function revisions(): HasMany
    {
        return $this->hasMany(PurchaseOrderRevision::class)->orderBy('revision_no');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
