<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\ProductionDowntimeEvent;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Clear the transactional records made while rehearsing, so a real first day
 * starts from a clean floor.
 *
 * ## Why this is a command and not a few DELETEs
 *
 * Three reasons, each learned from something that would otherwise go wrong:
 *
 *  1. **Stock moved.** Completing a batch issues material and receives finished
 *     goods, so deleting the batch rows alone leaves stock_balances holding
 *     quantities no surviving movement explains. Balances are therefore
 *     RECOMPUTED from the movements that remain, never patched by hand.
 *  2. **Tally already has some of it.** An approved batch queues a voucher into
 *     the factory's live books. This command CANNOT unsend that, and pretending
 *     otherwise would be the worst thing it could do — so it names every
 *     voucher number it is about to orphan and says plainly that a person must
 *     clear those in Tally.
 *  3. **Masters must survive.** Products, standards, machine configurations,
 *     downtime reasons, warehouses, shifts, users and the imported workbook are
 *     the work of days. Nothing here touches them, and the dry run says so on
 *     every invocation so nobody has to take it on trust.
 *
 * Defaults to a DRY RUN that reports and changes nothing. `--write` is a
 * separate, deliberate act.
 */
class ResetTestData extends Command
{
    protected $signature = 'production:reset-test-data
        {--write : Actually delete. Without this the command only reports.}
        {--since= : Only records on or after this production date (YYYY-MM-DD). Default: everything.}
        {--force : Skip the typed confirmation. For non-interactive runs only.}';

    protected $description = 'Delete rehearsal batches, their stock movements and day-bin loads, and recompute stock balances. Masters are never touched.';

