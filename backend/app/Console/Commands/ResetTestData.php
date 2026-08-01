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
use Illuminate\Support\Collection;
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
 *
 * ## `--since` means SINCE, for every table
 *
 * The cutoff scopes EVERY deletion, not just the batches: bin and batch stock
 * movements by `movement_date`, the day-bin ledger by `recorded_at`, material
 * lots by `received_date` (and their bags with them), Tally entries through the
 * batches they belong to. A `--since` that scoped only the batches would, two
 * weeks after go-live, still take every bag, lot and bin row the factory ever
 * made — and the balance recompute would then CONJURE stock back into the store,
 * because the transfer that moved it out had been deleted underneath it.
 *
 * For the same reason a transfer PAIR lives or dies together: deleting one leg
 * of a store→day-bin transfer leaves the recompute inventing (or losing) exactly
 * that quantity. Legs are matched by `transfer_group`, and a group that straddles
 * the cutoff is left entirely alone rather than half-deleted.
 *
 * A `--since` that is not a real YYYY-MM-DD date is REFUSED, never guessed at.
 * "yesterday" silently becoming "everything" is the one mistake this command
 * must not make.
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
        $sinceOption = $this->option('since');

        // Refused, not guessed at. Carbon::parse() would turn "yesterday" into a
        // real date and an empty string into today — both of which read as a
        // scoped run while deleting something else entirely. The workflow refuses
        // the same shapes before it ever gets here; this is the second layer, and
        // the only one a hand-typed command has.
        if ($sinceOption !== null && ! $this->isCalendarDate(trim((string) $sinceOption))) {
            $this->error('--since must be a calendar date in YYYY-MM-DD form — got "'.$sinceOption.'".');
            $this->line('Nothing was read and nothing was deleted. Omit --since entirely to clear every batch.');

            return self::FAILURE;
        }

        $since = $sinceOption !== null ? trim((string) $sinceOption) : null;

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
        // opening-stock transfer someone made deliberately is left alone — and
        // scoped by movement_date, so a load from before the cutoff (whose
        // material a surviving batch already consumed) is not pulled out from
        // under the recompute.
        $binMovementIds = StockMovement::query()
            ->where(function ($query) {
                $query->where('reference', 'like', 'Day bin%')
                    ->orWhere('reference', 'like', '%day bin%');
            })
            ->when($since !== null, fn ($q) => $q->whereDate('movement_date', '>=', $since))
            ->pluck('id');

        [$deleteMovementIds, $straddlingGroups] = $this->wholeTransferGroupsOnly(
            $movementIds->merge($binMovementIds)->unique(),
            $since,
        );

        // The day-bin ledger, the lots and their bags: scoped the same way, each
        // by the date column that means "when this happened" for that table.
        $dayBinMovementIds = DayBinMovement::query()
            ->when($since !== null, fn ($q) => $q->whereDate('recorded_at', '>=', $since))
            ->pluck('id');

        $lotIds = MaterialLot::query()
            ->when($since !== null, fn ($q) => $q->whereDate('received_date', '>=', $since))
            ->pluck('id');

        // Bags follow their lot — the two are one intake, and the bag→lot key is
        // restrictOnDelete, so a lot cannot go without them anyway.
        $bagIds = MaterialBag::query()->whereIn('material_lot_id', $lotIds)->pluck('id');

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

        // The scope line and the counts under it are one statement: every figure
        // in the table is the figure UNDER THE CUTOFF, so what the operator reads
        // here is exactly what a --write run would take.
        $this->line($since !== null
            ? "SCOPE: only records dated on or after {$since}. Everything earlier is left exactly as it is."
            : 'SCOPE: every date — no --since given, so this clears the whole rehearsal.');
        $this->newLine();

        $this->table(['what', 'count'], [
            ['production entries', $entries->count()],
            ['  of which approved or synced', $approved->count()],
            ['  still in progress', $entries->where('batch_status', 'in_progress')->count()],
            ['stock movements from those batches', $movementIds->unique()->count()],
            ['day-bin load/return movements', $binMovementIds->unique()->count()],
            ['stock movements deleted in total', $deleteMovementIds->count()],
            ['day-bin ledger rows', $dayBinMovementIds->count()],
            ['downtime events', ProductionDowntimeEvent::query()->whereIn('shift_production_entry_id', $entryIds)->count()],
            ['queued/sent Tally vouchers', $vouchers->count()],
            ['material lots', $lotIds->count()],
            ['material bags', $bagIds->count()],
        ]);

        if ($straddlingGroups->isNotEmpty()) {
            $this->newLine();
            $this->warn($straddlingGroups->count().' transfer(s) have one leg before the cutoff and one after.');
            $this->warn('They are left ALONE, whole: deleting half a transfer would invent stock at one end and lose it at the other.');
        }

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

        DB::transaction(function () use ($entryIds, $deleteMovementIds, $dayBinMovementIds, $lotIds, $bagIds, $vouchers) {
            // Children first, so nothing is orphaned even where the schema
            // would have allowed it.
            ProductionDowntimeEvent::query()->whereIn('shift_production_entry_id', $entryIds)->delete();
            DayBinMovement::query()->whereIn('id', $dayBinMovementIds)->delete();

            DB::table('shift_material_consumptions')->whereIn('shift_production_entry_id', $entryIds)->delete();
            DB::table('shift_scraps')->whereIn('shift_production_entry_id', $entryIds)->delete();

            TallySyncEntry::query()->whereIn('id', $vouchers->pluck('id'))->delete();

            // Handover children point at a parent entry; clearing the link
            // first keeps the delete order from tripping the constraint.
            ShiftProductionEntry::query()->whereIn('parent_entry_id', $entryIds)->update(['parent_entry_id' => null]);
            ShiftProductionEntry::query()->whereIn('id', $entryIds)->forceDelete();

            StockMovement::query()->whereIn('id', $deleteMovementIds)->delete();

            // Bags and lots are rehearsal intake. Bags first — they reference
            // the lot.
            MaterialBag::query()->whereIn('id', $bagIds)->delete();
            MaterialLot::query()->whereIn('id', $lotIds)->delete();

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
     * A real calendar date, written the one way this command accepts.
     *
     * Deliberately not Carbon::parse(): that accepts "yesterday", "+1 week" and
     * the empty string, each of which would run a delete the operator did not
     * describe. checkdate() on top of the shape check rejects 2026-02-31, which
     * the regex alone would let through.
     */
    private function isCalendarDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /**
     * Expand a set of stock movements so that no transfer is half-deleted.
     *
     * A transfer is a PAIR — one transfer_out from the store, one transfer_in to
     * the day bin, sharing a transfer_group. Delete one leg and the recompute
     * believes the other: stock appears at one end or vanishes at the other, and
     * a floor that trusts the figure would find out by running short mid-shift.
     *
     * So a group is all-or-nothing. Every leg of a touched group is pulled in —
     * including one the reference match missed — EXCEPT where a leg falls before
     * the cutoff, in which case the whole group stays. Nothing before --since is
     * ever deleted, and no pair is ever left with one leg standing.
     *
     * @param  Collection<int, int>  $movementIds
     * @return array{0: Collection<int, int>, 1: Collection<int, string>}
     */
    private function wholeTransferGroupsOnly(Collection $movementIds, ?string $since): array
    {
        $groups = StockMovement::query()
            ->whereIn('id', $movementIds)
            ->whereNotNull('transfer_group')
            ->pluck('transfer_group')
            ->unique();

        if ($groups->isEmpty()) {
            return [$movementIds->values(), collect()];
        }

        $legs = StockMovement::query()
            ->whereIn('transfer_group', $groups)
            ->get(['id', 'transfer_group', 'movement_date']);

        $straddling = collect();
        $keptLegIds = collect();
        $extraLegIds = collect();

        foreach ($legs->groupBy('transfer_group') as $group => $rows) {
            $reachesBackPastCutoff = $since !== null && $rows->contains(
                fn ($leg) => $leg->movement_date?->toDateString() < $since,
            );

            if ($reachesBackPastCutoff) {
                $straddling->push((string) $group);
                $keptLegIds = $keptLegIds->merge($rows->pluck('id'));

                continue;
            }

            $extraLegIds = $extraLegIds->merge($rows->pluck('id'));
        }

        $deleteIds = $movementIds
            ->reject(fn ($id) => $keptLegIds->contains($id))
            ->merge($extraLegIds)
            ->unique()
            ->values();

        return [$deleteIds, $straddling];
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
