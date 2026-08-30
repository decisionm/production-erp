<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * inventory:preview-warehouse-recovery — READ-ONLY. Lists the stock standing
 * in warehouses a person can no longer pick, says which single Store it would
 * move to, and classifies every row by the evidence in its own movement
 * history. It moves nothing, and it is not a gate.
 *
 * WHY THIS EXISTS. The factory is one physical Godown/Store, and the pickers
 * filter on `is_active`, so a balance sitting in a retired warehouse is
 * visible on the Stock page and reachable by no stock action at all. This
 * command is the per-row evidence DEC-20260817-001 demands before any merge,
 * deactivation or move: "no merge, deletion or deactivation of them happens
 * until dependency and stock history have been proven for each row."
 *
 * PRODUCTION/WIP IS NOT IN SCOPE, and that is the load-bearing exclusion.
 * DEC-20260817-001 makes Production/WIP the inventory location holding
 * material issued to production but not yet consumed — "which is what makes
 * 'issued to production' a real stock state distinct from both store stock
 * and consumption". It is retired ONLY in the sense that no picker offers it;
 * ProductionWipLocationResolver deliberately resolves it regardless of
 * `is_active` for exactly that reason. Folding its kilograms into the Store
 * would not recover stranded stock — it would destroy the one state that
 * tells the floor apart from the store. So it is skipped, and skipped
 * loudly, through the SAME resolver the rest of the system asks, so this
 * command can never disagree with the issue path about which row is WIP.
 *
 * THREE BUCKETS, READ OFF THE LEDGER, NEVER GUESSED. The rows are not one
 * population and must never be moved as one:
 *
 *   REAL   — the movements behind it are ordinary factory documents. This is
 *            stock the factory has, recorded in a place it can no longer
 *            reach. Recovering it is the point of the exercise.
 *   PILOT  — every movement behind it is a rehearsal opening balance. The
 *            factory never received this material.
 *   TEST   — every movement behind it is a wiring check or a demo document.
 *   MIXED  — both kinds of movement touch the same pair. Not classifiable
 *            from the ledger alone; a person decides.
 *
 * Moving PILOT or TEST into the operational Store would tell the factory it
 * holds material that does not exist, with no error anywhere. WHAT TO DO
 * ABOUT THEM IS AN OWNER DECISION, not a side effect of a recovery: this
 * command states the bucket and stops, the way inventory:check-ledger states
 * drift and repairs nothing.
 *
 * NEGATIVES ARE CALLED OUT SEPARATELY. A negative balance is not stock and
 * cannot be transferred — a transfer of minus-fifty is not a movement of
 * material, it is a correction wearing a movement's clothes. They are listed
 * so they are resolved on their own terms first.
 *
 * THE DESTINATION IS RESOLVED, NEVER HARDCODED. It is the sole active
 * warehouse. Where that is ambiguous (none, or more than one) the command
 * proposes nothing at all rather than picking, and says so.
 *
 * Exact arithmetic throughout (bcmath over a cursor), so the figures read the
 * same on MySQL (live) and sqlite (the suite), like every other ledger read
 * in this codebase.
 */
class PreviewWarehouseStockRecovery extends Command
{
    protected $signature = 'inventory:preview-warehouse-recovery';

    protected $description = 'Read-only: lists stock held in warehouses no picker offers, classifies each row from its own movement history, and names the Store it would move to. Changes nothing.';

    public const string VERDICT_NONE = 'VERDICT: nothing stranded — every non-zero balance sits in a warehouse a person can still pick.';

    /** A movement reference that proves the row is a rehearsal opening balance. */
    private const PILOT_PATTERN = '/provisional opening stock/i';

    /** A movement reference that proves the row is a wiring check or a demo document. */
    private const TEST_PATTERN = '/\bTEST\b|\bDEMO\b/i';

