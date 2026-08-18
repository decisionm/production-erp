<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'receipt_key', 'receipt_payload_hash', 'purchase_order_id', 'warehouse_id',
    'reference', 'received_date', 'notes', 'created_by',
    'receipt_note_reference', 'tracking_number',
])]
class GoodsReceiptNote extends Model
{
    /**
     * Read-side decoration set by GoodsReceiptService (via
     * PurchaseOrderTraceService::decorateReceipts) and read by
     * GoodsReceiptNoteResource — a plain property, never an attribute: the
     * TallyLink for this receipt's Receipt Note entry (TallySyncLinkService),
     * or null when none exists. Every row the service returns is decorated,
     * so null means "no entry", never "not looked up".
     */
    public ?array $tallyLink = null;

    /** The receipt as every list and trace names it: "GRN-{id}" (the list's `q` grammar). */
    public function documentNumber(): string
    {
        return "GRN-{$this->id}";
    }

    protected function casts(): array
    {
        return [
            'received_date' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptNoteLine::class);
    }

    public function materialLots(): HasMany
    {
        return $this->hasMany(MaterialLot::class, 'grn_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
