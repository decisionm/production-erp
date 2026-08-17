<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 5.5 (WS-C) — GET /production/shift-production-entries grows the
 * filters Completed Today needs, server-side, on the ONE list (no dedicated
 * completed-today endpoint):
 *
 *   production_date · date_from/date_to (factory days, on the stored
 *   production_date column) · work_center_id · shift_id · batch_status
 *   (in_progress|completed|cancelled) · status · per_page 1..100 (default 20)
 *
 * every key optional, unknown keys ignored, a value that could only be a
 * mistake refused with 422 (it used to be a 500 for a bad `status`). The
 * unfiltered call — the dashboard's, the approval queue's — answers exactly
 * as before. And each row carries `tally`: the TallyLink of the voucher that
 * CARRIES the entry (the shift voucher it was stamped onto via
 * tally_sync_entry_id, else its own per-entry voucher via the morph), null
 * when no voucher exists — resolved in a constant number of queries per page.
 */
class EntriesIndexFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Shift $day;

    private Shift $night;

    private WorkCenter $m1;

    private WorkCenter $m2;

    private Item $item;

    private Warehouse $fg;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        Sanctum::actingAs($user);

        $this->day = Shift::create(['name' => 'Day', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->night = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00']);
        $this->m1 = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'display_sequence' => 1]);
        $this->m2 = WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2', 'display_sequence' => 2]);
        $this->fg = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $this->item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'pcs']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function entry(array $overrides = []): ShiftProductionEntry
    {
        static $sequence = 0;
        $sequence++;

        return ShiftProductionEntry::create(array_merge([
            'shift_id' => $this->day->id,
            'work_center_id' => $this->m1->id,
            'item_id' => $this->item->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-10',
            'batch_number' => sprintf('20260810-M01-%03d', $sequence),
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '100',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ], $overrides));
    }

    /** @return list<int> the ids the endpoint answers, in the order it answers them */
    private function ids(string $query = ''): array
    {
        $response = $this->getJson('/api/v1/production/shift-production-entries'.($query === '' ? '' : "?{$query}"));
        $response->assertOk();

        return array_map(fn (array $row) => $row['id'], $response->json('data'));
    }

    // ---- backward compatibility ------------------------------------------

    public function test_unfiltered_call_answers_exactly_as_before(): void
    {
        $completed = $this->entry();
        $running = $this->entry(['batch_status' => BatchStatus::InProgress, 'quantity_produced' => null]);
        $cancelled = $this->entry(['batch_status' => BatchStatus::Cancelled]);
        $older = $this->entry(['production_date' => '2026-08-09']);

        $response = $this->getJson('/api/v1/production/shift-production-entries')->assertOk();

        // Cancelled rows are not work; everything else, newest first.
        $this->assertSame([$running->id, $completed->id, $older->id], array_map(fn (array $row) => $row['id'], $response->json('data')));
        $this->assertNotContains($cancelled->id, array_column($response->json('data'), 'id'));
        $this->assertSame(20, $response->json('meta.per_page'));
        // The additive key rides every row, null when no voucher exists.
        foreach ($response->json('data') as $row) {
            $this->assertArrayHasKey('tally', $row);
            $this->assertNull($row['tally']);
        }
    }

    public function test_status_filter_still_implies_a_completed_batch(): void
    {
        $pendingCompleted = $this->entry();
        $this->entry(['batch_status' => BatchStatus::InProgress, 'quantity_produced' => null]);
        $this->entry(['status' => ShiftProductionEntryStatus::Approved]);

        $this->assertSame([$pendingCompleted->id], $this->ids('status=pending'));
    }

    public function test_the_service_keeps_answering_the_old_positional_call(): void
    {
        $pending = $this->entry();
        $this->entry(['status' => ShiftProductionEntryStatus::Approved]);
        $cancelled = $this->entry(['batch_status' => BatchStatus::Cancelled]);

        $service = app(ShiftProductionEntryService::class);

        $this->assertSame([$pending->id], $service->paginate(20, ShiftProductionEntryStatus::Pending, false)->pluck('id')->all());
        $this->assertContains($cancelled->id, $service->paginate(20, null, true)->pluck('id')->all());
        $this->assertNotContains($cancelled->id, $service->paginate()->pluck('id')->all());
    }

    // ---- each filter -----------------------------------------------------

    public function test_production_date_filters_on_the_stored_factory_day(): void
    {
        $tenth = $this->entry(['production_date' => '2026-08-10']);
        $this->entry(['production_date' => '2026-08-09']);
        $this->entry(['production_date' => '2026-08-11']);
        // The night shift files under the day it started — the column, not
        // the clock, is what the filter compares.
        $nightOfTenth = $this->entry(['production_date' => '2026-08-10', 'shift_id' => $this->night->id]);

        $this->assertSame([$nightOfTenth->id, $tenth->id], $this->ids('production_date=2026-08-10'));
    }

    public function test_date_range_is_inclusive_at_both_ends(): void
    {
        $this->entry(['production_date' => '2026-08-08']);
        $ninth = $this->entry(['production_date' => '2026-08-09']);
        $tenth = $this->entry(['production_date' => '2026-08-10']);
        $eleventh = $this->entry(['production_date' => '2026-08-11']);
        $this->entry(['production_date' => '2026-08-12']);

        $this->assertSame([$eleventh->id, $tenth->id, $ninth->id], $this->ids('date_from=2026-08-09&date_to=2026-08-11'));
        // Either end alone is an open range.
        $this->assertSame([$eleventh->id, $tenth->id], $this->ids('date_from=2026-08-10&date_to=2026-08-11'));
        $this->assertCount(4, $this->ids('date_from=2026-08-09'));
        $this->assertCount(3, $this->ids('date_to=2026-08-10'));
    }

    public function test_work_center_filter(): void
    {
        $onM1 = $this->entry(['work_center_id' => $this->m1->id]);
        $onM2 = $this->entry(['work_center_id' => $this->m2->id]);

        $this->assertSame([$onM1->id], $this->ids("work_center_id={$this->m1->id}"));
        $this->assertSame([$onM2->id], $this->ids("work_center_id={$this->m2->id}"));
    }

    public function test_shift_filter(): void
    {
        $day = $this->entry(['shift_id' => $this->day->id]);
        $night = $this->entry(['shift_id' => $this->night->id]);

        $this->assertSame([$day->id], $this->ids("shift_id={$this->day->id}"));
        $this->assertSame([$night->id], $this->ids("shift_id={$this->night->id}"));
    }

    public function test_batch_status_filter_including_cancelled_which_the_default_hides(): void
    {
        $completed = $this->entry();
        $running = $this->entry(['batch_status' => BatchStatus::InProgress, 'quantity_produced' => null]);
        $cancelled = $this->entry(['batch_status' => BatchStatus::Cancelled]);

        $this->assertSame([$completed->id], $this->ids('batch_status=completed'));
        $this->assertSame([$running->id], $this->ids('batch_status=in_progress'));
        // Asking for cancelled batches by name IS asking to see them.
        $this->assertSame([$cancelled->id], $this->ids('batch_status=cancelled'));
    }

    public function test_the_completed_today_call_combines_date_batch_status_and_page_size(): void
    {
        $todayDone = $this->entry(['production_date' => '2026-08-10']);
        $todayDoneOnM2 = $this->entry(['production_date' => '2026-08-10', 'work_center_id' => $this->m2->id]);
        $this->entry(['production_date' => '2026-08-10', 'batch_status' => BatchStatus::InProgress, 'quantity_produced' => null]);
        $this->entry(['production_date' => '2026-08-09']);
        $this->entry(['production_date' => '2026-08-10', 'batch_status' => BatchStatus::Cancelled]);

        $response = $this->getJson('/api/v1/production/shift-production-entries?production_date=2026-08-10&batch_status=completed&per_page=100')
            ->assertOk();

        $this->assertSame([$todayDoneOnM2->id, $todayDone->id], array_column($response->json('data'), 'id'));
        $this->assertSame(100, $response->json('meta.per_page'));
        $this->assertSame(1, $response->json('meta.last_page'));
    }

    // ---- per_page and refusals -------------------------------------------

    public function test_per_page_is_bounded_one_to_one_hundred_and_defaults_to_twenty(): void
    {
        foreach (range(1, 3) as $_) {
            $this->entry();
        }

        $this->assertSame(20, $this->getJson('/api/v1/production/shift-production-entries')->assertOk()->json('meta.per_page'));
        $this->assertSame(1, $this->getJson('/api/v1/production/shift-production-entries?per_page=1')->assertOk()->json('meta.per_page'));
        $this->assertSame(100, $this->getJson('/api/v1/production/shift-production-entries?per_page=100')->assertOk()->json('meta.per_page'));
        $this->assertCount(2, $this->getJson('/api/v1/production/shift-production-entries?per_page=2')->assertOk()->json('data'));

        $this->getJson('/api/v1/production/shift-production-entries?per_page=0')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/production/shift-production-entries?per_page=101')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/production/shift-production-entries?per_page=many')->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_a_value_that_could_only_be_a_mistake_is_a_422_not_a_match_or_a_crash(): void
    {
        $this->entry();

        $this->getJson('/api/v1/production/shift-production-entries?production_date=yesterday')->assertStatus(422)->assertJsonValidationErrors('production_date');
        $this->getJson('/api/v1/production/shift-production-entries?production_date=10-08-2026')->assertStatus(422)->assertJsonValidationErrors('production_date');
        $this->getJson('/api/v1/production/shift-production-entries?date_from=2026-08-11&date_to=2026-08-10')->assertStatus(422)->assertJsonValidationErrors('date_to');
        $this->getJson('/api/v1/production/shift-production-entries?work_center_id=one')->assertStatus(422)->assertJsonValidationErrors('work_center_id');
        $this->getJson('/api/v1/production/shift-production-entries?shift_id=0')->assertStatus(422)->assertJsonValidationErrors('shift_id');
        $this->getJson('/api/v1/production/shift-production-entries?batch_status=done')->assertStatus(422)->assertJsonValidationErrors('batch_status');
        // A bad approval status used to reach the enum's from() and 500.
        $this->getJson('/api/v1/production/shift-production-entries?status=bogus')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_an_unknown_query_key_is_ignored(): void
    {
        $entry = $this->entry();

        $this->assertSame([$entry->id], $this->ids('colour=amber&sort=-id&foo=bar'));
    }

    public function test_an_empty_filter_value_is_no_filter(): void
    {
        $entry = $this->entry();

        // The empty-string middleware nulls these; a form that clears its
        // fields still loads the list.
        $this->assertSame([$entry->id], $this->ids('production_date=&work_center_id=&batch_status=&status=&per_page='));
    }

    // ---- tally: the voucher that carries the entry -----------------------

    public function test_tally_links_the_shift_voucher_the_entry_was_stamped_onto(): void
    {
        $entry = $this->entry(['status' => ShiftProductionEntryStatus::Approved]);
        $shiftMate = $this->entry(['status' => ShiftProductionEntryStatus::Approved]);

        // The shift voucher's morph names the SHIFT (it belongs to no single
        // entry); members hang off tally_sync_entry_id.
        $voucher = TallySyncEntry::create([
            'syncable_type' => (new Shift)->getMorphClass(),
            'syncable_id' => $this->day->id,
            'tally_voucher_type' => 'Stock Journal',
            'payload' => ['voucher_number' => 'SJ-20260810-S'.$this->day->id],
            'status' => TallySyncStatus::Pending,
        ]);
        $entry->forceFill(['tally_sync_entry_id' => $voucher->id])->save();
        $shiftMate->forceFill(['tally_sync_entry_id' => $voucher->id])->save();

        $rows = collect($this->getJson('/api/v1/production/shift-production-entries')->assertOk()->json('data'))->keyBy('id');

        foreach ([$entry, $shiftMate] as $member) {
            $link = $rows[$member->id]['tally'];
            $this->assertSame($voucher->id, $link['entry_id']);
            $this->assertSame('Stock Journal', $link['voucher_type']);
            $this->assertSame('pending', $link['status']);
            $this->assertSame('SJ-20260810-S'.$this->day->id, $link['voucher_number']);
            $this->assertNull($link['synced_at']);
            $this->assertSame("/tally-sync?entry={$voucher->id}", $link['link']);
            $this->assertIsArray($link['flags']);
        }
    }

    public function test_tally_links_the_per_entry_voucher_when_no_shift_voucher_carries_it(): void
    {
        $entry = $this->entry(['status' => ShiftProductionEntryStatus::Synced]);
        $unvouchered = $this->entry();

        $voucher = TallySyncEntry::create([
            'syncable_type' => $entry->getMorphClass(),
            'syncable_id' => $entry->id,
            'tally_voucher_type' => 'Manufacturing Journal',
            'payload' => ['voucher_number' => "SPE-{$entry->id}"],
            'status' => TallySyncStatus::Synced,
            'synced_at' => '2026-08-10 12:00:00',
        ]);

        $rows = collect($this->getJson('/api/v1/production/shift-production-entries')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame($voucher->id, $rows[$entry->id]['tally']['entry_id']);
        $this->assertSame('synced', $rows[$entry->id]['tally']['status']);
        $this->assertSame("SPE-{$entry->id}", $rows[$entry->id]['tally']['voucher_number']);
        $this->assertNotNull($rows[$entry->id]['tally']['synced_at']);
        $this->assertNull($rows[$unvouchered->id]['tally']);
    }

    public function test_the_shift_voucher_wins_over_a_stale_per_entry_voucher(): void
    {
        // History: approved under batch granularity, that voucher failed and
        // was dismissed, then swept into a shift voucher. The shift voucher is
        // what carries the entry's lines now.
        $entry = $this->entry(['status' => ShiftProductionEntryStatus::Approved]);
        TallySyncEntry::create([
            'syncable_type' => $entry->getMorphClass(),
            'syncable_id' => $entry->id,
            'tally_voucher_type' => 'Manufacturing Journal',
            'payload' => ['voucher_number' => "SPE-{$entry->id}"],
            'status' => TallySyncStatus::Dismissed,
        ]);
        $shiftVoucher = TallySyncEntry::create([
            'syncable_type' => (new Shift)->getMorphClass(),
            'syncable_id' => $this->day->id,
            'tally_voucher_type' => 'Stock Journal',
            'payload' => ['voucher_number' => 'SJ-20260810-S1'],
            'status' => TallySyncStatus::Pending,
        ]);
        $entry->forceFill(['tally_sync_entry_id' => $shiftVoucher->id])->save();

        $row = collect($this->getJson('/api/v1/production/shift-production-entries')->assertOk()->json('data'))->firstWhere('id', $entry->id);

        $this->assertSame($shiftVoucher->id, $row['tally']['entry_id']);
    }

    public function test_tally_links_cost_a_constant_number_of_queries_per_page(): void
    {
        $tallyQueries = function (int $rows): int {
            ShiftProductionEntry::query()->forceDelete();
            TallySyncEntry::query()->forceDelete();
            foreach (range(1, $rows) as $i) {
                $entry = $this->entry(['status' => ShiftProductionEntryStatus::Approved]);
                if ($i % 2 === 0) {
                    $voucher = TallySyncEntry::create([
                        'syncable_type' => (new Shift)->getMorphClass(),
                        'syncable_id' => $this->day->id,
                        'tally_voucher_type' => 'Stock Journal',
                        'payload' => ['voucher_number' => "SJ-{$i}"],
                        'status' => TallySyncStatus::Pending,
                    ]);
                    $entry->forceFill(['tally_sync_entry_id' => $voucher->id])->save();
                } else {
                    TallySyncEntry::create([
                        'syncable_type' => $entry->getMorphClass(),
                        'syncable_id' => $entry->id,
                        'tally_voucher_type' => 'Manufacturing Journal',
                        'payload' => ['voucher_number' => "SPE-{$entry->id}"],
                        'status' => TallySyncStatus::Pending,
                    ]);
                }
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->getJson('/api/v1/production/shift-production-entries?per_page=100')->assertOk();
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            $this->assertCount($rows, $response->json('data'));
            foreach ($response->json('data') as $row) {
                $this->assertNotNull($row['tally'], 'every row on this page has a voucher');
            }

            return count(array_filter($log, fn (array $query) => str_contains($query['query'], 'tally_sync_entries')));
        };

        $forTwo = $tallyQueries(2);
        $forEight = $tallyQueries(8);

        // The eager-load in paginate() plus the link service's own reads —
        // however many, the same number for 8 rows as for 2.
        $this->assertSame($forTwo, $forEight, 'tally_sync_entries reads must not grow with the page');
        $this->assertLessThanOrEqual(3, $forEight);
    }

    public function test_a_single_entry_response_carries_the_same_tally_key(): void
    {
        $entry = $this->entry(['batch_status' => BatchStatus::InProgress, 'quantity_produced' => null]);

        // A single-resource door (cancel) — the key is there, null: no
        // voucher exists for a batch that never completed.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cancel", ['reason' => 'started on the wrong machine'])
            ->assertOk()
            ->assertJsonPath('data.tally', null);
    }
}
