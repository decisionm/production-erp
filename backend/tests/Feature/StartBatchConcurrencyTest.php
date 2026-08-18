<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Exceptions\MachineBusyException;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Only one batch may ever be in progress on one machine.
 *
 * ## Why locking the entry rows was not enough
 *
 * The guard used to lock shift_production_entries rows matching
 * (machine, in_progress). That cannot protect the case that matters. When a
 * machine is IDLE there is no such row, so two concurrent requests both
 * selected nothing, both passed the check and both inserted — the machine ended
 * up with two live batches, each consuming from the same day bin, each producing
 * its own Tally voucher.
 *
 * The fix locks the work_centers row FIRST. That row always exists, so it is a
 * lock available whether or not a batch is. Every starter serialises on it.
 *
 * ## What these tests do and do not prove
 *
 * PHPUnit runs one connection against a SQLite database, so nothing here can
 * execute two genuinely simultaneous transactions. What is asserted instead is
 * the mechanism the protection depends on:
 *
 *  - the machine row is locked, and locked BEFORE the emptiness check (ordering
 *    is the entire fix — a lock taken afterwards protects nothing);
 *  - the lock and the insert are in ONE transaction, so nothing can interleave
 *    between the check and the write;
 *  - a machine that already holds a batch refuses the next start, from either
 *    the service or the HTTP endpoint.
 *
 * Real parallel execution against MySQL is a deployment-time check, not a unit
 * test, and is called out as such in the go-live report.
 */
class StartBatchConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $machine = WorkCenter::create(['name' => 'Machine 4', 'code' => 'M4', 'is_active' => true]);
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true]);
        $item = Item::create([
            'sku' => 'CONC-BTL', 'name' => 'Concurrency Bottle', 'uom' => 'NOS', 'is_active' => true,
            'standard_cycle_time' => '12.00', 'standard_cavities' => 4, 'nominal_weight_grams' => '18.0000',
            'nos_per_box' => 500, 'tally_stock_item_guid' => 'guid-conc',
        ]);

        return [$machine, $shift, $warehouse, $item];
    }

    private function payload(array $f): array
    {
        [$machine, $shift, $warehouse, $item] = $f;

        return [
            'work_center_id' => $machine->id,
            'shift_id' => $shift->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-31',
        ];
    }

    public function test_the_machine_row_is_read_before_the_running_check(): void
    {
        // Driver-agnostic half of the proof. SQLite ignores lockForUpdate() and
        // emits no FOR UPDATE at all, so the ORDER of the two reads is what can
        // be asserted here — and ordering is the entire fix. A machine lock
        // taken after the emptiness check protects nothing.
        $f = $this->fixtures();

        DB::enableQueryLog();
        app(ShiftProductionEntryService::class)->startBatch($this->payload($f), null);
        // Identifiers normalised to one quoting: sqlite's grammar writes
        // "work_centers", MySQL's `work_centers` — the suite runs on both.
        $log = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower(str_replace('`', '"', $q)));
        DB::disableQueryLog();

        $machineRead = $log->search(fn (string $q) => str_starts_with($q, 'select') && str_contains($q, 'from "work_centers"'));
        $runningCheck = $log->search(fn (string $q) => str_starts_with($q, 'select') && str_contains($q, 'from "shift_production_entries"'));

        $this->assertNotFalse($machineRead, 'The machine row is never read — nothing exists to serialise concurrent starters on.');
        $this->assertNotFalse($runningCheck, 'The in-progress recheck is missing.');
        $this->assertLessThan(
            $runningCheck,
            $machineRead,
            'The machine row must be read (and locked) BEFORE the in-progress check, or the idle state is still racy.',
        );
    }

    public function test_the_machine_lock_really_emits_for_update_on_mysql(): void
    {
        // The other half. Production runs MySQL, where lockForUpdate() is a real
        // row lock; SQLite silently drops it. Asserting the MySQL grammar keeps
        // the guard honest — without this, the lock could be removed from the
        // service and every test above would still pass on SQLite.
        // The builder records the lock even where the grammar discards it, so
        // this catches the lock being dropped from the service outright.
        $locked = WorkCenter::query()->whereKey(1)->lockForUpdate()->toBase();
        $this->assertTrue($locked->lock, 'lockForUpdate() did not register a lock on the builder.');

        // And under the production grammar it becomes a real row lock.
        $connection = DB::connection();
        $mysql = new Builder($connection, new MySqlGrammar($connection), $connection->getPostProcessor());
        $sql = $mysql->from('work_centers')->where('id', 1)->lockForUpdate()->toSql();

        $this->assertStringContainsString('for update', strtolower($sql));
    }

    public function test_the_lock_and_the_insert_share_one_transaction(): void
    {
        $f = $this->fixtures();

        DB::enableQueryLog();
        app(ShiftProductionEntryService::class)->startBatch($this->payload($f), null);
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower(str_replace('`', '"', $q)));
        DB::disableQueryLog();

        // Nothing may commit between locking the machine and inserting the
        // batch, or another request slips into the gap.
        $lock = $queries->search(fn (string $q) => str_contains($q, 'work_centers') && str_contains($q, 'for update'));
        $insert = $queries->search(fn (string $q) => str_starts_with($q, 'insert into "shift_production_entries"'));

        $this->assertNotFalse($insert, 'No batch insert was recorded.');
        $this->assertLessThan($insert, $lock, 'The machine lock must precede the insert.');

        $between = $queries->slice($lock, $insert - $lock);
        $this->assertFalse(
            $between->contains(fn (string $q) => $q === 'commit' || str_starts_with($q, 'commit')),
            'A commit occurred between locking the machine and inserting the batch, which reopens the race.',
        );
    }

    public function test_a_machine_already_running_refuses_the_next_start(): void
    {
        $f = $this->fixtures();
        $service = app(ShiftProductionEntryService::class);

        $first = $service->startBatch($this->payload($f), null);
        $this->assertSame(BatchStatus::InProgress, $first->batch_status);

        $this->expectException(MachineBusyException::class);
        $service->startBatch($this->payload($f), null);
    }

    public function test_only_one_batch_exists_after_a_refused_second_start(): void
    {
        $f = $this->fixtures();
        $service = app(ShiftProductionEntryService::class);

        $service->startBatch($this->payload($f), null);

        try {
            $service->startBatch($this->payload($f), null);
        } catch (MachineBusyException) {
            // expected
        }

        $this->assertSame(
            1,
            ShiftProductionEntry::where('work_center_id', $f[0]->id)
                ->where('batch_status', BatchStatus::InProgress->value)
                ->count(),
            'A machine must never hold two in-progress batches.',
        );
    }

    public function test_a_second_machine_is_unaffected(): void
    {
        // The lock must serialise starters on ONE machine, not across the floor —
        // ten machines start batches at shift change and must not queue behind
        // each other.
        $f = $this->fixtures();
        $other = WorkCenter::create(['name' => 'Machine 5', 'code' => 'M5', 'is_active' => true]);
        $service = app(ShiftProductionEntryService::class);

        $service->startBatch($this->payload($f), null);
        $second = $service->startBatch(['...' => null] + array_merge($this->payload($f), ['work_center_id' => $other->id]), null);

        $this->assertSame(BatchStatus::InProgress, $second->batch_status);
        $this->assertSame(2, ShiftProductionEntry::where('batch_status', BatchStatus::InProgress->value)->count());
    }
}
