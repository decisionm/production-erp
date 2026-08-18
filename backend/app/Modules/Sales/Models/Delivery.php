<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sales_order_id', 'warehouse_id', 'reference', 'delivered_date', 'notes', 'created_by'])]
class Delivery extends Model
{
    /**
     * Read-side decorations set by DeliveryService (via SalesDocumentTraceService)
     * and read by DeliveryResource — plain properties, never attributes:
     * not persisted, not in toArray(), null on a bare model.
     *
     *   tallyLink   the TallyLink for this delivery's Delivery Note entry
     *               (TallySyncLinkService), or null when none exists — the
     *               service decorates every row it returns (list, show,
     *               create), so a null here means "no entry", never "not
     *               looked up".
     *   cartonCount how many scanned cartons left on it (FinishedCartonService)
     *   trace       the show endpoint's chain (sales_order, cartons, tally)
     */
    public ?array $tallyLink = null;

    public ?int $cartonCount = null;

    /** @var array<string, mixed>|null */
    public ?array $trace = null;

    protected function casts(): array
    {
        return [
            'delivered_date' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "DN-{id}" — the same number the Delivery Note voucher carries (TallySyncService::enqueueDelivery). */
    public function documentNumber(): string
    {
        return "DN-{$this->id}";
    }
}