    public function handle(ProductionWipLocationResolver $wipLocation): int
    {
        $warehouses = DB::table('warehouses')
            ->select(['id', 'code', 'name', 'is_active', 'tally_guid'])
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $active = $warehouses->filter(fn ($w) => (bool) $w->is_active);

        // The WIP row through the SAME resolver the issue path uses, so the
        // two can never disagree about which row is Production/WIP.
        $wipId = $wipLocation->warehouseId();

        $this->line('Warehouse stock recovery preview — READ ONLY, nothing is changed.');
        $this->line(sprintf('  warehouses: %d (%d active)', $warehouses->count(), $active->count()));

        if ($wipId !== null) {
            $this->line(sprintf(
                '  Production/WIP is warehouse %d (%s) and is EXCLUDED — it is the internal location holding'
                .' material issued to production but not yet consumed (DEC-20260817-001), not stranded stock.',
                $wipId,
                $warehouses[$wipId]->code ?? '?',
            ));
        } else {
            $this->warn('  No Production/WIP location resolves. Its rows cannot be told apart from stranded stock,'
                .' so this preview refuses to classify any row until Inventory settings name one.');

            return self::FAILURE;
        }

        // The destination, resolved rather than assumed.
        $destination = $active->count() === 1 ? $active->first() : null;

        if ($destination === null) {
            $this->warn(sprintf(
                '  %d active warehouses — a single destination Store cannot be resolved, so NO destination is'
                .' proposed below. Settle which row is the Store first.',
                $active->count(),
            ));
        } else {
            $this->line(sprintf('  proposed destination: %d — %s (%s)', $destination->id, $destination->code, $destination->name));
        }

        $this->newLine();

        // Σ per (item, warehouse) of the references behind it, plus the count.
        $evidence = [];
        foreach (DB::table('stock_movements')
            ->select(['item_id', 'warehouse_id', 'reference'])
            ->orderBy('id')
            ->lazy(1000) as $row) {
            $key = "{$row->item_id}@{$row->warehouse_id}";
            $evidence[$key]['count'] = ($evidence[$key]['count'] ?? 0) + 1;
            $reference = (string) ($row->reference ?? '');

            if (preg_match(self::TEST_PATTERN, $reference) === 1) {
                $evidence[$key]['test'] = true;
            } elseif (preg_match(self::PILOT_PATTERN, $reference) === 1) {
                $evidence[$key]['pilot'] = true;
            } else {
                $evidence[$key]['real'] = true;
            }
        }

        $items = DB::table('items')->select(['id', 'sku', 'name', 'uom', 'category', 'is_active'])->get()->keyBy('id');

        // Lot / bag reach, COUNTED rather than assumed: the claim "no
        // traceability impact" has to be a measurement or it is worthless.
        $lotsByItem = DB::table('material_lots')->selectRaw('item_id, COUNT(*) as c')->groupBy('item_id')->pluck('c', 'item_id');
        $bagsByWarehouse = DB::table('material_bags')->selectRaw('current_warehouse_id, COUNT(*) as c')->groupBy('current_warehouse_id')->pluck('c', 'current_warehouse_id');

        $rows = [];
        $negatives = [];
        $buckets = ['REAL' => 0, 'PILOT' => 0, 'TEST' => 0, 'MIXED' => 0];

        foreach (DB::table('stock_balances')
            ->select(['item_id', 'warehouse_id', 'quantity'])
            ->orderBy('warehouse_id')
            ->orderBy('item_id')
            ->lazy(1000) as $balance) {
            $warehouse = $warehouses[$balance->warehouse_id] ?? null;

            if ($warehouse === null || (bool) $warehouse->is_active) {
                continue;
            }

            if ((int) $balance->warehouse_id === $wipId) {
                continue;
            }

            $quantity = bcadd((string) $balance->quantity, '0', 4);

            if (bccomp($quantity, '0', 4) === 0) {
                continue;
            }

            $key = "{$balance->item_id}@{$balance->warehouse_id}";
            $seen = $evidence[$key] ?? [];
            $kinds = array_values(array_filter([
                isset($seen['real']) ? 'REAL' : null,
                isset($seen['pilot']) ? 'PILOT' : null,
                isset($seen['test']) ? 'TEST' : null,
            ]));
            $bucket = match (true) {
                $kinds === [] => 'MIXED',
                count($kinds) > 1 => 'MIXED',
                default => $kinds[0],
            };
            $buckets[$bucket]++;

            $item = $items[$balance->item_id] ?? null;
            $negative = bccomp($quantity, '0', 4) === -1;

            $row = [
                'source' => $warehouse->code,
                'sku' => $item->sku ?? '(item '.$balance->item_id.')',
                'uom' => $item->uom ?? '(none)',
                'qty' => $quantity,
                'bucket' => $bucket.($negative ? ' (negative)' : ''),
                'destination' => $negative
                    ? '— resolve first'
                    : ($bucket === 'REAL' ? ($destination->code ?? '— unresolved') : '— owner decision'),
                'movements' => (string) ($seen['count'] ?? 0),
                'lots' => (string) ($lotsByItem[$balance->item_id] ?? 0),
                'bags here' => (string) ($bagsByWarehouse[$balance->warehouse_id] ?? 0),
                'source tally id' => $warehouse->tally_guid === null ? '(none)' : substr((string) $warehouse->tally_guid, 0, 8).'…',
            ];

            $rows[] = $row;

            if ($negative) {
                $negatives[] = $row;
            }
        }

        if ($rows === []) {
            $this->info(self::VERDICT_NONE);

            return self::SUCCESS;
        }

        $this->table(array_keys($rows[0]), $rows);
        $this->newLine();

        $this->line(sprintf('  rows stranded: %d', count($rows)));
        foreach ($buckets as $name => $count) {
            if ($count > 0) {
                $this->line(sprintf('    %-6s %d', $name, $count));
            }
        }

        if ($negatives !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '  %d row(s) are NEGATIVE. A negative balance is not stock and cannot be transferred; each is a'
                .' correction to resolve first, on its own terms, before any recovery moves anything:',
                count($negatives),
            ));

            // Listed line by line rather than left to a cell of a ten-column
            // table, which wraps: the rows a person must act on before
            // anything moves are not the place to make them hunt.
            foreach ($negatives as $row) {
                $this->warn(sprintf('    resolve first: %s @ %s = %s %s', $row['sku'], $row['source'], $row['qty'], $row['uom']));
            }
        }

        $movable = $buckets['REAL'];
        $withheld = $buckets['PILOT'] + $buckets['TEST'] + $buckets['MIXED'];

        $this->newLine();
        $this->line(sprintf(
            '  VERDICT: %d row(s) carry ordinary factory documents and are candidates to recover into the Store.'
            .' %d row(s) are withheld — their movements are rehearsal openings, wiring checks or a mixture, and'
            .' moving them would credit the Store with material the factory never received.',
            $movable,
            $withheld,
        ));
        $this->line('  Nothing was changed. What happens to the withheld rows is an owner decision, not a side effect of this check.');

        return self::SUCCESS;
    }
}
