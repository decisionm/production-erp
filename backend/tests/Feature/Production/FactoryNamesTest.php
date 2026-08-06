<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Database\Seeders\ShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Calling the machines and shifts what the factory calls them.
 *
 * Their paperwork writes ASB-1 to ASB-10 and shifts A, B and C. This database
 * shipped with MC-01 to MC-10 and Morning/Afternoon/Night, so a supervisor
 * holding the paper and reading the screen was reading two vocabularies for one
 * factory.
 *
 * The rename touches LIVE master data that production entries point at, so what
 * these tests mostly prove is what does NOT move.
 */
class FactoryNamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.enforced' => false]);
    }

    private function rename(bool $write = true): void
    {
        $this->artisan('production:rename-to-factory-names', $write ? ['--write' => true] : [])
            ->assertSuccessful();
    }

    public function test_machines_take_the_codes_the_paperwork_uses(): void
    {
        foreach (range(1, 10) as $n) {
            WorkCenter::create(['code' => 'MC-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'name' => "Machine {$n}"]);
        }

        $this->rename();

        // ASB-1, not ASB-01 — the paperwork writes single digits bare.
        $this->assertSame('ASB-1', WorkCenter::query()->where('name', 'Machine 1')->value('code'));
        $this->assertSame('ASB-10', WorkCenter::query()->where('name', 'Machine 10')->value('code'));
    }

    public function test_the_machines_office_name_is_left_alone(): void
    {
        // This deployment distinguishes the two on purpose — "the floor calls
        // machines by code and the office by name" — and the screens render
        // "Machine 3 (MC-03)". Renaming the name too would delete one
        // vocabulary to add the other.
        WorkCenter::create(['code' => 'MC-03', 'name' => 'Machine 3']);

        $this->rename();

        $machine = WorkCenter::query()->sole();
        $this->assertSame('ASB-3', $machine->code);
        $this->assertSame('Machine 3', $machine->name);
    }

    public function test_shifts_take_their_letters_from_their_start_times(): void
    {
        Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        Shift::create(['name' => 'Afternoon', 'start_time' => '14:00', 'end_time' => '22:00']);
        Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00']);

        $this->rename();

        // A is the day's FIRST shift. Keyed on start time rather than on the
        // name being replaced, so the assumption is auditable.
        // "Shift A", not a bare "A". A cell containing only a letter — in a
        // report column, on an approval row — is a letter, not a shift.
        $this->assertSame('Shift A', Shift::query()->where('start_time', 'like', '06:00%')->value('name'));
        $this->assertSame('Shift B', Shift::query()->where('start_time', 'like', '14:00%')->value('name'));
        $this->assertSame('Shift C', Shift::query()->where('start_time', 'like', '22:00%')->value('name'));
    }

    public function test_a_seeder_made_duplicate_is_deactivated_and_the_original_keeps_the_name(): void
    {
        // WHAT ACTUALLY HAPPENED ON THE LIVE FACTORY. ShiftSeeder ran on every
        // deploy keyed on NAME, so renaming Morning to "Shift A" left no
        // "Morning" for it to find and it created one — every deploy, until the
        // floor's picker offered six shifts for three. The owner spotted it:
        // "still A, B C also there, morning afternoon also there".
        $original = Shift::create(['name' => 'A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $twin = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);

        $this->rename();

        // The OLDEST row at a start time is the real one — production has been
        // pointing at it all along — so it takes the name.
        $this->assertSame('Shift A', $original->fresh()->name);
        // The twin is switched off, not deleted: deleting a row anything might
        // reference trades a cosmetic problem for a broken one.
        $this->assertFalse((bool) $twin->fresh()->is_active);
        $this->assertSame(2, Shift::query()->count());
        $this->assertSame(1, Shift::query()->where('is_active', true)->count());
    }

    public function test_a_duplicate_carrying_production_is_merged_into_the_survivor(): void
    {
        // The first version REFUSED to touch a duplicate with production against
        // it. That guard was right to exist and wrong to stop there — the owner's
        // reply was "THERE IS NOT NIGHT, SHIFT A TO C", with a picker still
        // offering four shifts for three.
        //
        // Two rows at 22:00 are ONE shift. A batch filed against either ran on the
        // 22:00 shift, so repointing it at the survivor loses nothing and asserts
        // nothing new. That is why this is a merge and not a deletion.
        $primary = Shift::create(['name' => 'C', 'start_time' => '22:00', 'end_time' => '06:00', 'is_active' => true]);
        $twin = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'is_active' => true]);

        $warehouse = Warehouse::create([
            'code' => 'SWA', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true,
            'tally_guid' => '7cabb80e-0000-0000-0000-00000000003e',
        ]);
        $machine = WorkCenter::create(['code' => 'ASB-1', 'name' => 'ASB-1', 'is_active' => true]);
        $product = Item::create(['sku' => 'BTL', 'name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $twin->id,
            'work_center_id' => $machine->id,
            'item_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-08-06',
            'batch_number' => '20260806-M01-001',
            'batch_status' => 'completed',
            'status' => 'pending',
        ]);

        $this->rename();

        // The batch now belongs to the surviving shift, which is named properly.
        $this->assertSame($primary->id, $entry->fresh()->shift_id);
        $this->assertSame('Shift C', $primary->fresh()->name);
        // And the duplicate is switched off, not deleted — nothing that ever
        // referenced it is left pointing at a missing row.
        $this->assertFalse((bool) $twin->fresh()->is_active);
        $this->assertNotNull(Shift::query()->find($twin->id));
    }

    public function test_a_merge_that_would_overwrite_a_days_summary_is_refused(): void
    {
        // shift_summaries is unique on (shift_id, production_date). If both shifts
        // hold a summary for one day, repointing collides — and forcing it would
        // silently discard a day's summary. Refusing the whole merge keeps the
        // duplicate visible and the history intact, which is the safe direction.
        $primary = Shift::create(['name' => 'C', 'start_time' => '22:00', 'end_time' => '06:00', 'is_active' => true]);
        $twin = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'is_active' => true]);

        foreach ([$primary, $twin] as $shift) {
            \DB::table('shift_summaries')->insert([
                'shift_id' => $shift->id,
                'production_date' => '2026-08-06',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->rename();

        $this->assertTrue((bool) $twin->fresh()->is_active, 'A merge that would lose a summary must not happen.');
        $this->assertSame(2, \DB::table('shift_summaries')->count());
    }

    public function test_the_seeder_no_longer_duplicates_a_renamed_shift(): void
    {
        // The root cause, fixed at source. firstOrCreate(['name' => 'Morning'])
        // was idempotent on its own terms and still duplicated data — because
        // IDEMPOTENT ON A MUTABLE FIELD IS NOT IDEMPOTENT. A name is renamed; a
        // start time is what the shift is.
        Shift::create(['name' => 'Shift A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);

        $this->seed(ShiftSeeder::class);

        // The 06:00 shift is recognised by its start time and left alone; only
        // the two genuinely missing shifts are created.
        $this->assertSame(3, Shift::query()->count());
        $sixAm = Shift::query()->get()->filter(fn ($s) => str_starts_with((string) $s->start_time, '06:00'));
        $this->assertCount(1, $sixAm);
        $this->assertSame('Shift A', $sixAm->first()->name);
    }

    public function test_the_batch_number_a_machine_mints_does_not_change(): void
    {
        // THE CLAIM MOST WORTH PROVING, because it looks like the obvious hazard.
        // generateBatchNumber() takes the first run of digits from the code and
        // left-pads it to two, so MC-01 gives "01" and ASB-1 gives "1" padded to
        // "01" — the same tag. If that ever stopped being true, a day's batches
        // would silently change shape mid-rename.
        $warehouse = Warehouse::create([
            'code' => 'SWA', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true,
            'tally_guid' => '7cabb80e-0000-0000-0000-00000000003e',
        ]);
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $product = Item::create(['sku' => 'BTL', 'name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);
        $before = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
        $after = WorkCenter::create(['code' => 'ASB-2', 'name' => 'Machine 2', 'is_active' => true]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);

        foreach ([$before, $after] as $machine) {
            $this->postJson('/api/v1/production/shift-production-entries', [
                'shift_id' => $shift->id,
                'work_center_id' => $machine->id,
                'item_id' => $product->id,
                'production_date' => '2026-08-05',
            ])->assertOk();
        }

        $numbers = ShiftProductionEntry::query()->orderBy('id')->pluck('batch_number')->all();

        // MC-01 and ASB-2 both mint a zero-padded two-digit tag.
        $this->assertMatchesRegularExpression('/^20260805-M01-\d{3}$/', $numbers[0]);
        $this->assertMatchesRegularExpression('/^20260805-M02-\d{3}$/', $numbers[1]);

        $this->assertNotNull($warehouse->id);
    }

    public function test_the_five_demo_work_centres_are_not_touched(): void
    {
        // Not this factory's machines. Whether they should be selectable at all
        // is a separate question; a rename is not the place to answer it.
        foreach (['INJ-01', 'BLOW-01', 'EBM-01', 'LABEL-01', 'PACK-01'] as $code) {
            WorkCenter::create(['code' => $code, 'name' => $code]);
        }

        $this->rename();

        $this->assertSame(
            ['BLOW-01', 'EBM-01', 'INJ-01', 'LABEL-01', 'PACK-01'],
            WorkCenter::query()->orderBy('code')->pluck('code')->all(),
        );
    }

    public function test_a_name_the_factory_chose_itself_is_never_overwritten(): void
    {
        // The rule every command of this shape follows here: a computed answer
        // must not replace a human's. "General" is not one of the three names
        // this deployment shipped, so somebody chose it.
        Shift::create(['name' => 'General', 'start_time' => '06:00', 'end_time' => '14:00']);
        WorkCenter::create(['code' => 'ASB-SPECIAL', 'name' => 'Special']);

        $this->rename();

        $this->assertSame('General', Shift::query()->sole()->name);
        $this->assertSame('ASB-SPECIAL', WorkCenter::query()->sole()->code);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        WorkCenter::create(['code' => 'MC-04', 'name' => 'Machine 4']);
        Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00']);

        $this->rename();
        $first = [WorkCenter::query()->sole()->code, Shift::query()->sole()->name];

        $this->rename();

        $this->assertSame($first, [WorkCenter::query()->sole()->code, Shift::query()->sole()->name]);
        $this->assertSame(['ASB-4', 'Shift C'], $first);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        WorkCenter::create(['code' => 'MC-07', 'name' => 'Machine 7']);
        Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);

        $this->rename(write: false);

        $this->assertSame('MC-07', WorkCenter::query()->sole()->code);
        $this->assertSame('Morning', Shift::query()->sole()->name);
    }

    public function test_a_collision_is_refused_rather_than_thrown(): void
    {
        // work_centers.code is unique. Renaming MC-01 into an ASB-1 that already
        // exists must report and move on, not abort the whole run with a
        // constraint violation half way through the machines.
        WorkCenter::create(['code' => 'ASB-1', 'name' => 'Machine 1']);
        WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1 duplicate']);
        WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2']);

        $this->rename();

        // The collision is left, and the machine after it is still renamed.
        $this->assertSame('MC-01', WorkCenter::query()->where('name', 'Machine 1 duplicate')->value('code'));
        $this->assertSame('ASB-2', WorkCenter::query()->where('name', 'Machine 2')->value('code'));
    }
}
