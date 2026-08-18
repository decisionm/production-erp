<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a purchase order's append-only history (Phase 6, P6-01):
 * kind 'amend' keeps the lines a Draft had BEFORE they were replaced;
 * kind 'close' keeps what was still open per line when the order was
 * short-closed. Written only by PurchaseOrderService::amend / close, never
 * updated (no updated_at), never deleted.
 *
 * lines_json is a snapshot, not a relation: the rows it describes may no
 * longer exist (an amendment deletes a Draft's lines), so it carries the
 * item id AND its sku/name at the time. It carries the purchase rate for a
 * kind-'amend' row — Owner/Accounts data (FC-06): PurchaseOrderRevisionResource
 * omits it for everyone else.
 */
#[Fillable(['purchase_order_id', 'revision_no', 'kind', 'lines_json', 'amended_by', 'reason', 'created_at'])]
class PurchaseOrderRevision extends Model
{
    public const KIND_AMEND = 'amend';

    public const KIND_CLOSE = 'close';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'revision_no' => 'integer',
            'lines_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function amendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by');
    }
}
