<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Inventory\Exceptions\IncomingQcHoldException;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\QualityHoldLocationResolver;
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
 * ================= MATERIAL STANDING IN PRODUCTION IS NOT DRIFT =============
 *
 * Since DEC-20260817-001 a store issue moves kilograms out of the store and
 * into Production/WIP, and NO Tally voucher is raised for that move — the
 * books only ever see the CONSUMPTION, at batch approval. So while an issue
 * is open the ERP's STORE balance has fallen and Tally's godown balance has
 * not, and a naive comparison would call the open issue a difference and
 * receipt it back into the store: a second copy of material that is standing
 * on the factory floor, and an accountant sent after a discrepancy that is
 * not one.
 *
 * So the ERP side of a godown line is the store's balance PLUS whatever is
 * standing in Production/WIP under that same godown (TallyGodownResolver's
 * alias, asked through ProductionWipLocationResolver so this service, the
 * voucher payload and the preview cannot disagree). Two rules keep that from
 * becoming its own quiet error:
 *
 *   - it is folded into EXACTLY ONE line — the single importable line for
 *     that item at the godown WIP posts under. Folded into two, it would
 *     cancel a real difference twice.
 *   - where it can be folded into none — Production/WIP posts under no
 *     godown Tally knows, or the snapshot carries no single line to carry it
 *     — the item is SKIPPED with the kilograms named, never matched and
 *     never reported as drift. Nothing is guessed and no stock moves.
 *
 * Either way the standing material is reported as its own named block
 * (`production_wip`), so it is visible as what it is rather than absorbed.
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
        // WHERE MATERIAL ISSUED TO PRODUCTION IS STANDING (DEC-20260817-001)
        // and which godown it posts under. Read only, through Inventory's own
        // service — never its warehouse rows.
        private readonly ProductionWipLocationResolver $wip,
        // AND WHERE MATERIAL RETURNED DAMAGED IS WAITING (DEC-20260901-003).
        // A SECOND internal location of the same physical godown, and the
        // same trap as Production/WIP: Tally never saw that material leave
        // the store, so a naive comparison reads the hold as store drift and
        // receipts a second copy of it back in. Read only, through
        // Inventory's own service.
        private readonly QualityHoldLocationResolver $qualityHold,
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
     *     erp: string, production_wip: string, erp_including_wip: string, tally: string,
     *     difference: string}>,
     *     production_wip: array{warehouse: ?string, godown: ?string, note: string,
     *     lines: list<array{item_id: int, item: ?string, quantity: string, counted_with_godown: bool}>},
     *     reference: string,
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

            // ---- what is standing in Production/WIP, and where it posts ----
            $wipWarehouse = $this->wip->warehouse();
            $wipGodown = $wipWarehouse === null ? null : $this->wip->tallyGodown();

            // WIP carrying Tally identity of its OWN is already a godown in
            // the snapshot's own right — its line reconciles like any other
            // and nothing is folded anywhere.
            $wipIsItsOwnGodown = $wipWarehouse !== null && $wipGodown !== null && $wipGodown->id === $wipWarehouse->id;

            // WHAT IS WAITING FOR QUALITY, read the same way and used
            // differently — see the skip below for why it is not folded.
            $qualityHoldWarehouse = $this->qualityHold->warehouse();
            $held = $qualityHoldWarehouse === null
                ? []
                : $this->standingInProduction($qualityHoldWarehouse->id);

            $standing = $wipWarehouse === null || $wipIsItsOwnGodown
                ? []
                : $this->standingInProduction($wipWarehouse->id);

            // The one line each item's standing kilograms may be folded into:
            // an importable line for that item at the godown WIP posts under.
            // Exactly one, or none — see the class docblock.
            $foldWarehouseId = $wipGodown !== null && ! $wipIsItsOwnGodown ? $wipGodown->id : null;
            $foldable = $this->foldableItems($locked->lines ?? [], $foldWarehouseId);
            $counted = [];

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

                // MATERIAL WAITING FOR QUALITY IS NOT DRIFT EITHER, and it is
                // SKIPPED rather than folded (DEC-20260901-003).
                //
                // Folding is what Production/WIP gets, and it is the better
                // answer — but it is built for exactly ONE extra location:
                // one `standing` map, one godown, one foldable line, one
                // report block. Folding a second location correctly means
                // reworking all four, and getting it wrong here does not
                // fail loudly — it silently receipts a second copy of real
                // material into the store, which is the exact bug the WIP
                // fold exists to prevent.
                //
                // So this takes the direction this service already states for
                // material it cannot place: report it with the quantity
                // named, match nothing, move no stock. An item with material
                // in quality hold is not reconciled until the hold is
                // dispositioned, which for a factory that scraps or releases
                // within the day is a short wait. Folding it properly is a
                // deliberate follow-up, not something to reach by widening a
                // condition.
                $inQualityHold = $held[(int) $itemId] ?? '0.0000';

                if (bccomp($inQualityHold, '0', 4) !== 0) {
                    $skipped[] = $label.' — '.$inQualityHold.' is waiting for Quality in "'
                        .($qualityHoldWarehouse?->name ?? '?').'" after coming back from production damaged. '
                        .'Not matched: that difference is material the factory still holds, not store drift.';

                    continue;
                }

                // MATERIAL ON THE FLOOR IS NOT DRIFT. Whatever this item has
                // standing in Production/WIP under this godown belongs on the
                // ERP side of the comparison — Tally never saw it leave.
                $inProduction = $standing[(int) $itemId] ?? '0.0000';
                $wipQuantity = '0.0000';

                if (bccomp($inProduction, '0', 4) !== 0) {
                    if (($foldable[(int) $itemId] ?? 0) !== 1) {
                        // Nowhere to put it — and a difference whose cause is
                        // known material is never corrected as if it were not.
                        $skipped[] = $label.' — '.$inProduction.' is standing in Production/WIP ("'
                            .($wipWarehouse?->name ?? '?').'"), which posts under no godown this snapshot carries a '
                            .'single line for. Not matched: that difference is material on the factory floor, not '
                            .'store drift.';

                        continue;
                    }

                    if ((int) $warehouseId === $foldWarehouseId) {
                        $wipQuantity = $inProduction;
                        $counted[(int) $itemId] = true;
                    }
                }

                $erpIncludingWip = bcadd($erp, $wipQuantity, 4);
                $difference = bcsub($tally, $erpIncludingWip, 4);

                if (bccomp($difference, '0', 4) === 0) {
                    $equal++;

                    continue;
                }

                $changes[] = [
                    'item' => (string) ($line['erp_item_name'] ?? $line['tally_item_name'] ?? ''),
                    'warehouse' => (string) ($line['erp_warehouse_name'] ?? $line['godown'] ?? ''),
                    'erp' => $erp,
                    'production_wip' => $wipQuantity,
                    'erp_including_wip' => $erpIncludingWip,
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
                        notes: $this->movementNote($erp, $wipQuantity, $tally),
                        createdBy: $userId,
                        purpose: StockMovementPurpose::Reconcile,
                    );

                    $received++;

                    continue;
                }

                try {
                    $this->stock->recordIssue(
                        itemId: (int) $itemId,
                        warehouseId: (int) $warehouseId,
                        // bcsub gives a signed string; the movement's quantity is a
                        // magnitude and its TYPE carries the direction.
                        quantity: ltrim($difference, '-'),
                        reference: $reference,
                        movementDate: $locked->as_of->toDateString(),
                        notes: $this->movementNote($erp, $wipQuantity, $tally),
                        createdBy: $userId,
                        // Tally may hold less than this ledger believes. Refusing
                        // here would leave the two systems disagreeing at the exact
                        // point somebody asked them to agree.
                        allowNegative: true,
                        purpose: StockMovementPurpose::Reconcile,
                    );
                } catch (IncomingQcHoldException $e) {
                    // MATERIAL WAITING FOR INCOMING QC IS NOT DRIFT EITHER.
                    // Writing this line down would take held kilograms off
                    // the balance while the bags holding them stay
                    // `waiting_qc` — the hold would then exceed the balance
                    // and freeze the item for everybody, over a difference
                    // that is very likely the hold itself.
                    //
                    // Reported as a skipped line rather than thrown: one held
                    // item must not roll back every other line an operator
                    // just matched. Same shape as every other skip reason,
                    // and it names the way out (quality releases the arrival).
                    $skipped[] = $label.' — '.$e->getMessage();

                    continue;
                }

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
                'production_wip' => $this->standingReport($wipWarehouse?->name, $wipGodown?->name, $standing, $counted),
                'reference' => $reference,
            ];
        });
    }

    /**
     * What each item has standing in Production/WIP right now, as exact 4dp
     * strings, zero balances dropped.
     *
     * Read inside the same transaction and locked like every other balance
     * this service reads: the figure it is compared against must not move
     * between the two reads.
     *
     * @return array<int, string>
     */
    private function standingInProduction(int $warehouseId): array
    {
        $standing = [];

        $rows = StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->get(['item_id', 'quantity']);

        foreach ($rows as $row) {
            $quantity = $this->decimal($row->quantity);

            if (bccomp($quantity, '0', 4) !== 0) {
                $standing[(int) $row->item_id] = $quantity;
            }
        }

        return $standing;
    }

    /**
     * How many importable snapshot lines each item has at the godown
     * Production/WIP posts under. Only a count of exactly ONE lets the
     * standing kilograms be folded in: none means there is no line to carry
     * them, several means there is no way to choose which — and both are
     * reported rather than guessed.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, int>
     */
    private function foldableItems(array $lines, ?int $foldWarehouseId): array
    {
        if ($foldWarehouseId === null) {
            return [];
        }

        $counts = [];

        foreach ($lines as $line) {
            if (($line['importable'] ?? false) !== true) {
                continue;
            }

            if ((int) ($line['erp_warehouse_id'] ?? 0) !== $foldWarehouseId) {
                continue;
            }

            $itemId = (int) ($line['erp_item_id'] ?? 0);

            if ($itemId !== 0) {
                $counts[$itemId] = ($counts[$itemId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The standing-material block, reported whether it was counted or not —
     * the whole point is that this material is never invisible and never
     * mistaken for drift.
     *
     * @param  array<int, string>  $standing
     * @param  array<int, bool>  $counted
     * @return array{warehouse: ?string, godown: ?string, note: string,
     *     lines: list<array{item_id: int, item: ?string, quantity: string, counted_with_godown: bool}>}
     */
    private function standingReport(?string $warehouse, ?string $godown, array $standing, array $counted): array
    {
        ksort($standing);

        $names = $standing === []
            ? []
            : Item::query()->withTrashed()->whereIn('id', array_keys($standing))->pluck('name', 'id')->all();

        $lines = [];
        foreach ($standing as $itemId => $quantity) {
            $lines[] = [
                'item_id' => $itemId,
                'item' => $names[$itemId] ?? null,
                'quantity' => $quantity,
                'counted_with_godown' => $counted[$itemId] ?? false,
            ];
        }

        if ($warehouse === null) {
            $note = 'No Production/WIP location is configured here, so nothing was counted as standing with '
                .'production.';
        } elseif ($lines === []) {
            $note = "Nothing is standing in Production/WIP (\"{$warehouse}\") — every godown line was compared on "
                .'the store balance alone.';
        } elseif ($godown === null) {
            $note = "Production/WIP (\"{$warehouse}\") posts under no godown Tally knows, so the material standing "
                .'there could not be matched against any godown line. It is listed here and those items were left '
                .'alone: this is material on the factory floor, not store drift.';
        } else {
            $note = 'Material issued to production and not yet consumed is counted with the godown it posts under '
                ."(\"{$godown}\"), never reported as store drift. These kilograms are standing in Production/WIP "
                ."(\"{$warehouse}\") and were included on the ERP side.";
        }

        return [
            'warehouse' => $warehouse,
            'godown' => $godown,
            'note' => $note,
            'lines' => $lines,
        ];
    }

    /**
     * What a reconcile movement says about itself. It names the standing
     * material explicitly when there is any — a person reading the ledger
     * later has to be able to see that the figure was not the store balance
     * alone.
     */
    private function movementNote(string $erp, string $wipQuantity, string $tally): string
    {
        if (bccomp($wipQuantity, '0', 4) === 0) {
            return "Matched to Tally: ERP held {$erp}, Tally holds {$tally}.";
        }

        return "Matched to Tally: ERP held {$erp} in the store plus {$wipQuantity} standing in Production/WIP, "
            ."Tally holds {$tally}.";
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
