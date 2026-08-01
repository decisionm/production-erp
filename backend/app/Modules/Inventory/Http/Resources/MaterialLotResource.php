<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FIELD-LEVEL GATING (the first in this codebase — here is why).
 *
 * EnsureModulePermission is coarse by design: one view/manage pair per
 * module gates a whole route group. That is right for whole features and
 * wrong for this one field group, because a material lot is a thing the
 * STORE must read (which bags, which supplier lot, how many kilos) that
 * carries a number only the OWNER and ACCOUNTS should see (what the
 * supplier charged per kg). Splitting the lot endpoint in two, or minting
 * an inventory.costs permission, both fail: PermissionSeeder resyncs
 * Administrator to exactly PermissionService's catalog on every deploy, so
 * an invented permission is stripped on the next `migrate --seed` and the
 * gate silently opens.
 *
 * So the rate fields hang off finance.view/finance.manage — the permission
 * the Owner and the Accounts team already hold and the store does not — and
 * are OMITTED ENTIRELY, not nulled, for everyone else. Omitted matters: a
 * null rate is a real and meaningful state here (opening stock has no
 * purchase rate), so nulling the field for a store user would be
 * indistinguishable from telling them the lot genuinely cost nothing.
 */
class MaterialLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $showsCost = $request->user()?->hasAnyPermission(['finance.view', 'finance.manage']) ?? false;

        return [
            'id' => $this->id,
            'grn_id' => $this->grn_id,
            'goods_receipt_note_line_id' => $this->goods_receipt_note_line_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'supplier_lot_no' => $this->supplier_lot_no,
            'received_date' => $this->received_date?->toDateString(),
            'bag_count' => $this->bag_count,
            'bag_weight_kg' => $this->bag_weight_kg,
            'total_received_kg' => $this->total_received_kg,
            'bags' => MaterialBagResource::collection($this->whenLoaded('bags')),
            // Receipt provenance so the lot register can show — and link to —
            // the goods receipt this lot arrived on, with the price paid and
            // the exact date+time it was received. Only present when the
            // caller eager-loaded the relations (the lot register does).
            'receipt' => $this->when(
                $this->relationLoaded('grn') && $this->grn !== null,
                fn () => [
                    'goods_receipt_note_id' => $this->grn->id,
                    'purchase_order_id' => $this->grn->purchase_order_id,
                    'received_at' => $this->grn->received_date?->toIso8601String(),
                    'unit_cost' => $this->relationLoaded('goodsReceiptLine')
                        ? $this->goodsReceiptLine?->unit_cost
                        : null,
                ],
            ),
            // Rate provenance and cost history — Owner/Accounts only, see
            // the class note. receipt_* is the original, never-moving rate;
            // current_* is the latest recorded version (the original when
            // nothing has been appended); has_revisions says whether the
            // two can differ. null rate_source means UNKNOWN, not zero.
            ...($showsCost ? [
                'receipt_rate_per_kg' => $this->receiptRatePerKg(),
                'rate_source' => $this->rate_source?->value,
                'current_rate_per_kg' => $this->currentRatePerKg(),
                'has_revisions' => $this->hasRevisions(),
            ] : []),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
