<?php

namespace Tests\Feature\TallySync;

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
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE SHIFT SWEEP JUDGES A FIXTURE THE WAY THE TOP-LEVEL GUARD DOES.
 *
 * Under shift granularity a real item's approval sweeps its approved
 * shift-mates into one Stock Journal. The top-level guard
 * (TallySyncService::isLocalFixtureEntry) refuses a local-only fixture on
 * the RESOLVED identity — finished_item_id ?? item_id — honouring either
 * the is_local_fixture column or the LOCAL- prefix. The sweep used to test
 * only the base item's SKU prefix in SQL, so two kinds of fixture slipped
 * into a shift-mate's voucher through the back door (audit §4.11):
 *
 *   Hole B — a real product whose packaging identity is a fixture;
 *   Hole A — a column-flagged fixture whose SKU no longer starts LOCAL-.
 *
 * These pin both holes shut, and pin the two things the sweep must keep
 * doing: real shift-mates still merge, and an entry whose product was
 * retired (soft-deleted) after the run is still swept, not silently dropped.
 *
 * AND THE PREDICATE GOVERNS WHAT IS ON A VOUCHER, NOT ONLY WHAT JOINS. A
 * fixture stamped onto a voucher under the old sweep (the live hole) is
 * replayed by two rebuild paths — the merge rebuild and retry's regenerate —
 * and both must leave its lines off, while its membership stamp is left
 * exactly as it was (the tracker is not rewritten from a read path).
 */
class LocalFixtureSweepTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    private WorkCenter $machine;

    private Item $bottle;

    private Item $resin;

    private Warehouse $fgStore;

    private Warehouse $rmStore;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.approvals.quality_stage_enabled' => false]);
        config(['tally-sync.release_idle_minutes' => 0]);
        config(['tally-sync.voucher_granularity' => 'shift']);

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS']);
        $this->resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'KG']);
        $this->fgStore = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $this->rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'RM Store']);
        $this->approver = User::factory()->create();
    }

    /**
     * An entry of this shift in the given status, with one resin line.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function entry(ShiftProductionEntryStatus $status, string $produced, array $attributes = []): ShiftProductionEntry
    {
        $entry = ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-07-23',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => $produced,
            'quantity_scrap' => '0',
            'status' => $status,
            ...$attributes,
        ]);

        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => '10.0000',
        ]);

        return $entry;
    }

    /**
     * An approved shift-mate that no voucher has claimed — exactly the state
     * a fixture entry is left in after its own approval was declined at the
     * top-level guard, and exactly what the sweep goes looking for.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function approvedUnvoucheredEntry(string $produced, array $attributes = []): ShiftProductionEntry
    {
        return $this->entry(ShiftProductionEntryStatus::Approved, $produced, $attributes);
    }

    private function approve(ShiftProductionEntry $entry): ShiftProductionEntry
    {
        $service = app(ShiftProductionEntryService::class);
        $service->pmApprove($entry, $this->approver->id);

        return $service->accountantApprove($entry->fresh(), User::factory()->create()->id);
    }

    /** @return list<string> the produced-line item names on the sole voucher */
    private function producedNames(TallySyncEntry $voucher): array
    {
        return collect($voucher->payload['produced'])->pluck('item')->all();
    }

    public function test_hole_b_a_real_product_whose_packaging_identity_is_a_fixture_is_not_swept(): void
    {
        $fixtureIdentity = Item::create([
            'sku' => 'LOCAL-500ML-TRAY', 'name' => '500ml Tray Pack (LOCAL FIXTURE)', 'uom' => 'NOS',
            'is_local_fixture' => true,
        ]);

        // A REAL product (BTL-500) whose frozen identity is the fixture. Its
        // base item passes any SKU-prefix test; only the resolved identity
        // says "fixture".
        $fixtureBacked = $this->approvedUnvoucheredEntry('700', ['finished_item_id' => $fixtureIdentity->id]);

        $mate = $this->approve($this->entry(ShiftProductionEntryStatus::Pending, '5000', ['batch_number' => 'B-1']));

        $voucher = TallySyncEntry::query()->sole();
        $this->assertSame([$mate->id], $voucher->payload['entry_ids'], 'The fixture-identity entry must not ride the mate\'s voucher');
        $this->assertNull($fixtureBacked->fresh()->tally_sync_entry_id);
        $this->assertNotContains($fixtureIdentity->name, $this->producedNames($voucher));
        $this->assertSame(
            [['item' => '500ml PET Bottle', 'quantity' => '5000.0000', 'godown' => 'FG Store']],
            $voucher->payload['produced'],
        );
        // Consumption is not dragged in sideways either.
        $this->assertSame('10.0000', $voucher->payload['consumed'][0]['quantity']);
    }

    public function test_hole_a_a_column_flagged_fixture_renamed_off_the_prefix_is_not_swept(): void
    {
        // The SKU programme's case: flagged a fixture, SKU no longer LOCAL-.
        $renamedFixture = Item::create([
            'sku' => 'REAL-001', 'name' => 'Renamed Fixture Bottle', 'uom' => 'NOS',
            'is_local_fixture' => true,
        ]);
        $fixtureEntry = $this->approvedUnvoucheredEntry('900', ['item_id' => $renamedFixture->id]);

        $mate = $this->approve($this->entry(ShiftProductionEntryStatus::Pending, '5000', ['batch_number' => 'B-1']));

        $voucher = TallySyncEntry::query()->sole();
        $this->assertSame([$mate->id], $voucher->payload['entry_ids'], 'A flagged fixture must not ride the mate\'s voucher');
        $this->assertNull($fixtureEntry->fresh()->tally_sync_entry_id);
        $this->assertNotContains($renamedFixture->name, $this->producedNames($voucher));
        $this->assertSame('5000.0000', $voucher->payload['produced'][0]['quantity']);
    }

    public function test_a_prefixed_fixture_is_still_not_swept(): void
    {
        // The case the old SQL form did catch — must keep being caught.
        $prefixed = Item::create(['sku' => 'LOCAL-OLD-STYLE', 'name' => 'Old-style Fixture', 'uom' => 'NOS']);
        $fixtureEntry = $this->approvedUnvoucheredEntry('900', ['item_id' => $prefixed->id]);

        $mate = $this->approve($this->entry(ShiftProductionEntryStatus::Pending, '5000', ['batch_number' => 'B-1']));

        $voucher = TallySyncEntry::query()->sole();
        $this->assertSame([$mate->id], $voucher->payload['entry_ids']);
        $this->assertNull($fixtureEntry->fresh()->tally_sync_entry_id);
    }

    public function test_control_a_real_shift_mate_is_still_swept(): void
    {
        $realMate = $this->approvedUnvoucheredEntry('3000', ['batch_number' => 'B-0']);

        $trigger = $this->approve($this->entry(ShiftProductionEntryStatus::Pending, '5000', ['batch_number' => 'B-1']));

        $voucher = TallySyncEntry::query()->sole();
        $this->assertSame([$realMate->id, $trigger->id], $voucher->payload['entry_ids']);
        $this->assertSame($voucher->id, $realMate->fresh()->tally_sync_entry_id);
        $this->assertSame('8000.0000', $voucher->payload['produced'][0]['quantity']);
        $this->assertSame('20.0000', $voucher->payload['consumed'][0]['quantity']);
    }

    public function test_an_entry_whose_product_was_retired_after_the_run_is_still_swept(): void
    {
        // The product master was soft-deleted after the run; the batch still
        // posts under the live identity it froze at completion. The predicate
        // must not read "no visible base item" as "fixture" and drop it.
        $retired = Item::create(['sku' => 'BTL-OLD', 'name' => 'Retired Bottle', 'uom' => 'NOS']);
        $liveIdentity = Item::create(['sku' => 'BTL-OLD-T', 'name' => 'Retired Bottle - Tray Pack', 'uom' => 'NOS']);
        $survivor = $this->approvedUnvoucheredEntry('1200', [
            'item_id' => $retired->id,
            'finished_item_id' => $liveIdentity->id,
            'batch_number' => 'B-0',
        ]);
        $retired->delete();
        $this->assertNull($survivor->fresh()->item, 'precondition: the base item is no longer visible');

        $trigger = $this->approve($this->entry(ShiftProductionEntryStatus::Pending, '5000', ['batch_number' => 'B-1']));

        $voucher = TallySyncEntry::query()->sole();
        $this->assertSame([$survivor->id, $trigger->id], $voucher->payload['entry_ids']);
        $this->assertSame($voucher->id, $survivor->fresh()->tally_sync_entry_id);
        $this->assertContains('Retired Bottle - Tray Pack', $this->producedNames($voucher));
    }

    public function test_the_predicate_reads_a_fully_retired_item_as_not_a_fixture(): void
    {
        // The single source of truth the sweep now shares with the top-level
        // guard, pinned on the one branch a payload cannot show end to end:
        // effectiveItem() null (base item soft-deleted, no frozen identity)
        // must be "not a fixture", so the sweep keeps such an entry — the
        // guarantee the SQL form's own comment made.
        $retired = Item::create(['sku' => 'BTL-GONE', 'name' => 'Gone Bottle', 'uom' => 'NOS']);
        $entry = $this->approvedUnvoucheredEntry('100', ['item_id' => $retired->id]);
        $retired->delete();

        $entry = $entry->fresh();
        $this->assertNull($entry->effectiveItem());

        $isFixture = (fn () => $this->isLocalFixtureEntry($entry))->call(app(TallySyncService::class));

        $this->assertFalse($isFixture);
    }

    /**
     * The pre-fix state, reproduced by hand: a fixture-identity entry whose
     * membership stamp already names the voucher — what the old prefix-only
     * sweep left behind on live — and a frozen payload that carries its line,
     * exactly as that sweep would have written it.
     */
    private function stampFixtureOnto(TallySyncEntry $voucher, ShiftProductionEntry $fixture, string $fixtureName): void
    {
        ShiftProductionEntry::query()->whereKey($fixture->id)->update(['tally_sync_entry_id' => $voucher->id]);

        $payload = $voucher->payload;
        $payload['entry_ids'] = [...$payload['entry_ids'], $fixture->id];
        $payload['produced'][] = ['item' => $fixtureName, 'quantity' => (string) $fixture->quantity_produced, 'godown' => 'FG Store'];
        $voucher->update(['payload' => $payload]);
    }

    public function test_a_fixture_already_stamped_onto_a_voucher_is_left_off_the_merge_rebuild(): void
    {
        $fixtureIdentity = Item::create([
            'sku' => 'LOCAL-500ML-TRAY', 'name' => '500ml Tray Pack (LOCAL FIXTURE)', 'uom' => 'NOS',
            'is_local_fixture' => true,
        ]);

        $first = $this->approve($this->entry(ShiftProductionEntryStatus::Pending, '5000', ['batch_number' => 'B-1']));
        $voucher = TallySyncEntry::query()->sole();

        $stranded = $this->approvedUnvoucheredEntry('700', ['finished_item_id' => $fixtureIdentity->id, 'batch_number' => 'B-X']);
        $this->stampFixtureOnto($voucher, $stranded, $fixtureIdentity->name);
        $this->assertContains($fixtureIdentity->name, $this->producedNames($voucher->fresh()), 'precondition: the frozen payload carries the fixture line');

        // A later shift-mate joins the still-open voucher — the merge
        // rebuilds the payload from every stamped member.
        $second = $this->approve($this->entry(ShiftProductionEntryStatus::Pending, '3000', ['batch_number' => 'B-2']));

        $rebuilt = TallySyncEntry::query()->sole();
        $this->assertSame([$first->id, $second->id], $rebuilt->payload['entry_ids'], 'the stamped fixture must not ride the rebuilt voucher');
        $this->assertNotContains($fixtureIdentity->name, $this->producedNames($rebuilt));
        $this->assertSame(
            [['item' => '500ml PET Bottle', 'quantity' => '8000.0000', 'godown' => 'FG Store']],
            $rebuilt->payload['produced'],
        );
        $this->assertSame('20.0000', $rebuilt->payload['consumed'][0]['quantity'], 'the fixture\'s consumption stays off too');
        // Membership is left as stamped — the tracker is not rewritten.
        $this->assertSame($voucher->id, $stranded->fresh()->tally_sync_entry_id);
    }

    public function test_a_fixture_already_stamped_onto_a_voucher_is_left_off_the_retry_regenerate(): void
    {
        $fixtureIdentity = Item::create([
            'sku' => 'LOCAL-500ML-TRAY', 'name' => '500ml Tray Pack (LOCAL FIXTURE)', 'uom' => 'NOS',
            'is_local_fixture' => true,
        ]);

        $real = $this->approve($this->entry(ShiftProductionEntryStatus::Pending, '5000', ['batch_number' => 'B-1']));
        $voucher = TallySyncEntry::query()->sole();

        $stranded = $this->approvedUnvoucheredEntry('700', ['finished_item_id' => $fixtureIdentity->id, 'batch_number' => 'B-X']);
        $this->stampFixtureOnto($voucher, $stranded, $fixtureIdentity->name);

        // Tally refused the fixture line, as it always will; staff press Retry.
        $voucher->update([
            'status' => TallySyncStatus::Failed,
            'error_message' => 'Stock Item does not exist',
            'delivered_at' => now(),
        ]);

        app(TallySyncService::class)->retry($voucher->fresh());

        $regenerated = $voucher->fresh();
        $this->assertSame(TallySyncStatus::Pending, $regenerated->status);
        $this->assertSame([$real->id], $regenerated->payload['entry_ids'], 'retry must regenerate without the stamped fixture');
        $this->assertNotContains($fixtureIdentity->name, $this->producedNames($regenerated));
        $this->assertSame(
            [['item' => '500ml PET Bottle', 'quantity' => '5000.0000', 'godown' => 'FG Store']],
            $regenerated->payload['produced'],
        );
        $this->assertSame('10.0000', $regenerated->payload['consumed'][0]['quantity']);
        $this->assertSame($voucher->id, $stranded->fresh()->tally_sync_entry_id, 'membership is left as stamped');
    }
}
