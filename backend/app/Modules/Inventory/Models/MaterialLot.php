<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialCostVersionKind;
use App\Modules\Inventory\Models\Enums\MaterialLotRateSource;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One supplier lot on a GRN (Phase 6 traceability chain: GRN → lot → bag →
 * day bin → batch). Lives in Inventory because it IS raw-material stock
 * detail — the store-side identity of what stock_movements track in
 * aggregate. All writes go through TraceabilityService.
 *
 * COST CONTRACT (other modules code against exactly these two methods):
 *   receiptRatePerKg() — the ORIGINAL goods-receipt rate. Never changes.
 *   currentRatePerKg() — the best rate known today: the latest cost version
 *                        if one has been appended, else the receipt rate.
 * Both return a decimal STRING (or null when the lot has no known rate at
 * all, which is the honest state of an opening-stock lot) so callers can
 * hand them straight to bcmath without a float ever touching a rupee.
 */
#[Fillable([
    'grn_id', 'goods_receipt_note_line_id', 'item_id', 'supplier_lot_no',
    'received_date', 'bag_count', 'bag_weight_kg', 'total_received_kg',
    'receipt_rate_per_kg', 'rate_source', 'notes', 'created_by',
])]
class MaterialLot extends Model
{
    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'bag_count' => 'integer',
            'bag_weight_kg' => 'decimal:4',
            'total_received_kg' => 'decimal:4',
            'receipt_rate_per_kg' => 'decimal:4',
            'rate_source' => MaterialLotRateSource::class,
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Read-only cross-module relation (same precedent as
     * ShiftProductionEntry→Item): GRN writes stay in Procurement.
     */
    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class, 'grn_id');
    }

    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNoteLine::class, 'goods_receipt_note_line_id');
    }

    public function bags(): HasMany
    {
        return $this->hasMany(MaterialBag::class);
    }

    /**
     * The lot's cost history, oldest first. Append-only — see
     * MaterialCostVersion. Writes go through MaterialCostVersionService.
     *
     * @return HasMany<MaterialCostVersion, $this>
     */
    public function costVersions(): HasMany
    {
        return $this->hasMany(MaterialCostVersion::class)->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The ORIGINAL goods-receipt rate, exactly as it was stamped when the
     * lot was created. Appending a hundred cost versions does not move it —
     * that is the whole point of keeping the column beside the history.
     *
     * Null = this lot has no purchase rate in the system (opening stock).
     */
    public function receiptRatePerKg(): ?string
    {
        $rate = $this->receipt_rate_per_kg;

        return $rate === null ? null : (string) $rate;
    }

    /**
     * The best rate known today: the most recently appended cost version,
     * falling back to the receipt rate when nothing has been appended.
     *
     * The fallback is deliberate and part of the contract — a caller asking
     * "what does this lot cost?" should get the receipt rate rather than
     * null merely because no invoice has landed yet. Null still means what
     * it always means: no rate is known at all.
     *
     * "Latest" is highest id. The table is append-only, so id order IS the
     * order the facts were recorded in; created_at can tie to the second on
     * two versions filed in one sitting, id cannot.
     */
    public function currentRatePerKg(): ?string
    {
        $latest = $this->relationLoaded('costVersions')
            ? $this->costVersions->sortByDesc('id')->first()
            : $this->costVersions()->reorder()->orderByDesc('id')->first();

        $rate = $latest?->rate_per_kg;

        return $rate === null ? $this->receiptRatePerKg() : (string) $rate;
    }

    /**
     * True once anything other than the original receipt rate has been
     * recorded — an invoice, a landed cost, or a correction. Deliberately
     * NOT "more than one version": a lot with no receipt rate whose first
     * ever version is an invoice HAS been revised, and a reader must be
     * told so.
     */
    public function hasRevisions(): bool
    {
        if ($this->relationLoaded('costVersions')) {
            return $this->costVersions
                ->contains(fn (MaterialCostVersion $version) => $version->kind !== MaterialCostVersionKind::Receipt);
        }

        return $this->costVersions()
            ->where('kind', '!=', MaterialCostVersionKind::Receipt->value)
            ->exists();
    }
}
