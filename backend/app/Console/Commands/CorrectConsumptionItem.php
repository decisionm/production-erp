<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StoreIssueLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\ShiftProductionEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DEC-20260903-001 — the batch consumption booked against one item is
 * corrected to another by NEW, append-only movements that name the batch
 * they correct. On live: item 592 "Pet Resin" (demo data, DEC-20260805-002)
 * to item 606 "Relpet", roughly 15.5 t over 115 batches, 04-Aug to 02-Sep.
 *
 * WHAT A CORRECTION ROW IS. For every ledger row a batch wrote against the
 * from-item, two rows are posted, dated and located EXACTLY as the row they
 * correct:
 *
 *   original ISSUE  "SPE #n" (purpose consumption)
 *     → RECEIPT of the from-item, same kg, same warehouse, same date
 *     → ISSUE   of the to-item,   same kg, same warehouse, same date
 *   original RECEIPT "SPE #n amended" (an amendment's reversal, unstamped)
 *     → ISSUE   of the from-item
 *     → RECEIPT of the to-item
 *
 * so the NET per batch moves from one item to the other without a gram
 * changing. Both rows carry purpose `consumption` — they ARE consumption,
 * negative and positive — so "kg consumed of X" answered by purpose stays
 * right for both items without a new purpose to teach every report. They
 * are found again by their reference, "Correction of SPE #n
 * (DEC-20260903-001)", and by the note naming the movement id corrected,
 * which is what makes a second run find nothing left to do.
 *
 * WHICH WAREHOUSE THE TO-ITEM'S CONSUMPTION LANDS ON: the same one the
 * original row used (on live, the Store godown), not Production/WIP. The
 * correction changes the IDENTITY of a posted consumption and nothing else;
 * it must leave every location's total exactly as the accountant will see
 * it after posting the statement. Tally has one godown, the Stock Journals
 * already posted took the resin out of that godown, and the accountant's
 * correcting journal will move the same kilograms between the two items
 * under that godown. Booking the to-item leg on Production/WIP instead would
 * (a) push WIP deeply negative for every batch before the first Store Issue
 * ever existed, and (b) split the ERP's per-item total across a row that
 * Tally reconciliation folds into the godown only when WIP aliases to it
 * (TallyStockReconcileService), which nobody has proven on live. The 1,000
 * kg the Store DID issue to the floor on 18-Aug is not moved by this command;
 * the statement lists it, standing, for the lead to close.
 *
 * NEVER: the originals are not edited or deleted (the model refuses anyway),
 * Tally is not touched (tally_sync_entries untouched — the accountant posts
 * the correcting journal from the statement, DEC-20260903-001), and no
 * other item is corrected: the statement LISTS the other negative balances
 * in the same warehouses (Master Batch Amber on live) with the Store's open
 * handovers beside them, because the decision says examine them together —
 * and examining is a person's job, not this command's guess.
 *
 * The to-item's issue is posted with allowNegative: the Store's ERP balance
 * of the to-item may not carry the kilograms (the demo item did), and the
 * whole point is to make that visible on the right item.
 */
class CorrectConsumptionItem extends Command
{
    protected $signature = 'inventory:correct-consumption-item
        {--from-item= : SKU or exact name of the item the batches wrongly consumed}
        {--to-item= : SKU or exact name of the item they physically ran on}
        {--write : Post the correction movements (default is a dry run that prints the statement)}';

    protected $description = 'DEC-20260903-001: print the correction statement (dry run) or post append-only movements moving batch consumption from one item to another';

    public const REFERENCE_SUFFIX = '(DEC-20260903-001)';

    public function __construct(
        private readonly StockMovementService $stock,
        private readonly ProductionWipLocationResolver $wip,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = $this->resolveItem((string) $this->option('from-item'), '--from-item');
        $to = $this->resolveItem((string) $this->option('to-item'), '--to-item');

        if ($from === null || $to === null) {
            return self::FAILURE;
        }

        if ($from->id === $to->id) {
            $this->error('--from-item and --to-item name the same item. Nothing read.');

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $originals = $this->originals($from);
        $corrected = $this->alreadyCorrected($from, $to);
        $pending = $originals->reject(fn (StockMovement $m) => in_array((int) $m->id, $corrected, true))->values();

        $this->info(sprintf(
            'Correction statement — consumption booked against #%d %s (%s), corrected to #%d %s (%s)',
            $from->id, $from->sku, $from->name, $to->id, $to->sku, $to->name,
        ));

        $byBatch = $this->statement($pending);
        $netTotal = $byBatch->reduce(fn (string $carry, array $row) => bcadd($carry, $row['net'], 4), '0.0000');

        $this->table(['count', 'value'], [
            ['ledger rows carrying a batch reference', $originals->count()],
            ['already corrected by a previous run', count($corrected)],
            [$write ? 'rows corrected now' : 'rows to correct', $pending->count()],
            ['batches', $byBatch->count()],
            ['net kg', $netTotal],
        ]);
        $this->line('batches '.$byBatch->count());
        $this->line('net kg '.$netTotal);

        if ($byBatch->isNotEmpty()) {
            $this->table(
                ['batch', 'date', 'shift', 'machine', 'warehouse', 'rows', 'net kg'],
                $byBatch->map(fn (array $row) => [
                    'SPE #'.$row['spe'], $row['date'], $row['shift'], $row['machine'], $row['warehouse'], $row['rows'], $row['net'],
                ])->all(),
            );
        }

        $this->standingSection($to);
        $this->otherNegativesSection($from, $to, $pending);

        if ($pending->isEmpty()) {
            $this->newLine();
            $this->info('Every batch row carrying '.$from->name.' already has its correction posted — nothing left to correct.');

            return self::SUCCESS;
        }

        if (! $write) {
            $this->newLine();
            $this->line('DRY RUN — nothing posted. Hand this statement to the accountant; re-run with --write to post the correction movements.');

            return self::SUCCESS;
        }

        $posted = 0;

        DB::transaction(function () use ($pending, $from, $to, &$posted): void {
            foreach ($pending as $original) {
                $posted += $this->correct($original, $from, $to);
            }
        });

        $this->newLine();
        $this->info(sprintf(
            'POSTED %d correction movement(s) for %d original row(s) across %d batch(es): %s kg net moved from %s to %s. Originals untouched; Tally untouched — the accountant posts from the statement above.',
            $posted, $pending->count(), $byBatch->count(), $netTotal, $from->name, $to->name,
        ));

        foreach ($this->warehousesOf($pending) as $warehouse) {
            $this->line(sprintf(
                '  %s now: %s %s · %s %s',
                $warehouse->name,
                $from->name, $this->balance($from, $warehouse),
                $to->name, $this->balance($to, $warehouse),
            ));
        }

        return self::SUCCESS;
    }

    private function resolveItem(string $key, string $option): ?Item
    {
        $key = trim($key);

        if ($key === '') {
            $this->error("{$option} is required: the SKU or exact name of the item.");

            return null;
        }

        $matches = Item::withTrashed()
            ->where(fn ($q) => $q->where('sku', $key)->orWhere('name', $key))
            ->orderBy('id')
            ->get();

        if ($matches->count() === 0) {
            $this->error("{$option}='{$key}' matches no item (by SKU or exact name). Nothing read.");

            return null;
        }

        if ($matches->count() > 1) {
            $this->error(sprintf(
                "%s='%s' matches %d items (%s). Name one by its SKU. Nothing read.",
                $option, $key, $matches->count(),
                $matches->map(fn (Item $i) => "#{$i->id} {$i->sku}")->implode(', '),
            ));

            return null;
        }

        return $matches->first();
    }

    /**
     * Every ledger row a batch wrote against the item: the consumption
     * issues "SPE #n" and the amendment reversals "SPE #n amended". Nothing
     * else — a correction row, an opening, a reconcile, a store issue are
     * not batch consumption and are never mirrored.
     *
     * @return Collection<int, StockMovement>
     */
    private function originals(Item $from): Collection
    {
        return StockMovement::query()
            ->where('item_id', $from->id)
            ->where('reference', 'like', 'SPE #%')
            ->orderBy('id')
            ->get()
            ->filter(function (StockMovement $m): bool {
                $reference = (string) $m->reference;

                if ($m->type === StockMovementType::Issue) {
                    return preg_match('/^SPE #\d+$/', $reference) === 1
                        && $m->purpose === StockMovementPurpose::Consumption;
                }

                return $m->type === StockMovementType::Receipt
                    && preg_match('/^SPE #\d+ amended$/', $reference) === 1;
            })
            ->values();
    }

    /**
     * The ids of the original rows a previous run already corrected — read
     * back from the note every correction row carries.
     *
     * @return list<int>
     */
    private function alreadyCorrected(Item $from, Item $to): array
    {
        $ids = [];

        $rows = StockMovement::query()
            ->whereIn('item_id', [$from->id, $to->id])
            ->where('reference', 'like', 'Correction of SPE #% '.self::REFERENCE_SUFFIX)
            ->get(['notes']);

        foreach ($rows as $row) {
            if (preg_match('/^Corrects stock movement #(\d+):/', (string) $row->notes, $m) === 1) {
                $ids[] = (int) $m[1];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The statement, one line per batch: date, shift, machine, warehouse,
     * the rows, and the NET kg (issues minus amendment reversals).
     *
     * @param  Collection<int, StockMovement>  $pending
     * @return Collection<int, array{spe: int, date: string, shift: string, machine: string, warehouse: string, rows: int, net: string}>
     */
    private function statement(Collection $pending): Collection
    {
        $grouped = $pending->groupBy(fn (StockMovement $m) => (int) preg_replace('/\D+/', '', (string) $m->reference));
        $entries = ShiftProductionEntry::query()
            ->with(['shift', 'workCenter'])
            ->whereIn('id', $grouped->keys())
            ->get()
            ->keyBy('id');
        $warehouses = Warehouse::withTrashed()->whereIn('id', $pending->pluck('warehouse_id')->unique())->get()->keyBy('id');

        return $grouped->map(function (Collection $rows, int $spe) use ($entries, $warehouses): array {
            $net = '0.0000';

            foreach ($rows as $row) {
                $sign = $row->type === StockMovementType::Issue ? '1' : '-1';
                $net = bcadd($net, bcmul((string) $row->quantity, $sign, 4), 4);
            }

            $entry = $entries->get($spe);

            return [
                'spe' => $spe,
                'date' => $entry?->production_date?->format('Y-m-d') ?? '?',
                'shift' => (string) ($entry?->shift?->name ?? '?'),
                'machine' => (string) ($entry?->workCenter?->code ?? '?'),
                'warehouse' => $rows->pluck('warehouse_id')->unique()->map(fn ($id) => (string) ($warehouses->get($id)?->name ?? "#{$id}"))->implode(', '),
                'rows' => $rows->count(),
                'net' => $net,
            ];
        })->sortBy('spe')->values();
    }

    /** Post the two mirrors of one original row; returns how many were posted (2). */
    private function correct(StockMovement $original, Item $from, Item $to): int
    {
        $spe = (int) preg_replace('/\D+/', '', (string) $original->reference);
        $reference = "Correction of SPE #{$spe} ".self::REFERENCE_SUFFIX;
        $notes = sprintf(
            'Corrects stock movement #%d: %s → %s, per DEC-20260903-001; the original row is untouched.',
            $original->id, $from->name, $to->name,
        );
        $quantity = bcadd((string) $original->quantity, '0', 4);
        $warehouseId = (int) $original->warehouse_id;
        $movementDate = $original->movement_date->format('Y-m-d H:i:s');
        $unitCost = $original->unit_cost === null ? null : bcadd((string) $original->unit_cost, '0', 4);
        $short = null;

        if ($original->type === StockMovementType::Issue) {
            // Un-consume the from-item, consume the to-item.
            $this->stock->recordReceipt(
                itemId: $from->id,
                warehouseId: $warehouseId,
                quantity: $quantity,
                unitCost: $unitCost ?? $this->stock->currentAverageCost($from->id, $warehouseId),
                reference: $reference,
                movementDate: $movementDate,
                notes: $notes,
                purpose: StockMovementPurpose::Consumption,
            );
            $this->stock->recordIssue(
                itemId: $to->id,
                warehouseId: $warehouseId,
                quantity: $quantity,
                reference: $reference,
                movementDate: $movementDate,
                notes: $notes,
                allowNegative: true,
                shortfallKg: $short,
                purpose: StockMovementPurpose::Consumption,
            );

            return 2;
        }

        // An amendment's reversal: the from-item was handed back; mirror that
        // as the to-item handed back, and take the from-item's hand-back away.
        $this->stock->recordIssue(
            itemId: $from->id,
            warehouseId: $warehouseId,
            quantity: $quantity,
            reference: $reference,
            movementDate: $movementDate,
            notes: $notes,
            allowNegative: true,
            shortfallKg: $short,
            purpose: StockMovementPurpose::Consumption,
        );
        $this->stock->recordReceipt(
            itemId: $to->id,
            warehouseId: $warehouseId,
            quantity: $quantity,
            unitCost: $unitCost ?? $this->stock->currentAverageCost($to->id, $warehouseId),
            reference: $reference,
            movementDate: $movementDate,
            notes: $notes,
            purpose: StockMovementPurpose::Consumption,
        );

        return 2;
    }

    /** What the Store has issued of the to-item into Production/WIP and not had back — listed, never moved. */
    private function standingSection(Item $to): void
    {
        $wipId = $this->wip->warehouseId();

        $this->newLine();
        $this->line("Standing in Production/WIP — {$to->name} the Store issued and has not had back (not moved by this command; the lead closes it):");

        if ($wipId === null) {
            $this->line('  (no Production/WIP location is configured)');

            return;
        }

        $lines = StoreIssueLine::query()
            ->with('storeIssue')
            ->where('item_id', $to->id)
            ->where('to_warehouse_id', $wipId)
            ->whereColumn('quantity_issued', '>', 'quantity_returned')
            ->whereHas('storeIssue', fn ($q) => $q->whereIn('status', [StoreIssueStatus::Issued->value, StoreIssueStatus::PartiallyReturned->value]))
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            $this->line('  none');

            return;
        }

        foreach ($lines as $line) {
            $this->line(sprintf(
                '  %s · issued %s · returned %s · standing %s',
                $line->storeIssue?->issue_number ?? "issue #{$line->store_issue_id}",
                bcadd((string) $line->quantity_issued, '0', 4),
                bcadd((string) $line->quantity_returned, '0', 4),
                bcsub((string) $line->quantity_issued, (string) $line->quantity_returned, 4),
            ));
        }
    }

    /**
     * The other items driven negative in the same warehouses — the
     * Master Batch Amber case on live — with the Store's open handovers
     * beside them. Listed, not corrected: DEC-20260903-001 says they are
     * examined together, and examining is a person's job.
     *
     * @param  Collection<int, StockMovement>  $pending
     */
    private function otherNegativesSection(Item $from, Item $to, Collection $pending): void
    {
        $warehouseIds = $pending->pluck('warehouse_id')->unique()->values();

        if ($warehouseIds->isEmpty()) {
            $warehouseIds = StockMovement::query()->where('item_id', $from->id)->distinct()->pluck('warehouse_id');
        }

        $negatives = StockBalance::query()
            ->with('item')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereNotIn('item_id', [$from->id, $to->id])
            ->where('quantity', '<', 0)
            ->orderBy('quantity')
            ->get();

        $this->newLine();
        $this->line('Other negative balances in the same warehouse(s) — listed, not corrected (DEC-20260903-001: examined together, by a person):');

        if ($negatives->isEmpty()) {
            $this->line('  none');

            return;
        }

        $wipId = $this->wip->warehouseId();

        foreach ($negatives as $balance) {
            $name = $balance->item?->name ?? "item #{$balance->item_id}";
            $this->line(sprintf('  #%d %s · %s', $balance->item_id, $name, bcadd((string) $balance->quantity, '0', 4)));

            if ($wipId === null) {
                continue;
            }

            // THIS item's open handovers, not every item's. The section
            // exists to put the Store's side of ONE negative balance next to
            // it — "the Store issued 20 kg of this and the batches consumed
            // something else" is the finding; a list of every open issue
            // under every negative row is noise a person cannot act on.
            $open = StoreIssueLine::query()
                ->with('storeIssue')
                ->where('to_warehouse_id', $wipId)
                ->where('item_id', $balance->item_id)
                ->whereColumn('quantity_issued', '>', 'quantity_returned')
                ->whereHas('storeIssue', fn ($q) => $q->whereIn('status', [StoreIssueStatus::Issued->value, StoreIssueStatus::PartiallyReturned->value]))
                ->orderBy('id')
                ->get();

            foreach ($open as $line) {
                $this->line(sprintf(
                    '      Store issued to Production, open: %s · standing %s',
                    $line->storeIssue?->issue_number ?? "issue #{$line->store_issue_id}",
                    bcsub((string) $line->quantity_issued, (string) $line->quantity_returned, 4),
                ));
            }
        }
    }

    /** @return Collection<int, Warehouse> */
    private function warehousesOf(Collection $pending): Collection
    {
        return Warehouse::withTrashed()->whereIn('id', $pending->pluck('warehouse_id')->unique())->orderBy('id')->get();
    }

    private function balance(Item $item, Warehouse $warehouse): string
    {
        return bcadd((string) StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity'), '0', 4);
    }
}
