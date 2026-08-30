<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Models\Enums\SupplierBillStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The vendor's invoice, recorded — see the migration's docblock for what
 * this is and deliberately is not (no Tally posting: Q39/Q41/Q28 open).
 * FC-06: every figure is a purchase rate; the routes gate on
 * module:finance and no other surface serves these rows.
 */
#[Fillable([
    'vendor_id', 'purchase_order_id', 'bill_number', 'bill_date',
    'purchase_ledger_name', 'subtotal', 'cgst', 'sgst', 'igst', 'rounding',
    'total', 'notes', 'created_by',
])]
class SupplierBill extends Model
{
    /** The bill as every list names it: "BILL-{id}" (the vendor's own number is `bill_number`). */
    public function documentNumber(): string
    {
        return "BILL-{$this->id}";
    }

    protected function casts(): array
    {
        return [
            'status' => SupplierBillStatus::class,
            'bill_date' => 'date',
            'subtotal' => 'decimal:4',
            'cgst' => 'decimal:4',
            'sgst' => 'decimal:4',
            'igst' => 'decimal:4',
            'rounding' => 'decimal:4',
            'total' => 'decimal:4',
            'recorded_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
