<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Dating a batch for a day that has already happened.
 *
 * THE FAILURE THIS FIXES. The API always accepted `production_date`; the screen
 * never offered it. So a shift that ran on the 4th, typed up on the 5th, was
 * recorded on the 5th — landing in the wrong day's Tally voucher and the wrong
 * day's report, with nothing on screen to say so. Reported from the floor
 * during live use (05-Aug).
 *
 * Backdating is ordinary factory work, not an error to prevent. What must be
 * prevented is the two ways a date goes wrong by accident: a future date, and a
 * mistyped month that reaches back into a closed period.
 */
class BatchBackdatingTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    private WorkCenter $machine;

    private Item $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-month, so "the 1st of this month" and "a few days ago" are
        // genuinely different dates and the window is actually exercised.
        Carbon::setTestNow('2026-08-05 09:00:00');

        config(['production.enforced' => false]);
        // The factory's chosen window. Off by default so the API keeps the
        // permissive contract its other callers rely on; this suite is about
        // what happens when a factory turns it on.
        config(['production.backdate_limit' => 'month']);

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
        $this->product = Item::create(['sku' => 'BTL', 'name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);
        // Tally-linked and the only one, so FactoryWarehouseResolver can answer
        // where finished bottles land — the floor is never asked this.
        Warehouse::create([
            'code' => 'SWA', 'name' => 'SWAASHPET POLYMERS PVT LTD',
            'is_active' => true, 'tally_guid' => '7cabb80e-0000-0000-0000-00000000003e',
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function start(?string $date, ?WorkCenter $machine = null)
    {
        return $this->postJson('/api/v1/production/shift-production-entries', array_filter([
            'shift_id' => $this->shift->id,
            'work_center_id' => ($machine ?? $this->machine)->id,
            'item_id' => $this->product->id,
            'production_date' => $date,
        ], fn ($v) => $v !== null));
    }

    public function test_yesterdays_shift_can_be_entered_today(): void
    {
        // The exact case from the floor: the work happened on the 4th, somebody
        // types it on the 5th, and it must be recorded as the 4th.
        $this->start('2026-08-04')->assertOk();

        $this->assertSame('2026-08-04', ShiftProductionEntry::query()->sole()->production_date->toDateString());
    }

    public function test_any_earlier_day_this_month_is_accepted(): void
    {
        $this->start('2026-08-01')->assertOk();

        $this->assertSame('2026-08-01', ShiftProductionEntry::query()->sole()->production_date->toDateString());
    }

    public function test_today_is_still_accepted(): void
    {
        $this->start('2026-08-05')->assertOk();

        $this->assertSame('2026-08-05', ShiftProductionEntry::query()->sole()->production_date->toDateString());
    }

    public function test_a_future_date_is_refused(): void
    {
        // Production that has not happened cannot be recorded.
        $this->start('2026-08-06')
            ->assertStatus(422)
            ->assertJsonValidationErrors('production_date')
            ->assertSee('in the future', false);

        $this->assertSame(0, ShiftProductionEntry::query()->count());
    }

    public function test_a_mistyped_year_is_refused_rather_than_recorded(): void
    {
        // The realistic fat-finger: right day, wrong year. Without the ceiling
        // this becomes a batch nobody finds until the year turns.
        $this->start('2027-08-04')->assertStatus(422)->assertJsonValidationErrors('production_date');

        $this->assertSame(0, ShiftProductionEntry::query()->count());
    }

    public function test_a_date_well_before_the_window_is_refused_with_a_useful_message(): void
    {
        // A mistyped month must not silently reopen a period the accountant has
        // closed — and the message has to say what IS allowed, because the
        // person reading it is a supervisor at 6am.
        //
        // 20 July, not 31 July: the 'month' window deliberately also reaches a
        // week back, so the last days of last month are still enterable. This
        // date is outside both halves of it.
        $this->start('2026-07-20')
            ->assertStatus(422)
            ->assertJsonValidationErrors('production_date')
            ->assertSee('2026-07-29', false);

        $this->assertSame(0, ShiftProductionEntry::query()->count());
    }

    public function test_with_no_limit_configured_a_historical_date_is_still_accepted(): void
    {
        // The default, and the contract every other caller depends on: a
        // migration seeding last quarter, an integration replaying a month.
        config(['production.backdate_limit' => 'none']);

        $this->start('2026-05-20')->assertOk();

        $this->assertSame('2026-05-20', ShiftProductionEntry::query()->sole()->production_date->toDateString());
    }

    public function test_a_future_date_is_refused_even_with_no_limit_configured(): void
    {
        // The one rule that is never the factory's to switch off.
        config(['production.backdate_limit' => 'none']);

        $this->start('2026-08-06')->assertStatus(422)->assertJsonValidationErrors('production_date');
    }

    public function test_the_month_window_still_reaches_back_a_week_at_a_month_boundary(): void
    {
        // On the 2nd, a strict month floor would refuse last night's shift —
        // the exact entry this feature exists to allow.
        Carbon::setTestNow('2026-08-02 07:00:00');

        $this->start('2026-08-01')->assertOk();
        $this->start('2026-07-30', WorkCenter::create([
            'code' => 'MC-03', 'name' => 'Machine 3', 'is_active' => true,
        ]))->assertOk();
    }

    public function test_a_configured_rolling_window_replaces_the_month_floor(): void
    {
        config(['production.backdate_limit' => 3]);

        $this->start('2026-08-02')->assertOk();                // inside 3 days
        $this->start('2026-08-01', WorkCenter::create([        // outside it
            'code' => 'MC-02', 'name' => 'Machine 2', 'is_active' => true,
        ]))->assertStatus(422)->assertJsonValidationErrors('production_date');
    }

    public function test_omitting_the_date_still_defaults_to_the_shift_aware_today(): void
    {
        // The unchanged common path: the floor does not type a date at all.
        $this->start(null)->assertOk();

        $this->assertSame('2026-08-05', ShiftProductionEntry::query()->sole()->production_date->toDateString());
    }
}
