<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One material asked for, on one request.
 *
 * `uom` is a SNAPSHOT of the item's unit taken when the request was raised.
 * It is never accepted from the caller: FC-03 is the reason — 229 metres of
 * tape filed as 229 Nos is a different number about a different thing, and
 * that reached the live factory once.
 *
 * `quantity` is WHAT IS ASKED OF THE STORE, and always has been. Where a
 * request considered the floor (DEC-20260831-001), `required_quantity` is
 * what production actually needed and `available_in_production` is what was
 * already standing there when the request was raised — so `quantity` is the
 * balance between them, floored at zero. Both are NULL on a request that
 * never netted, which is not the same as zero: zero would claim the floor was
 * empty.
 *
 * `issued_quantity` is how much the store has handed over so far. It is
 * written ONLY through MaterialRequestService::applyIssuedQuantities, and
 * it is NOT consumption: issued material is standing in Production/WIP
 * (DEC-20260817-001) until a batch calculates that it used some of it.
 */
#[Fillable([
    'material_request_id', 'item_id', 'quantity', 'uom', 'issued_quantity', 'notes',
    // DEC-20260831-001, and NULL where a request never considered the floor.
    'required_quantity', 'available_in_production',
])]
class MaterialRequestLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'issued_quantity' => 'decimal:4',
            'required_quantity' => 'decimal:4',
            'available_in_production' => 'decimal:4',
        ];
    }

    /**
     * What the store still owes on this line, floored at zero and exact to
     * 4dp (bcmath, never float — money and stock quantities are decimal all
     * the way through this codebase).
     *
     * The floor is not cosmetic. A bag is not divisible: asking for 20 kg
     * and being handed a 25 kg bag is the ordinary case, so an issue may
     * legitimately exceed what was asked for. The overage stays visible in
     * `issued_quantity`; what is owed is simply nothing.
     */
    public function remainingQuantity(): string
    {
        $remaining = bcsub((string) $this->quantity, (string) $this->issued_quantity, 4);

        return bccomp($remaining, '0', 4) === 1 ? $remaining : '0.0000';
    }

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
