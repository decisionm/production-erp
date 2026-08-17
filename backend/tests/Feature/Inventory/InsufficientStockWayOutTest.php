<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The refusal has to name a way out that still exists.
 *
 * DEC-20260817-001 states the factory's logical inventory locations as
 * Raw Material Store -> Production/WIP -> Finished Goods Store and records
 * that there is no Day Bin. The message used to end "enter its opening
 * stock on the Day Bin page" — a route into stock the factory no longer
 * recognises. This pins the replacement, and pins that the old pointer
 * cannot come back.
 */
class InsufficientStockWayOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_refusal_points_at_the_store_issue_flow_and_never_at_the_day_bin_page(): void
    {
        $item = Item::create(['sku' => 'RES-1', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs']);
        $warehouse = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store']);

        $message = InsufficientStockException::forItem(
            $item->id,
            $warehouse->id,
            '0.0000',
            '118.9980',
        )->getMessage();

        // Still names the material, the place and both quantities.
        $this->assertStringContainsString('Billion Pet Resin IV-0.8', $message);
        $this->assertStringContainsString('Raw Material Store', $message);
        $this->assertStringContainsString('0.0000 recorded there', $message);
        $this->assertStringContainsString('118.9980 needed', $message);

        // And names a way out that exists on both sides of the issue:
        // receive it into the store, or bring back what is standing in
        // Production/WIP on the issue that took it there.
        $this->assertStringContainsString('Receive the material against its purchase', $message);
        $this->assertStringContainsString('return the unused part on its store issue', $message);

        $this->assertStringNotContainsString('Day Bin', $message);
        $this->assertStringNotContainsString('opening stock', $message);
    }
}
