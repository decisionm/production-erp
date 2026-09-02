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
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The register, newest first, searched and filtered on the server so a
     * page and its total are the matching set — not the first twenty rows
     * of everything.
     *
     * `$q` matches what a row shows: the product's sku or name (an archived
     * product's inspections stay findable), the arrival's GRN tracking
     * number and the Rejections Out reference — case-insensitive substrings,
     * with `%` and `_` taken literally ('!' escapes). A bare number is an
     * INSPECTION or GRN id ("12", "#12"); "GRN-12" / "grn 12" is the GRN
     * alone. Notes are deliberately not searched. The id is the tie-breaker
     * and the whole order, so two reads of one page agree.
     */
    public function paginate(int $perPage = 20, ?string $q = null, ?InspectionResult $result = null): LengthAwarePaginator
    {
        $term = trim((string) $q);

        return IncomingInspection::query()
            ->with(['goodsReceiptNoteLine.goodsReceiptNote', 'item', 'inspectedBy'])
            ->when($result, fn ($query) => $query->where('result', $result->value))
            ->when($term !== '', fn ($query) => $this->whereMatchesTerm($query, $term))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  Builder<IncomingInspection>  $query
     */
    private function whereMatchesTerm($query, string $term): void
    {
        $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
        $like = fn (string $column) => "lower({$column}) like ? escape '!'";

        // "GRN-12", "grn 12", "grn#12" name a receipt; "#12" an inspection;
        // a bare "12" could be either and matches both.
        $grnId = preg_match('/^grn[\s\-#]*(\d+)$/i', $term, $m) ? (int) $m[1] : null;
        $inspectionId = preg_match('/^#\s*(\d+)$/', $term, $m) ? (int) $m[1] : null;
        if (preg_match('/^\d+$/', $term)) {
            $grnId = $inspectionId = (int) $term;
        }

        $query->where(function ($any) use ($needle, $like, $grnId, $inspectionId) {
            $any->whereRaw($like('incoming_inspections.rejections_out_reference'), [$needle])
                ->orWhereHas('item', fn ($item) => $item->withTrashed()->where(function ($either) use ($needle, $like) {
                    $either->whereRaw($like('items.sku'), [$needle])->orWhereRaw($like('items.name'), [$needle]);
                }))
                ->orWhereHas('goodsReceiptNoteLine.goodsReceiptNote', function ($grn) use ($needle, $like, $grnId) {
                    $grn->whereRaw($like('goods_receipt_notes.tracking_number'), [$needle]);
                    if ($grnId !== null) {
                        $grn->orWhere('goods_receipt_notes.id', $grnId);
                    }
                });
            if ($inspectionId !== null) {
                $any->orWhere('incoming_inspections.id', $inspectionId);
            }
        });
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

            // THE WHOLE LINE, OR NOTHING. dispositionBags() releases every bag
            // that was not rejected, and it cannot do otherwise: a bag held
            // back has no way out, because the guard above refuses a second
            // inspection on this line. So inspecting PART of a line quietly
            // released the uninspected remainder into available stock as
            // though it had passed. See the exception for why this is refused
            // rather than reinterpreted.
            if (bccomp($inspected, $line->quantity, 4) !== 0) {
                throw InvalidInspectionQuantityException::mustCoverWholeLine($line->quantity, $inspected);
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
            // A LINE WITH NO BAGS STILL HAS TO SAY WHAT DID NOT HAPPEN.
            //
            // No bags means nothing to release and nothing to reject, and that
            // is deliberate — a non-traceability item has no bag to flip. But a
            // REJECTION on such a line was recorded on the inspection while the
            // material stayed in available stock, and the record said nothing
            // about it. Anyone reading "50 rejected" would reasonably believe
            // 50 had left the balance.
            //
            // The quantity is not this code's to move: on a bag-tracked line
            // the rejected weight is the summed weight of real bags, and here
            // the only source would be the figure a person typed — which this
            // service refuses to move stock on, by design. So the fact is
            // WRITTEN DOWN instead of acted on, and whether a typed rejection
            // may move stock stays the quality desk's answer.
            if (bccomp($rejected, '0', 4) === 1) {
                // Kept short ON PURPOSE: bag_disposition_note is varchar(200),
                // and MySQL in strict mode REFUSES an over-long value where
                // sqlite takes it quietly. The first draft of this sentence was
                // 215 characters, passed locally and failed on CI's MySQL —
                // exactly the driver gap DatabaseDriverParityTest exists for.
                // A test below pins the worst-case length.
                return [
                    sprintf(
                        'No bags on this line, so no stock was issued: %s recorded as rejected remains in the store. '.
                        'A line with no bags has none to reject, and the figure is typed rather than weighed.',
                        rtrim(rtrim($rejected, '0'), '.') ?: '0',
                    ),
                    null,
                ];
            }

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
