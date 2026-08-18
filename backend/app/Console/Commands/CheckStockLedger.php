<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Enums\StockMovementType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * inventory:check-ledger — the READ-ONLY guard of the stock ledger invariant
 * (Phase 5, P5-05; StockLedgerInvariantTest):
 *
 *   for every (item, warehouse):
 *     stock_balances.quantity == Σ signed stock_movements.quantity
 *
 * where receipt and transfer_in add, issue and transfer_out subtract.
 * stock_movements is the fact (append-only, StockMovement::booted);
 * stock_balances is a running total StockMovementService keeps under a row
 * lock — this command is how a person, or a deploy, asks whether the two
 * still agree.
 *
 * Three kinds of drift, all listed: a balance whose quantity is not what its
 * movements sum to; a balance row that no movement explains (a non-zero
 * quantity with no movements); movements with no balance row at all (a
 * non-zero sum with nothing to compare against). A pair whose sum is zero
 * and has no row is in balance and not listed.
 *
 * REPORT ONLY, exit 0 clean / 1 drift. Nothing is repaired: what to do about
 * drift on live — recompute (production:reset-test-data --write recomputes
 * balances after a rehearsal clear), reconcile to Tally, or investigate the
 * movement that should have been there — is a decision, not a side effect
 * of a check. Its test pins that the database is byte-identical before and
 * after, either way.
 *
 * Exact arithmetic on purpose: the sums are formed in PHP with bcmath over
 * a lazy cursor rather than in SQL, so the answer is the same on MySQL (live)
 * and on sqlite (the suite) and no float ever rounds a 4-decimal quantity.
 * Cheap on a live database: one ordered pass over four columns of the ledger
 * and one over the balances, nothing held but the per-pair sums.
 */
class CheckStockLedger extends Command
{
    protected $signature = 'inventory:check-ledger';

    protected $description = 'Read-only: compares the sum of stock movements per (item, warehouse) with stock_balances and lists any drift. Exit 0 clean, 1 drift; changes nothing.';

    public const string VERDICT_CLEAN = 'VERDICT: clean — every stock balance equals the sum of its movements.';

    public function handle(): int
    {
        // Σ signed quantity per pair, exact.
        $sums = [];
        $movements = 0;
        $unmappedTypes = [];

        foreach (DB::table('stock_movements')->select(['id', 'item_id', 'warehouse_id', 'type', 'quantity'])->orderBy('id')->lazy(1000) as $row) {
            $movements++;
            $sign = $this->signOf((string) $row->type);

            if ($sign === null) {
                $unmappedTypes[(string) $row->type] = ($unmappedTypes[(string) $row->type] ?? 0) + 1;

                continue;
            }

            $key = "{$row->item_id}@{$row->warehouse_id}";
            $sums[$key] = bcadd($sums[$key] ?? '0.0000', bcmul((string) $row->quantity, $sign, 4), 4);
        }

        $balances = [];
        foreach (DB::table('stock_balances')->select(['item_id', 'warehouse_id', 'quantity'])->orderBy('item_id')->orderBy('warehouse_id')->lazy(1000) as $row) {
            // Normalised through bcmath: MySQL hands a DECIMAL back as
            // "900.0000", sqlite as 900 — the comparison and the table must
            // read the same on both.
            $balances["{$row->item_id}@{$row->warehouse_id}"] = bcadd((string) $row->quantity, '0', 4);
        }

        $this->line('Stock ledger check: Σ signed stock_movements per (item, warehouse) vs stock_balances.quantity');
        $this->line(sprintf('  movements: %d', $movements));
        $this->line(sprintf('  balance rows: %d', count($balances)));
        $this->line(sprintf('  pairs with movements: %d', count($sums)));

        // Every pair either side knows about, in (item, warehouse) order.
        $keys = array_unique([...array_keys($sums), ...array_keys($balances)]);
        usort($keys, function (string $a, string $b): int {
            [$ai, $aw] = array_map('intval', explode('@', $a));
            [$bi, $bw] = array_map('intval', explode('@', $b));

            return $ai <=> $bi ?: $aw <=> $bw;
        });

        $drift = [];
        foreach ($keys as $key) {
            $ledger = $sums[$key] ?? '0.0000';
            $balance = $balances[$key] ?? null;

            if (bccomp($ledger, $balance ?? '0.0000', 4) === 0) {
                continue;
            }

            [$itemId, $warehouseId] = array_map('intval', explode('@', $key));
            $difference = bcsub($balance ?? '0.0000', $ledger, 4);
            $drift[] = [
                'item' => $this->describe('items', $itemId, 'sku'),
                'warehouse' => $this->describe('warehouses', $warehouseId, 'code'),
                'ledger sum' => $ledger,
                'balance' => $balance ?? '(no row)',
                'drift' => (bccomp($difference, '0', 4) >= 0 ? '+' : '').$difference,
            ];
        }

        foreach ($unmappedTypes as $type => $count) {
            $this->warn(sprintf('  %d movement(s) carry type "%s", which this check cannot sign — left out of the sums; the ledger cannot be trusted until they are explained.', $count, $type));
        }

        if ($drift === [] && $unmappedTypes === []) {
            $this->info(self::VERDICT_CLEAN);

            return self::SUCCESS;
        }

        if ($drift !== []) {
            $this->newLine();
            $this->table(['item', 'warehouse', 'ledger sum', 'balance', 'drift'], $drift);
            $this->newLine();
        }

        $this->error(sprintf(
            'VERDICT: DRIFT — %d (item, warehouse) pair(s) disagree%s. Nothing was changed.',
            count($drift),
            $unmappedTypes === [] ? '' : sprintf(' and %d unmapped movement type(s) were found', count($unmappedTypes)),
        ));

        return self::FAILURE;
    }

    /**
     * '1', '-1' or null for a type this check does not know. Enumerated
     * against the enum rather than matched on a substring, like
     * production:reset-test-data's recompute — a future type nobody signed
     * must show up as a warning, not be silently treated as an issue.
     */
    private function signOf(string $type): ?string
    {
        return match (StockMovementType::tryFrom($type)) {
            StockMovementType::Receipt, StockMovementType::TransferIn => '1',
            StockMovementType::Issue, StockMovementType::TransferOut => '-1',
            null => null,
        };
    }

    /** "#12 SKU Name" — trashed masters included, a deleted item still owns its ledger. */
    private function describe(string $table, int $id, string $codeColumn): string
    {
        $row = DB::table($table)->select([$codeColumn, 'name'])->where('id', $id)->first();

        return $row === null
            ? "#{$id} (missing)"
            : trim(sprintf('#%d %s %s', $id, $row->{$codeColumn} ?? '', $row->name ?? ''));
    }
}
