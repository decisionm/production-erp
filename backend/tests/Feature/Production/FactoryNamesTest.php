<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
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
        $this->assertSame('A', Shift::query()->where('start_time', 'like', '06:00%')->value('name'));
        $this->assertSame('B', Shift::query()->where('start_time', 'like', '14:00%')->value('name'));
        $this->assertSame('C', Shift::query()->where('start_time', 'like', '22:00%')->value('name'));
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
        $this->assertSame(['ASB-4', 'C'], $first);
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
