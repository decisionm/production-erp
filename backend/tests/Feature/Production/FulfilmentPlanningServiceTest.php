<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Services\FulfilmentPlanningService;
use App\Modules\Production\Services\ProductionRequestService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHEN THE FACTORY COULD HAVE IT — and, far more often, an honest refusal
 * to say.
 *
 * Two rules are being pinned here:
 *
 *   S12, THE CASCADE. A product with no usable standard loses its own date
 *   AND every date behind it in the queue, because nobody can say how long
 *   an unestimable job will hold the line. No date, and no caveat-date.
 *
 *   THE BASIS IS PUBLISHED. Every ETA carries the figures it was computed
 *   from — shifts per day, hours in a shift, parallel lines, timezone — so
 *   a date the floor disagrees with can be argued about in numbers instead
 *   of being taken on faith.
 *
 * Nothing here is persisted: there is no ETA column anywhere (S11).
 */
class FulfilmentPlanningServiceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $fg;

    private Customer $customer;

    private User $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = User::factory()->create(['name' => 'Storekeeper', 'is_active' => true]);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);

        // The factory's three 8-hour shifts. Summed, not one taken three
        // times — a 10-hour day shift beside two 8-hour ones must not turn
        // a 26-hour day into a 30-hour one.
        Shift::create(['name' => 'Shift A', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'is_active' => true]);
        Shift::create(['name' => 'Shift B', 'start_time' => '14:00:00', 'end_time' => '22:00:00', 'is_active' => true]);
        Shift::create(['name' => 'Shift C', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'is_active' => true]);
    }

    public function test_the_basis_says_what_the_dates_were_computed_from(): void
    {
        $basis = app(FulfilmentPlanningService::class)->plan()['basis'];

        $this->assertSame([
            'shifts_per_day' => 3,
            'parallel_lines' => 1,
            'shift_hours' => '8.0000',
            'timezone' => 'Asia/Kolkata',
            'source' => 'active_shifts',
        ], $basis);
    }

    public function test_a_deactivated_shift_leaves_the_factorys_day(): void
    {
        Shift::query()->where('name', 'Shift C')->update(['is_active' => false]);

        $basis = app(FulfilmentPlanningService::class)->plan()['basis'];

        $this->assertSame(2, $basis['shifts_per_day']);
        $this->assertSame('8.0000', $basis['shift_hours']);
    }

    public function test_a_product_with_a_standard_is_dated(): void
    {
        // 8 hours ÷ 10s cycle = 2880 shots, × 4 cavities = 11 520 a shift.
        $bottle = $this->item('BTL-500', '10.00', 4);
        $this->request($bottle, '20000');

        $row = app(FulfilmentPlanningService::class)->plan()['data'][0];

        $this->assertFalse($row['cannot_estimate']);
        $this->assertNull($row['reason']);
        $this->assertSame(11520, $row['capacity_per_shift']);
        $this->assertSame(2, $row['shifts_needed']);
        $this->assertNotNull($row['estimated_ready_date']);
        $this->assertSame(0, $row['queued_ahead']);
    }

    /**
     * S12 — THE CASCADE, and the two reasons it needs.
     *
     * The head of the queue is a product with no cycle time on its standard
     * or its item master: it cannot be estimated at all, and it says so in
     * its own words. What is BEHIND it cannot carry that reason — nothing is
     * ahead of the first row — so it gets the cascade reason instead, and
     * neither of them gets a date.
     */
    public function test_an_unestimable_product_takes_the_dates_of_everything_behind_it(): void
    {
        $mystery = $this->item('NEW-JAR', null, null);
        $known = $this->item('BTL-500', '10.00', 4);

        $this->request($mystery, '5000');
        $this->request($known, '5000');

        $rows = app(FulfilmentPlanningService::class)->plan()['data'];

        $this->assertTrue($rows[0]['cannot_estimate']);
        $this->assertSame(FulfilmentPlanningService::REASON_NO_STANDARD, $rows[0]['reason']);
        $this->assertNull($rows[0]['estimated_ready_date']);
        $this->assertNull($rows[0]['capacity_per_shift']);
        $this->assertNull($rows[0]['shifts_needed']);

        $this->assertTrue($rows[1]['cannot_estimate']);
        $this->assertSame(FulfilmentPlanningService::REASON_ITEMS_AHEAD, $rows[1]['reason']);
        $this->assertNull($rows[1]['estimated_ready_date']);
        // Not even a "we could have done it in one shift" hint: the factory
        // cannot start it until the job in front is off the machine.
        $this->assertNull($rows[1]['shifts_needed']);
    }

    /**
     * The cascade is about ORDER, not about the product. Put the estimable
     * job first and it keeps its date; only what follows the unknown one
     * loses theirs.
     */
    public function test_a_job_ahead_of_the_unknown_one_keeps_its_date(): void
    {
        $known = $this->item('BTL-500', '10.00', 4);
        $mystery = $this->item('NEW-JAR', null, null);

        $this->request($known, '5000');
        $this->request($mystery, '5000');

        $rows = app(FulfilmentPlanningService::class)->plan()['data'];

        $this->assertFalse($rows[0]['cannot_estimate']);
        $this->assertNotNull($rows[0]['estimated_ready_date']);
        $this->assertTrue($rows[1]['cannot_estimate']);
        $this->assertSame(FulfilmentPlanningService::REASON_NO_STANDARD, $rows[1]['reason']);
    }

    public function test_with_no_active_shift_nothing_can_be_planned(): void
    {
        Shift::query()->update(['is_active' => false]);
        $this->request($this->item('BTL-500', '10.00', 4), '5000');

        $plan = app(FulfilmentPlanningService::class)->plan();

        $this->assertSame(0, $plan['basis']['shifts_per_day']);
        $this->assertSame('no_active_shifts', $plan['basis']['source']);
        $this->assertNull($plan['basis']['shift_hours']);
        $this->assertTrue($plan['data'][0]['cannot_estimate']);
        $this->assertSame(FulfilmentPlanningService::REASON_NO_SHIFT_HOURS, $plan['data'][0]['reason']);
        $this->assertSame([], $plan['today_targets']);
    }

    public function test_todays_targets_are_the_head_of_the_queue(): void
    {
        // Frozen before the first shift, so all three of today's slots are
        // still ahead — the un-frozen version of this test silently changed
        // meaning with the wall clock (Codex P2, PR #33: "today" is the
        // slots actually LEFT today, not a day's worth from wherever now
        // happens to fall).
        $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00', config('tally-sync.factory_timezone')));

        $bottle = $this->item('BTL-500', '10.00', 4);

        // Each of these fits comfortably inside one shift, so the first
        // three fill the day's three shifts and the fourth does not.
        $first = $this->request($bottle, '1000');
        $second = $this->request($bottle, '1000');
        $third = $this->request($bottle, '1000');
        $this->request($bottle, '1000');

        $targets = app(FulfilmentPlanningService::class)->plan()['today_targets'];

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            array_column($targets, 'request_id'),
        );
    }

    /** From the 22:00 boundary, today has ONE slot left — so one target. */
    public function test_todays_targets_shrink_as_the_day_is_spent(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', config('tally-sync.factory_timezone')));

        $bottle = $this->item('BTL-500', '10.00', 4);
        $first = $this->request($bottle, '1000');
        $this->request($bottle, '1000');

        $targets = app(FulfilmentPlanningService::class)->plan()['today_targets'];

        $this->assertSame([$first->id], array_column($targets, 'request_id'));
    }

    /**
     * THE CALENDAR IS WALKED THROUGH REAL SHIFT BOUNDARIES (Codex P1 on
     * PR #33). A two-shift job planned from the 14:00 boundary runs 14:00 to
     * 22:00 and 22:00 to 06:00 — it is in hand at six the NEXT morning, and
     * the old arithmetic (whole days per shifts_per_day shifts) dated it
     * today.
     */
    public function test_a_job_crossing_midnight_is_dated_tomorrow(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', config('tally-sync.factory_timezone')));

        $bottle = $this->item('BTL-500', '10.00', 4); // 11 520 a shift
        $this->request($bottle, '20000');             // 2 shifts

        $row = app(FulfilmentPlanningService::class)->plan()['data'][0];

        $this->assertSame(2, $row['shifts_needed']);
        // Boundary 14:00 today; finishes at 06:00 on the 2nd.
        $this->assertSame('2026-09-02', $row['estimated_ready_date']);
    }

    /**
     * EACH SHIFT'S OWN LENGTH COUNTS (Codex P1 on PR #33). With a 10/6/8
     * day, a 9 000-piece job starting on the SIX-hour shift does not fit in
     * it (8 640), spills into the overnight shift, and is in hand tomorrow —
     * the averaged shift the old walk used called it one shift, today.
     */
    public function test_a_short_shift_is_not_padded_to_the_average(): void
    {
        Shift::query()->delete();
        Shift::create(['name' => 'Long', 'start_time' => '06:00:00', 'end_time' => '16:00:00', 'is_active' => true]);
        Shift::create(['name' => 'Short', 'start_time' => '16:00:00', 'end_time' => '22:00:00', 'is_active' => true]);
        Shift::create(['name' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'is_active' => true]);

        $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', config('tally-sync.factory_timezone')));

        $bottle = $this->item('BTL-500', '10.00', 4); // 6h: 8 640, 8h: 11 520
        $this->request($bottle, '9000');

        $row = app(FulfilmentPlanningService::class)->plan()['data'][0];

        $this->assertSame(2, $row['shifts_needed']);
        $this->assertSame('2026-09-02', $row['estimated_ready_date']);
    }

    // ---- fixtures ---------------------------------------------------------

    private function item(string $sku, ?string $cycleTime, ?int $cavities): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => $sku,
            'uom' => 'Nos',
            'standard_cycle_time' => $cycleTime,
            'standard_cavities' => $cavities,
        ]);
    }

    private function request(Item $item, string $quantity)
    {
        return app(ProductionRequestService::class)->createFromShortfall(
            $this->line($item, $quantity),
            $quantity,
            $this->store->id,
        );
    }

    private function line(Item $item, string $quantity): SalesOrderLine
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => '2026-08-20',
        ]);

        return $order->lines()->create([
            'item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => '4.50',
            'quantity_delivered' => '0',
        ]);
    }
}
