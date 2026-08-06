<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\TallySync\Models\TallyStockSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Make the ERP's stock balances equal Tally's, item by item and godown by
 * godown.
 *
 * WHY THIS EXISTS AND WHY IT IS NOT THE OPENING-STOCK SERVICE. The owner, on
 * seeing the approval desk report four materials issued beyond the recorded
 * balance (06-Aug): "we can sync the live stock from the tally and start
 * consume from here". That is the right instinct — Tally is this factory's book
 * of record for stock and the ERP is starting mid-stream — but it is NOT what
 * applying another snapshot does.
 *
 *   TallyOpeningStockService RECEIPTS Tally's closing balance. It ADDS.
 *
 *   This service MATCHES it. It reads what the ERP currently holds, works out
 *   the difference, and moves only the difference.
 *
 * To be exact about the hazard, because that service is not careless: it
 * already refuses a repeat of the same date — its ledger reference is "Tally
 * opening <as_of>". What it cannot refuse is a LATER snapshot, and a later
 * snapshot is precisely what "sync the live stock" means once it is done more
 * than once. September's closing balance receipted on top of August's is twice
 * the stock, with no error anywhere: no red screen, just a balance that is too
 * big until somebody counts the floor.
 *
 * So this one is safe to run on every fresh snapshot, which is the only way
 * "sync the stock" can be a monthly habit rather than a one-off.
 *
 * ======================== WHAT IT DOES TO THE LEDGER ========================
 *
 * A receipt when Tally holds more than the ERP, an issue when it holds less.
 * Deliberately the SAME two audited movements every other part of the system
 * uses, rather than a new "adjustment" type: a reconcile is a real correction
 * to a real balance, it must show up in the ledger a person reads, and adding a
 * movement type would mean auditing every report that counts them.
 *
 * The issue side passes allowNegative, and has to: Tally may hold less than the
 * ERP thinks, and refusing the correction would leave the two systems
 * disagreeing precisely where somebody asked them to agree.
 *
 * ============================ WHAT IT WILL NOT DO ============================
 *
 * Only lines the preview already judged importable — an exact item this ERP
 * holds, a godown it knows, in the bound company. An unmatched line is reported
 * and skipped, never guessed at.
 *
 * A negative closing balance in Tally is skipped and reported, exactly as the
 * opening-stock service skips it: Tally showing a negative is a question for
 * the accountant, not a figure to copy into a second system.
 *
 * ONE SNAPSHOT IS RECONCILED ONCE. Not because a second run would double
 * anything — it would compute a difference of zero and do nothing — but because
 * a snapshot is a photograph of a moment, and re-matching to yesterday's
 * photograph would undo a day of real production. Guarded on the ledger's own
 * reference as well as the snapshot's status, for the reason the opening-stock
 * service states: status is a claim this table makes about itself, the ledger is
 * the fact.
 */
class TallyStockReconcileService
{
    public function __construct(
        private readonly StockMovementService $stock,
    ) {}

    /** The reference every movement of one reconcile carries. */
    public function referenceFor(TallyStockSnapshot $snapshot): string
    {
        return "TALLY-RECONCILE-{$snapshot->id}";
    }

