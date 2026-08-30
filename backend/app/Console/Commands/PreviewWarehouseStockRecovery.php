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
 * command is the per-row evidence DEC-20260830-002 demands before any merge,
 * deactivation or move: "no merge, deletion or deactivation of them happens
 * until dependency and stock history have been proven for each row."
 *
 * (DEC-20260830-002 is the one-Store decision, recorded 30-Aug-2026. It
 * supersedes DEC-20260817-001's reading of three separate places while
 * deliberately KEEPING that decision's invariant and its no-merge clause,
 * which are the two things this command rests on.)
 *
 * PRODUCTION/WIP IS NOT IN SCOPE, and that is the load-bearing exclusion.
 * DEC-20260830-002 makes Production/WIP the inventory location holding
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

    /**
     * A wiring check or a demo document. Checked FIRST, so a reference that is
     * both an opening balance and a test ("Opening stock for SPE-3 test")
     * lands in the stricter bucket rather than the kinder one.
     */
    private const TEST_PATTERN = '/\bTEST\b|\bDEMO\b/i';

    /**
     * ANY opening balance, not just the one the pilot happened to write.
     *
     * The first cut of this matched the literal 'Provisional opening stock
     * (pilot)'. That is the string this factory used, and matching it would
     * have worked — right up to 'Opening stock top-up', which is a wiring
     * artefact on the live instance, matches neither that literal nor the
     * TEST pattern, and would therefore have been classified as ordinary
     * factory stock AND printed with the live Store as its destination. A
     * report that proposes moving material the factory never received is the
     * exact failure this command exists to prevent, so the rule is drawn on
     * the CONCEPT rather than on one factory's wording:
     *
     *   an opening balance is a figure somebody seeded, not a document
     *   recording that material arrived.
     *
     * So every 'opening stock' reference is withheld and a person looks at
     * it. That is deliberately over-inclusive: a genuine opening balance that
     * really is standing in the store is withheld too, and the cost of that
     * is one owner conversation, against the cost of silently crediting the
     * Store with thousands of kilograms it does not have.
     */
    private const OPENING_PATTERN = '/opening stock/i';

    public function handle(ProductionWipLocationResolver $wipLocation): int
    {
        // DB::table, not the model, and that is on purpose: Warehouse soft
        // deletes, and a TRASHED warehouse still carries its stock_balances
        // rows (the one cascadeSide() check with no database backstop). An
        // Eloquent read would apply the soft-delete scope and quietly drop
        // exactly the rows most likely to be stranded — the ones nobody can
        // even see in the admin list any more.
        $warehouses = DB::table('warehouses')
            ->select(['id', 'code', 'name', 'is_active', 'tally_guid', 'deleted_at'])
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        // A trashed row is not pickable whatever its is_active says, so it is
        // never a destination — and its stock is stranded by definition.
        $active = $warehouses->filter(fn ($w) => (bool) $w->is_active && $w->deleted_at === null);

        $trashedWithRows = $warehouses->filter(fn ($w) => $w->deleted_at !== null);

        if ($trashedWithRows->isNotEmpty()) {
            $this->warn(sprintf(
                '  %d warehouse row(s) are SOFT DELETED and are treated as unpickable here: %s.',
                $trashedWithRows->count(),
                $trashedWithRows->pluck('code')->implode(', '),
            ));
        }

        // The WIP row through the SAME resolver the issue path uses, so the
        // two can never disagree about which row is Production/WIP.
        $wipId = $wipLocation->warehouseId();

        $this->line('Warehouse stock recovery preview — READ ONLY, nothing is changed.');
        $this->line(sprintf('  warehouses: %d (%d active)', $warehouses->count(), $active->count()));

        if ($wipId !== null) {
            $this->line(sprintf(
                '  Production/WIP is warehouse %d (%s) and is EXCLUDED — it is the internal location holding'
                .' material issued to production but not yet consumed (DEC-20260830-002), not stranded stock.',
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
            } elseif (preg_match(self::OPENING_PATTERN, $reference) === 1) {
                $evidence[$key]['opening'] = true;
            } else {
                $evidence[$key]['documented'] = true;
            }
        }

        $items = DB::table('items')->select(['id', 'sku', 'name', 'uom', 'category', 'is_active'])->get()->keyBy('id');

        // Lot / bag reach, COUNTED rather than assumed: the claim "no
        // traceability impact" has to be a measurement or it is worthless.
        $lotsByItem = DB::table('material_lots')->selectRaw('item_id, COUNT(*) as c')->groupBy('item_id')->pluck('c', 'item_id');
        $bagsByWarehouse = DB::table('material_bags')->selectRaw('current_warehouse_id, COUNT(*) as c')->groupBy('current_warehouse_id')->pluck('c', 'current_warehouse_id');

        $rows = [];
        $negatives = [];
        $buckets = ['DOCUMENTED' => 0, 'OPENING' => 0, 'TEST' => 0, 'MIXED' => 0];

        foreach (DB::table('stock_balances')
            ->select(['item_id', 'warehouse_id', 'quantity'])
            ->orderBy('warehouse_id')
            ->orderBy('item_id')
            ->lazy(1000) as $balance) {
            $warehouse = $warehouses[$balance->warehouse_id] ?? null;

            // "Pickable" is the whole test, and it is not is_active alone: a
            // soft-deleted row can still carry is_active = true and no picker
            // will ever offer it.
            if ($warehouse === null || $active->has($warehouse->id)) {
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
                isset($seen['documented']) ? 'DOCUMENTED' : null,
                isset($seen['opening']) ? 'OPENING' : null,
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
                    : ($bucket === 'DOCUMENTED' ? ($destination->code ?? '— unresolved') : '— owner decision'),
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

        $candidates = $buckets['DOCUMENTED'];
        $withheld = $buckets['OPENING'] + $buckets['TEST'] + $buckets['MIXED'];

        $this->newLine();
        $this->line(sprintf(
            '  VERDICT: %d row(s) are backed only by ordinary factory documents and are CANDIDATES to recover'
            .' into the Store. %d row(s) are withheld — their movements are opening balances, wiring checks or'
            .' a mixture, and moving those would credit the Store with material the factory never received.',
            $candidates,
            $withheld,
        ));

        $this->newLine();
        $this->warn('  TWO THINGS A PERSON MUST SETTLE BEFORE ANY OF THIS MOVES:');
        $this->warn('   1. Does a document reference from a retired location prove the material is PHYSICALLY there'
            .' today? This report reads references, not shelves. The candidate list above is only as good as that'
            .' assumption, and confirming it is the owner\'s call, not this command\'s.');
        $this->warn('   2. A transfer carries the SOURCE row\'s average cost into the destination, where the'
            .' weighted average is recomputed. Recovering these rows would therefore re-value the Store\'s existing'
            .' stock of the same items, blending in costs that came from a rehearsal seeder or an older Tally'
            .' company. That is an Accounts consequence and it is not this report\'s to accept.');

        $this->newLine();
        $this->line('  Nothing was changed. This command has no write mode. What happens next is an owner decision.');

        return self::SUCCESS;
    }
}
