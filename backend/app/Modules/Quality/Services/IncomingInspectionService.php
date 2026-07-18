<?php

namespace App\Modules\Quality\Services;

use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Quality\Exceptions\InvalidInspectionQuantityException;
use App\Modules\Quality\Models\Enums\InspectionResult;
use App\Modules\Quality\Models\IncomingInspection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Reads Procurement's GoodsReceiptNoteLine via a plain Eloquent relation,
 * not a Service call — this module never mutates GRN/PO state, only reads
 * the received quantity to validate against, so a direct relation is the
 * right call here (same as any belongsTo(Item::class) elsewhere), not a
 * cross-module write requiring the owning module's Service.
 */
class IncomingInspectionService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return IncomingInspection::query()
            ->with(['goodsReceiptNoteLine.goodsReceiptNote', 'item', 'inspectedBy'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{goods_receipt_note_line_id: int, inspected_quantity: string, accepted_quantity: string, rejected_quantity: string, inspection_date: string, notes?: string}  $data
     */
    public function create(array $data, ?int $inspectedBy): IncomingInspection
    {
        $line = GoodsReceiptNoteLine::findOrFail($data['goods_receipt_note_line_id']);

        $inspected = (string) $data['inspected_quantity'];
        $accepted = (string) $data['accepted_quantity'];
        $rejected = (string) $data['rejected_quantity'];

        if (bccomp($inspected, $line->quantity, 4) > 0) {
            throw InvalidInspectionQuantityException::exceedsReceived($line->quantity, $inspected);
        }

        if (bccomp(bcadd($accepted, $rejected, 4), $inspected, 4) !== 0) {
            throw InvalidInspectionQuantityException::mismatch($inspected, $accepted, $rejected);
        }

        $result = match (true) {
            bccomp($rejected, '0', 4) === 0 => InspectionResult::Pass,
            bccomp($accepted, '0', 4) === 0 => InspectionResult::Fail,
            default => InspectionResult::Partial,
        };

        return IncomingInspection::create([
            'goods_receipt_note_line_id' => $line->id,
            'item_id' => $line->item_id,
            'inspected_quantity' => $inspected,
            'accepted_quantity' => $accepted,
            'rejected_quantity' => $rejected,
            'result' => $result,
            'inspection_date' => $data['inspection_date'],
            'inspected_by' => $inspectedBy,
            'notes' => $data['notes'] ?? null,
        ])->load(['goodsReceiptNoteLine.goodsReceiptNote', 'item', 'inspectedBy']);
    }
}
