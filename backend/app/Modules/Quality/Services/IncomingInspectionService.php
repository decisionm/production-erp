<?php

namespace App\Modules\Quality\Services;

use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Quality\Exceptions\InvalidInspectionQuantityException;
use App\Modules\Quality\Models\Enums\InspectionResult;
use App\Modules\Quality\Models\IncomingInspection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads Procurement's GoodsReceiptNoteLine via a plain Eloquent relation,
 * not a Service call — this module never mutates GRN/PO state, only reads
 * the received quantity to validate against, so a direct relation is the
 * right call here (same as any belongsTo(Item::class) elsewhere), not a
 * cross-module write requiring the owning module's Service.
 */
class IncomingInspectionService
{
    public function __construct(private readonly StockMovementService $stock) {}

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return IncomingInspection::query()
            ->with(['goodsReceiptNoteLine.goodsReceiptNote', 'item', 'inspectedBy'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * THE ARRIVAL LINES STILL WAITING FOR AN INSPECTION — the desk's queue.
     *
     * READ-ONLY. This method writes nothing, locks nothing and derives no QC
     * state; it answers one question the shipped code already decides.
     *
     * WHAT MAKES A LINE "PENDING", AND WHY IT IS NOT A JUDGEMENT CALL. This
     * service already refuses a second inspection on a line that has one
     * ("ONE DISPOSITION PER ARRIVAL LINE", create() above) — so "has no
     * `incoming_inspections` row" is not a lifecycle state invented here, it
     * is the exact complement of the refusal the deployed code enforces.
     * That is the whole of the filter.
     *
     * WHAT THIS DELIBERATELY DOES *NOT* FILTER ON. Not `MaterialBagStatus::
     * WaitingQc`, and not the presence of bags at all. DEC-20260825-001
     * records the incoming-QC hold and leaves expressly open "whether every
     * arrival line must wait for QA before the store may issue it or only
     * named materials". A bag-status filter would answer that open question
     * silently, in code, by making un-bagged arrivals vanish from the desk's
     * queue. The owner decides that, not this method.
     *
     * NOT PAGINATED, ON PURPOSE. A picker fed from a paginated list showing
     * the first 20 of a longer set is the defect `pickerFullList.test.ts`
     * exists to prevent — found live on 12-Aug-2026, when a resin PO could
     * not be raised because the raw materials were not in the first page.
     * The queue that says "what is waiting for QC" has to be all of it or it
     * is lying, so the full set is returned and ordered OLDEST FIRST, which
     * is the order the desk works it in.
     */
    public function pendingLines(): Collection
    {
        return GoodsReceiptNoteLine::query()
            ->with(['goodsReceiptNote', 'item'])
            // Quality reading its OWN table to exclude the lines it has
            // already dispositioned; the GRN line itself is read through a
            // plain relation for the reason this class's docblock gives.
            ->whereNotExists(fn ($query) => $query
                ->from('incoming_inspections')
                ->whereColumn('incoming_inspections.goods_receipt_note_line_id', 'goods_receipt_note_lines.id')
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array{goods_receipt_note_line_id: int, inspected_quantity: string, accepted_quantity: string, rejected_quantity: string, inspection_date: string, notes?: string}  $data
     */
    public function create(array $data, ?int $inspectedBy): IncomingInspection
    {
        return DB::transaction(function () use ($data, $inspectedBy) {
            $line = GoodsReceiptNoteLine::query()
                ->with('goodsReceiptNote')
                ->lockForUpdate()
                ->findOrFail($data['goods_receipt_note_line_id']);

            // ONE DISPOSITION PER ARRIVAL LINE. The inspection now moves
            // stock and releases bags; running it twice would move them
            // twice. A wrong result is corrected the way every stock fact
            // is — a recorded adjustment — not by re-inspecting.
            if (IncomingInspection::query()->where('goods_receipt_note_line_id', $line->id)->exists()) {
                throw ValidationException::withMessages([
                    'goods_receipt_note_line_id' => 'this arrival line already has its inspection — a wrong result needs a stock adjustment, not a second inspection',
                ]);
            }

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

            [$dispositionNote, $rejectionsOutReference] = $this->dispositionBags(
                $line,
                $accepted,
                $rejected,
                $inspectedBy,
                $data['inspection_date'],
            );

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
                'rejections_out_reference' => $rejectionsOutReference,
                'bag_disposition_note' => $dispositionNote,
            ])->load(['goodsReceiptNoteLine.goodsReceiptNote', 'item', 'inspectedBy']);
        });
    }

    /**
     * QC DISPOSITION AT WHOLE-BAG GRANULARITY (owner flow: bags arrive as
     * waiting_qc and unavailable; QC releases accepted quantity and routes
     * rejected quantity through a Rejections Out reference).
     *
     * Rejected bags are taken newest-first — the tail of the unload — and
     * only where the rejected kilograms cover whole bags. Whether a partial
     * QC result may SPLIT kilograms within one bag is an OPEN OWNER
     * DECISION; until it is ruled, the boundary bag stays waiting_qc with a
     * note saying exactly that, rather than this code inventing a split.
     *
     * The rejected kilograms leave usable stock through an ordinary issue
     * referencing the arrival ("Rejections Out {ref}") so the balance stays
     * true. NO Tally voucher is created here — the Rejections Out reference
     * is recorded for the day the voucher shape is proven and enabled.
     *
     * A line with no bags (a non-traceability item) needs no disposition —
     * the inspection is a recorded result exactly as before.
     *
     * @return array{0: ?string, 1: ?string} [disposition note, rejections-out reference]
     */
    private function dispositionBags(
        GoodsReceiptNoteLine $line,
        string $accepted,
        string $rejected,
        ?int $userId,
        string $inspectionDate,
    ): array {
        $bags = MaterialBag::query()
            ->whereHas('lot', fn ($query) => $query->where('goods_receipt_note_line_id', $line->id))
            ->where('status', MaterialBagStatus::WaitingQc->value)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        if ($bags->isEmpty()) {
            return [null, null];
        }

        $reference = null;
        $rejectedKg = '0.0000';
        $rejectedIds = [];
        $heldId = null;

        $left = bcadd($rejected, '0', 4);
        foreach ($bags as $bag) {
            if (bccomp($left, '0', 4) !== 1) {
                break;
            }
            $kg = (string) $bag->remaining_kg;
            if (bccomp($kg, $left, 4) <= 0) {
                $bag->update(['status' => MaterialBagStatus::RejectedQc]);
                $rejectedKg = bcadd($rejectedKg, $kg, 4);
                $rejectedIds[] = $bag->id;
                $left = bcsub($left, $kg, 4);
            } else {
                // The rejection ends inside this bag. Splitting one bag's
                // kilograms is the open owner decision — hold it, say so.
                $heldId = $bag->id;
                break;
            }
        }

        // Everything neither rejected nor held is released to production.
        $released = 0;
        foreach ($bags as $bag) {
            if ($bag->id === $heldId || in_array($bag->id, $rejectedIds, true)) {
                continue;
            }
            $bag->update(['status' => MaterialBagStatus::InStore]);
            $released++;
        }

        if (bccomp($rejectedKg, '0', 4) === 1) {
            $grn = $line->goodsReceiptNote;
            $reference = sprintf('RJO-%s-L%d', $grn->tracking_number ?? "GRN{$grn->id}", $line->id);
            // THE ONE ISSUE ALLOWED TO TAKE HELD KILOGRAMS OFF A BALANCE,
            // through the door named for exactly this and nothing else.
            //
            // Every other outflow is now refused above an incoming-QC hold
            // (StockMovementService::decrementBalance). A rejection has to
            // pass, or quality could never take failed material out of
            // stock — and the quantity is not anyone's to choose: it is the
            // summed remaining_kg of the bags flipped to `rejected_qc` two
            // dozen lines above, so it can be neither inflated by a payload
            // nor pointed at material this inspection did not just reject.
            //
            // It also must not take the item-wide `waiting_qc` lock the
            // guard takes: this transaction already holds ONE GRN line's
            // bags, and two inspections on two lines of the same material
            // would then each wait on bags the other holds. See
            // recordIncomingQcRejectionIssue() for that reading.
            $this->stock->recordIncomingQcRejectionIssue(
                itemId: $line->item_id,
                warehouseId: $grn->warehouse_id,
                quantity: $rejectedKg,
                reference: "Rejections Out {$reference}",
                movementDate: $inspectionDate,
                notes: 'Incoming QC rejection — awaiting the Rejections Out voucher decision; no Tally entry is created here.',
                createdBy: $userId,
            );
        }

        $note = sprintf(
            '%d bag(s) released, %d rejected whole (%s kg out of stock)%s.',
            $released,
            count($rejectedIds),
            rtrim(rtrim($rejectedKg, '0'), '.') ?: '0',
            $heldId !== null ? ', 1 bag held waiting_qc — the rejected quantity ends inside it, and whether QC may split one bag\'s kilograms is an open owner decision' : '',
        );

        return [$note, $reference];
    }
}
