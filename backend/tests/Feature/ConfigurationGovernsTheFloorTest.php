<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The connection between the Configuration page and the Shift Floor.
 *
 * One precedence chain, everywhere: APPROVED machine configuration → factory
 * product standard → item master. The batch snapshot always honoured it; these
 * tests pin down that the PREVIEW and the READINESS GATE honour the same chain,
 * because a screen quoting the standard's figures while the run uses the
 * machine's own is the screen disagreeing with the gate.
 *
 * Approval is the switch. A draft row — including all 46 seeded from the daily
 * sheets — changes nothing anywhere. The moment a person approves it on the
 * Configuration page, every surface follows: preview figures, readiness
 * verdict, and the started batch's frozen snapshot.
 */
class ConfigurationGovernsTheFloorTest extends TestCase
{
    use RefreshDatabase;

    private WorkCenter $machine;

    private Item $item;

    private Warehouse $warehouse;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = WorkCenter::create(['name' => 'Machine 6', 'code' => 'M6', 'is_active' => true]);
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        // Item-master figures present, so every fallback tier is real and a
        // configuration override is DISTINGUISHABLE from the fallback.
        $this->item = Item::create([
            'sku' => 'B.200 Ml Brute Pet Bottle Amber-18gms',
            'name' => 'B.200 Ml Brute Pet Bottle Amber-18gms',
            'uom' => 'NOS', 'is_active' => true, 'colour' => 'Amber',
            'standard_cycle_time' => '20.00', 'standard_cavities' => 2,
            'nominal_weight_grams' => '18.0000', 'nos_per_box' => 490,
            'tally_stock_item_guid' => 'itm-brute',
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);
    }

    private function config(ConfigurationStatus $status): ProductionConfiguration
    {
        return ProductionConfiguration::create([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->item->id,
            'colour' => 'Amber',
            'default_cycle_time' => '15.10',
            'default_cavities' => 4,
            'unit_weight_grams' => '18.0000',
            'status' => $status,
            'source' => 'DAILY-PRODUCTION-REVIEW',
        ]);
    }

    private function preview(): array
    {
        return $this->getJson(
            '/api/v1/production/shift-production-entries/preview'
            ."?item_id={$this->item->id}&work_center_id={$this->machine->id}"
            ."&warehouse_id={$this->warehouse->id}&shift_id={$this->shift->id}"
        )->assertOk()->json('data');
    }

    public function test_an_approved_configuration_drives_the_preview_figures(): void
    {
        $this->config(ConfigurationStatus::Approved);

        $data = $this->preview();

        // The machine's own CT and cavities, not the item master's 20s / 2.
        $this->assertSame('15.10', (string) $data['estimation']['standard_cycle_time']);
        $this->assertSame(4, $data['estimation']['active_cavities']);
        // And the response names its source, so the screen can say so.
        $this->assertNotNull($data['configuration']);
        $this->assertSame(4, $data['configuration']['default_cavities']);
    }

    public function test_a_draft_configuration_changes_nothing_on_the_preview(): void
    {
        // The 46 seeded rows are drafts. Until a person approves one, the
        // preview must keep quoting the standard/item chain.
        $this->config(ConfigurationStatus::Draft);

        $data = $this->preview();

        $this->assertSame('20.00', (string) $data['estimation']['standard_cycle_time']);
        $this->assertNull($data['configuration']);
    }

    public function test_an_approved_configuration_satisfies_the_readiness_gate(): void
    {
        // Wipe the item-master figures: only the approved configuration knows
        // the CT, cavities and weight. The gate must judge the figures the run
        // will actually use — before this wiring it refused a product whose
        // approved configuration carried the very cycle time it reported
        // missing.
        $this->item->update(['standard_cycle_time' => null, 'standard_cavities' => null, 'nominal_weight_grams' => null]);
        $this->config(ConfigurationStatus::Approved);
        config()->set('production.readiness.enforced', true);

        $data = $this->preview();

        $codes = array_column($data['readiness']['blocking'] ?? [], 'code');
        $this->assertNotContains('cycle_time', $codes);
        $this->assertNotContains('cavities', $codes);
        $this->assertNotContains('weight', $codes);
    }

    /**
     * One product standard for the item, so the preview resolves a standard and
     * warningsFor() reaches the machine-settings notice at all. Without this the
     * two tests below pass for the wrong reason — no standard means an early
     * return long before the notice is considered.
     */
    private function standard(): ProductionStandard
    {
        return ProductionStandard::create([
            'item_id' => $this->item->id,
            'source_product_name' => 'B.200 Ml Brute Pet Bottle Amber-18gms',
            'cavities' => 2,
            'unit_weight_grams' => '18.0000',
            'cycle_time' => '20.00',
            'status' => 'approved',
        ]);
    }

    public function test_running_on_the_product_standard_raises_no_notice_at_all(): void
    {
        // Running on the factory product standard is the NORMAL case. It was
        // warned about twice, in two wordings, and the owner read both as
        // errors — because to the person interrupted, a yellow box IS an
        // error. Normality is silent; the green banner appears only when a
        // machine's own approved figures take over.
        $this->standard();

        $data = $this->preview();

        $this->assertNull($data['configuration']);
        $this->assertSame(
            [],
            $data['warnings'],
            'A product with a complete standard and no machine override must start without a single notice.',
        );
    }

    public function test_an_approved_configuration_is_announced_not_warned(): void
    {
        $this->standard();
        $this->config(ConfigurationStatus::Approved);

        $data = $this->preview();

        // The override is visible as data for the green banner — never as a
        // warning entry.
        $this->assertNotNull($data['configuration']);
        $this->assertSame([], $data['warnings']);
    }

    public function test_the_started_batch_snapshots_the_approved_figures(): void
    {
        // End of the chain: what the preview promised is what the batch
        // freezes. This is the same precedence the snapshot always had — the
        // assertion is here so the three surfaces are pinned TOGETHER.
        $this->config(ConfigurationStatus::Approved);

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'shift_id' => $this->shift->id,
            'production_date' => '2026-07-31',
        ], null);

        $this->assertSame('15.10', (string) $entry->standard_cycle_time);
        $this->assertSame(4, $entry->active_cavities);
        $this->assertSame('configuration', $entry->cycle_time_source);
    }
}
