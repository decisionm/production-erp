<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialRequestStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A PRODUCTION MATERIAL REQUEST — the floor asking the store to hand over
 * material the factory already owns.
 *
 * SHAPE BORROWED, MEANING NOT. The header/lines/status shape is modelled on
 * Procurement's PurchaseRequisition, and the resemblance stops exactly
 * there. A PurchaseRequisition is a request to BUY: it faces a vendor, it
 * ends in a purchase order, it carries money, and FC-06 gates who may read
 * it. A MaterialRequest faces the STORE ACROSS THE YARD, it ends in a store
 * issue, and it carries no rate, no amount and no vendor at all. Do not
 * merge the two, do not share their enums, and do not read one as evidence
 * about the other.
 *
 * AND IT IS NOT A CONSUMPTION. Raising, submitting or even fully issuing
 * this document deducts nothing as consumed: an issue is a transfer of
 * location (Raw Material Store → Production/WIP, DEC-20260817-001) and
 * consumption is calculated later, at batch completion. Three states, never
 * collapsed.
 *
 * work_center_id is NULL for a common-input (resin/masterbatch) request and
 * is REFUSED if a caller names one — FC-01 and DEC-20260807-006: one
 * crane-fed loading point piped to all ten machines, so a bag belongs to no
 * machine and no batch. For film, cartons and tape a machine IS meaningful
 * and is carried. The refusal lives in MaterialRequestService.
 */
#[Fillable([
    'status', 'requested_by', 'requested_at', 'shift_id', 'work_center_id', 'notes',
    'submitted_at', 'cancelled_by', 'cancelled_at', 'cancelled_reason',
])]
class MaterialRequest extends Model
{
    /**
     * {submit, cancel, issue} as MaterialRequestService::abilities computed
     * them — stamped on every row the service hands back, printed by the
     * resource, and enforced by the actions themselves. Null means "not
     * decorated", never "nothing allowed"; the service decorates every row
     * it returns.
     *
     * @var array{submit: bool, cancel: bool, issue: bool}|null
     */
    public ?array $can = null;

    protected function casts(): array
    {
        return [
            'status' => MaterialRequestStatus::class,
            'requested_at' => 'datetime',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** "MR-{id}" — the number the floor and the store quote at each other. */
    public function documentNumber(): string
    {
        return "MR-{$this->id}";
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialRequestLine::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }
}