    public function handle(): int
    {
        $since = $this->option('since') !== null
            ? Carbon::parse((string) $this->option('since'))->toDateString()
            : null;

        $entries = ShiftProductionEntry::query()
            ->when($since !== null, fn ($q) => $q->whereDate('production_date', '>=', $since))
            ->get();

        if ($entries->isEmpty()) {
            $this->info($since !== null
                ? "No production entries on or after {$since} — nothing to clear."
                : 'No production entries at all — nothing to clear.');

            return self::SUCCESS;
        }

        $entryIds = $entries->pluck('id');

        // Stock movements are matched by the reference the services stamp on
        // them ("SPE #{id}"), which is the only link between a movement and the
        // batch that caused it — there is no foreign key. Matched with a word
        // boundary so "SPE #1" cannot also claim "SPE #10".
        $movementIds = collect();
        foreach ($entryIds as $id) {
            $movementIds = $movementIds->merge(
                StockMovement::query()
                    ->where('reference', 'SPE #'.$id)
                    ->pluck('id'),
            );
        }

        // Day-bin loads and returns: the transfers into and out of the bin
        // warehouse, which are rehearsal too. Identified by the references the
        // day-bin service stamps rather than by warehouse, so a genuine
        // opening-stock transfer someone made deliberately is left alone.
        $binMovementIds = StockMovement::query()
            ->where(function ($query) {
                $query->where('reference', 'like', 'Day bin%')
                    ->orWhere('reference', 'like', '%day bin%');
            })
            ->pluck('id');

        // status is cast to an enum, so it must be compared by ->value: casting
        // the enum object to a string throws, and the whole report died before
        // printing a single figure.
        $approved = $entries->filter(function ($entry) {
            $status = $entry->status instanceof \BackedEnum ? $entry->status->value : (string) $entry->status;

            return in_array($status, ['approved', 'synced'], true);
        });
        $vouchers = TallySyncEntry::query()
            ->whereIn('syncable_id', $entryIds)
            ->where('syncable_type', ShiftProductionEntry::class)
            ->get();

        $this->table(['what', 'count'], [
            ['production entries', $entries->count()],
            ['  of which approved or synced', $approved->count()],
            ['  still in progress', $entries->where('batch_status', 'in_progress')->count()],
            ['stock movements from those batches', $movementIds->unique()->count()],
            ['day-bin load/return movements', $binMovementIds->unique()->count()],
            ['day-bin ledger rows', DayBinMovement::query()->count()],
            ['downtime events', ProductionDowntimeEvent::query()->whereIn('shift_production_entry_id', $entryIds)->count()],
            ['queued/sent Tally vouchers', $vouchers->count()],
            ['material lots', MaterialLot::query()->count()],
            ['material bags', MaterialBag::query()->count()],
        ]);

        if ($vouchers->isNotEmpty()) {
            $this->newLine();
            $this->warn('These vouchers were already sent to Tally. Deleting them HERE does not remove them THERE —');
            $this->warn('somebody has to delete or cancel them in Tally, or the books will double-count:');
            foreach ($vouchers as $voucher) {
                $number = data_get($voucher->payload, 'voucher_number', '(no number in payload)');
                // Enum-cast, like the entry's own status — read ->value rather
                // than interpolating the object, which throws.
                $status = $voucher->status instanceof \BackedEnum
                    ? $voucher->status->value
                    : (string) $voucher->status;
                $this->line("  · {$number}  status={$status}");
            }
        }

        $this->newLine();
        $this->line('NOT touched: products, production standards, machine configurations, downtime reasons,');
        $this->line('factory settings, warehouses, shifts, users, moulds — every master stays exactly as it is.');
        $this->line('Stock balances will be RECOMPUTED from the movements that survive, not edited by hand.');

        if (! $this->option('write')) {
            $this->newLine();
            $this->info('DRY RUN — nothing deleted. Re-run with --write once a database backup exists.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete the records listed above? This cannot be undone without a backup.', false)) {
            $this->info('Left alone.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($entryIds, $movementIds, $binMovementIds, $vouchers) {
            // Children first, so nothing is orphaned even where the schema
            // would have allowed it.
            ProductionDowntimeEvent::query()->whereIn('shift_production_entry_id', $entryIds)->delete();
            DayBinMovement::query()->delete();

            DB::table('shift_material_consumptions')->whereIn('shift_production_entry_id', $entryIds)->delete();
            DB::table('shift_scraps')->whereIn('shift_production_entry_id', $entryIds)->delete();

            TallySyncEntry::query()->whereIn('id', $vouchers->pluck('id'))->delete();

            // Handover children point at a parent entry; clearing the link
            // first keeps the delete order from tripping the constraint.
            ShiftProductionEntry::query()->whereIn('parent_entry_id', $entryIds)->update(['parent_entry_id' => null]);
            ShiftProductionEntry::query()->whereIn('id', $entryIds)->forceDelete();

            StockMovement::query()->whereIn('id', $movementIds->merge($binMovementIds)->unique())->delete();

            // Bags and lots are rehearsal intake. Bags first — they reference
            // the lot.
            MaterialBag::query()->delete();
            MaterialLot::query()->delete();

            $this->recomputeBalances();
        });

        $this->newLine();
        $this->info('Cleared. Stock balances recomputed from the surviving movements.');
        if ($vouchers->isNotEmpty()) {
            $this->warn('Remember: the Tally vouchers listed above still exist in Tally and must be dealt with there.');
        }

        return self::SUCCESS;
    }

    /**
     * Rebuild every stock balance from the movements that remain.
     *
     * Recomputed rather than decremented: a balance edited by subtraction
     * inherits every rounding and every movement the reference match missed,
     * and the whole point of this command is a floor whose figures nobody has
     * to distrust. A material with no movements left drops to zero rather than
     * keeping a row nothing explains.
     */
    private function recomputeBalances(): void
    {
        // The column is `type`, not a direction flag: receipt and transfer_in
        // add, issue and transfer_out subtract (see StockMovementType and the
        // create() calls in StockMovementService). Enumerated explicitly rather
        // than matched on a substring — a future type nobody mapped would
        // otherwise be silently treated as an issue and quietly lose stock.
        $sums = StockMovement::query()
            ->selectRaw(
                'item_id, warehouse_id, SUM(CASE WHEN type IN (?, ?) THEN quantity WHEN type IN (?, ?) THEN -quantity ELSE 0 END) as qty',
                ['receipt', 'transfer_in', 'issue', 'transfer_out'],
            )
            ->groupBy('item_id', 'warehouse_id')
            ->get();

        StockBalance::query()->update(['quantity' => '0.0000']);

        foreach ($sums as $sum) {
            StockBalance::query()
                ->where('item_id', $sum->item_id)
                ->where('warehouse_id', $sum->warehouse_id)
                ->update(['quantity' => number_format(max(0, (float) $sum->qty), 4, '.', '')]);
        }
    }
}
