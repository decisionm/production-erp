<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\TallySync\Models\TallyStockSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turn a Tally stock snapshot into the ERP's opening balances.
 *
 * WHY THIS IS NEEDED. The ERP's own stock ledger was never opened. Rehearsal
 * figures went into a warehouse that is now retired, so when production moved
 * to the real godown it started from zero and every batch drove it further
 * negative — 177 kg below zero on resin at the time of writing. Nothing on the
 * floor is wrong and Tally is unaffected (it keeps its own stock and accepted
 * the first real voucher against exactly this position). What breaks is
 * COSTING: material that never arrived also never arrived AT A PRICE, so the
 * pool has no rate to draw at and a batch cannot be costed. That figure is
 * what Sales sees.
 *
 * ============================ THE ONE-WAY DOOR ============================
 *
 * An opening balance posted twice is not a mistake anyone notices. There is no
 * error, no failed row, no red screen — the stock is simply bigger than it
 * should be, and it stays that way until someone counts the floor. So this
 * refuses a second application three separate ways:
 *
 *   1. the snapshot's own status, checked under a row lock;
 *   2. the stock ledger itself — any movement already carrying this
 *      snapshot's reference means it ran, whatever the status column says;
 *   3. one transaction, so a failure part-way leaves nothing behind.
 *
 * Guard 2 is the load-bearing one. Status is a claim this table makes about
 * itself; the ledger is the fact.
 *
 * ========================== WHAT IT WILL NOT POST ==========================
 *
 * Only lines the preview already judged importable — an exact Tally item this
 * ERP holds, a godown it knows, belonging to the bound company. An unmatched
 * line is reported and skipped, never guessed at: attaching an opening balance
 * to the nearest-looking product is how the wrong bottle acquires stock.
 *
 * Zero quantities are skipped as nothing to record. Negative closing stock is
 * skipped and reported rather than posted — Tally showing a negative is a
 * question for the accountant, not a receipt to copy into a second system.
 */
class TallyOpeningStockService
{
    public function __construct(
        private readonly StockMovementService $stock,
    ) {}

    /**
     * @return array{applied: int, skipped: list<string>, reference: string}
     */
    public function apply(TallyStockSnapshot $snapshot, ?int $userId): array
    {
        return DB::transaction(function () use ($snapshot, $userId) {
            /** @var TallyStockSnapshot $locked */
            $locked = TallyStockSnapshot::query()->lockForUpdate()->findOrFail($snapshot->id);

            if ($locked->isApplied()) {
                throw ValidationException::withMessages([
                    'snapshot' => sprintf(
                        'This snapshot was already applied on %s. Applying it again would add the whole opening balance a second time.',
                        $locked->applied_at?->toDayDateTimeString() ?? 'an earlier date',
                    ),
                ]);
            }

            $reference = $locked->movementReference();

            // The ledger is the fact, the status column is only a claim.
            if (StockMovement::query()->where('reference', $reference)->exists()) {
                throw ValidationException::withMessages([
                    'snapshot' => "The stock ledger already carries movements referenced '{$reference}'. "
                        .'An opening balance for this date has been posted before — nothing has been added.',
                ]);
            }

            $applied = 0;
            $skipped = [];

            foreach ($locked->lines as $line) {
                $label = ($line['tally_item_name'] ?? '?').' @ '.($line['godown'] ?? 'no godown');

                if (! ($line['importable'] ?? false)) {
                    $skipped[] = $label.' — '.implode('; ', $line['problems'] ?? ['not importable']);

                    continue;
                }

                $qty = (string) ($line['closing_quantity'] ?? '0');

                if (bccomp($qty, '0', 4) === 0) {
                    continue;
                }

                if (bccomp($qty, '0', 4) === -1) {
                    $skipped[] = $label." — Tally itself shows {$qty}, a negative closing balance. Not copied.";

                    continue;
                }

                if (($line['erp_item_id'] ?? null) === null || ($line['erp_warehouse_id'] ?? null) === null) {
                    // Cannot happen for an importable line, and refuses rather
                    // than falling through: an opening balance posted against a
                    // guessed item is exactly what importable exists to prevent.
                    $skipped[] = $label.' — marked importable but carries no resolved item or warehouse.';

                    continue;
                }

                $this->stock->recordReceipt(
                    itemId: (int) $line['erp_item_id'],
                    warehouseId: (int) $line['erp_warehouse_id'],
                    quantity: $qty,
                    // Tally's own closing rate. A line Tally prices at nothing
                    // comes in at zero rather than at an invented figure: the
                    // resin pool keeps unpriced kg out of its average and says
                    // so, which is the signal finance needs. A confident wrong
                    // cost would be worse than a visible missing one.
                    unitCost: (string) ($line['closing_rate'] ?? '0'),
                    reference: $reference,
                    movementDate: $locked->as_of->toDateString(),
                    notes: 'Opening balance read from Tally stock summary.',
                    createdBy: $userId,
                );

                $applied++;
            }

            $locked->forceFill([
                'status' => TallyStockSnapshot::STATUS_APPLIED,
                'applied_at' => now(),
                'applied_by' => $userId,
                'applied_line_count' => $applied,
            ])->save();

            return ['applied' => $applied, 'skipped' => $skipped, 'reference' => $reference];
        });
    }
}