    /**
     * @return array{
     *     matched: int, received: int, issued: int, already_equal: int,
     *     skipped: list<string>, changes: list<array{item: string, warehouse: string,
     *     erp: string, tally: string, difference: string}>, reference: string,
     * }
     */
    public function apply(TallyStockSnapshot $snapshot, ?int $userId, bool $write = true): array
    {
        $reference = $this->referenceFor($snapshot);

        return DB::transaction(function () use ($snapshot, $userId, $write, $reference) {
            /** @var TallyStockSnapshot $locked */
            $locked = TallyStockSnapshot::query()->lockForUpdate()->findOrFail($snapshot->id);

            // GUARD ON THE LEDGER, not only on the column. A reconcile that ran
            // and failed to update its own status has still moved stock.
            if ($write && StockMovement::query()->where('reference', $reference)->exists()) {
                throw ValidationException::withMessages([
                    'snapshot' => "The stock ledger already carries movements referenced '{$reference}'. "
                        .'This snapshot has been reconciled. Take a fresh Tally stock summary and reconcile that instead — '
                        .'re-matching an old snapshot would undo the production since.',
                ]);
            }

            $received = 0;
            $issued = 0;
            $equal = 0;
            $skipped = [];
            $changes = [];

            foreach ($locked->lines ?? [] as $line) {
                // Same label shape as TallyOpeningStockService, and the same
                // keys: the preview writes tally_item_name and godown.
                $label = ($line['tally_item_name'] ?? '?').' @ '.($line['godown'] ?? 'no godown');

                if (($line['importable'] ?? false) !== true) {
                    $skipped[] = $label.' — '.implode('; ', $line['problems'] ?? ['not importable']);

                    continue;
                }

                $itemId = $line['erp_item_id'] ?? null;
                $warehouseId = $line['erp_warehouse_id'] ?? null;

                if ($itemId === null || $warehouseId === null) {
                    $skipped[] = $label.' — marked importable but carries no resolved item or warehouse.';

                    continue;
                }

                $tally = $this->decimal($line['closing_quantity'] ?? '0');

                // A NEGATIVE IN TALLY IS NOT A TARGET. Copying it over would
                // reproduce somebody else's unanswered question as our own
                // balance, and the whole point of matching Tally is that Tally
                // is the figure people trust.
                if (bccomp($tally, '0', 4) === -1) {
                    $skipped[] = $label." — Tally itself shows {$tally}, a negative balance. Not matched.";

                    continue;
                }

                $erp = $this->decimal(
                    StockBalance::query()
                        ->where('item_id', $itemId)
                        ->where('warehouse_id', $warehouseId)
                        ->lockForUpdate()
                        ->value('quantity') ?? '0'
                );

                $difference = bcsub($tally, $erp, 4);

                if (bccomp($difference, '0', 4) === 0) {
                    $equal++;

                    continue;
                }

                $changes[] = [
                    'item' => (string) ($line['erp_item_name'] ?? $line['tally_item_name'] ?? ''),
                    'warehouse' => (string) ($line['erp_warehouse_name'] ?? $line['godown'] ?? ''),
                    'erp' => $erp,
                    'tally' => $tally,
                    'difference' => $difference,
                ];

                if (! $write) {
                    bccomp($difference, '0', 4) === 1 ? $received++ : $issued++;

                    continue;
                }

                if (bccomp($difference, '0', 4) === 1) {
                    $this->stock->recordReceipt(
                        itemId: (int) $itemId,
                        warehouseId: (int) $warehouseId,
                        quantity: $difference,
                        // Tally's own closing rate, like the opening-stock
                        // service. An unpriced line comes in at zero rather
                        // than at an invented figure — a visible missing cost
                        // is worth more to finance than a confident wrong one.
                        unitCost: (string) ($line['closing_rate'] ?? '0'),
                        reference: $reference,
                        movementDate: $locked->as_of->toDateString(),
                        notes: "Matched to Tally: ERP held {$erp}, Tally holds {$tally}.",
                        createdBy: $userId,
                    );

                    $received++;

                    continue;
                }

                $this->stock->recordIssue(
                    itemId: (int) $itemId,
                    warehouseId: (int) $warehouseId,
                    // bcsub gives a signed string; the movement's quantity is a
                    // magnitude and its TYPE carries the direction.
                    quantity: ltrim($difference, '-'),
                    reference: $reference,
                    movementDate: $locked->as_of->toDateString(),
                    notes: "Matched to Tally: ERP held {$erp}, Tally holds {$tally}.",
                    createdBy: $userId,
                    // Tally may hold less than this ledger believes. Refusing
                    // here would leave the two systems disagreeing at the exact
                    // point somebody asked them to agree.
                    allowNegative: true,
                );

                $issued++;
            }

            if ($write) {
                $locked->forceFill([
                    'status' => TallyStockSnapshot::STATUS_APPLIED,
                    'applied_at' => now(),
                    'applied_by' => $userId,
                    'applied_line_count' => $received + $issued,
                ])->save();
            }

            return [
                'matched' => $received + $issued,
                'received' => $received,
                'issued' => $issued,
                'already_equal' => $equal,
                'skipped' => $skipped,
                'changes' => $changes,
                'reference' => $reference,
            ];
        });
    }

    /**
     * A quantity as an exact 4dp string.
     *
     * Through bcadd rather than a cast: SQLite hands a decimal column back as
     * '5' where MySQL gives '5.0000', and every comparison here is a bccomp
     * that would otherwise change answer with the driver.
     */
    private function decimal(mixed $value): string
    {
        $raw = trim((string) $value);

        if ($raw === '' || ! is_numeric($raw)) {
            return '0.0000';
        }

        return bcadd($raw, '0', 4);
    }
}
