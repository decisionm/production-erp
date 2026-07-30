<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Core\Services\PermissionService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A complete, repeatable local acceptance environment: everything one
 * supervisor needs to run the canonical flow end to end, and nothing that
 * belongs only to a demo.
 *
 * Idempotent throughout — every row is matched on a natural key, so running
 * it twice changes nothing and running it after a partial failure completes
 * the job. LOCAL ONLY: it creates users with a known password and is never
 * invoked by deploy.sh.
 *
 * The 100 kg / 5 kg scenario in the acceptance test is built on this
 * fixture: one resin bag of 100 kg, from which a batch loads 5 kg.
 */
class AcceptanceFixtureSeeder extends Seeder
{
    public const PASSWORD = 'password123';

    public function run(): void
    {
        $this->call(CanonicalMachineSeeder::class);
        $this->call(ProductionConfigurationDefaultsSeeder::class);

        $shift = Shift::firstOrCreate(
            ['name' => 'Morning'],
            ['start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true],
        );
        Shift::firstOrCreate(['name' => 'Afternoon'], ['start_time' => '14:00', 'end_time' => '22:00', 'is_active' => true]);
        Shift::firstOrCreate(['name' => 'Night'], ['start_time' => '22:00', 'end_time' => '06:00', 'is_active' => true]);

        // Godowns that exist in Tally — the readiness gate refuses anything
        // else, so a fixture built on seeded lookalikes would not be a
        // faithful rehearsal.
        $rm = Warehouse::updateOrCreate(['code' => 'RM'], [
            'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm-fixture',
        ]);
        $fg = Warehouse::updateOrCreate(['code' => 'FG'], [
            'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg-fixture',
        ]);

        // --- materials -------------------------------------------------
        $resin = Item::updateOrCreate(['sku' => 'PET-IV08'], [
            'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.', 'is_active' => true,
            'tally_stock_item_guid' => 'itm-resin-fixture',
        ]);
        $mb = Item::updateOrCreate(['sku' => 'MB-AMBER'], [
            'name' => 'Master Batch - Amber', 'uom' => 'Kgs.', 'is_active' => true,
            'tally_stock_item_guid' => 'itm-mb-fixture',
        ]);
        // A Nos-unit consumable: it must appear as an expected recipe line
        // and must never be summed into the kg reconciliation.
        $cap = Item::updateOrCreate(['sku' => 'CAP-28'], [
            'name' => '28mm Cap', 'uom' => 'Nos.', 'is_active' => true,
            'tally_stock_item_guid' => 'itm-cap-fixture',
        ]);

        // --- finished goods --------------------------------------------
        // Tray-packed, cavity 5 — the main acceptance product.
        $bottle = Item::updateOrCreate(['sku' => 'BTL-100-RND'], [
            'name' => '100ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '12.9000',
            'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'itm-btl-fixture',
        ]);
        // Cavity 6, pouch AND tray — exercises the variant + packaging choice.
        $sixCav = Item::updateOrCreate(['sku' => 'BTL-60-RND'], [
            'name' => '60ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '10.5000', 'colour' => 'Amber',
            'tally_stock_item_guid' => 'itm-btl60-fixture',
        ]);
        // Cavity 7 — proves 6 and 7 are ordinary cavity counts.
        $sevenCav = Item::updateOrCreate(['sku' => 'BTL-90-RIB'], [
            'name' => '90ML RIB', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '8.5000', 'colour' => 'Clear',
            'tally_stock_item_guid' => 'itm-btl90-fixture',
        ]);

        $this->standards($bottle, $sixCav, $sevenCav);
        $this->recipe($bottle, $resin, $mb, $cap);
        $this->stock($resin, $mb, $cap, $rm);
        $this->lotWithBags($resin, $rm);
        $this->users();
    }

    private function standards(Item $bottle, Item $sixCav, Item $sevenCav): void
    {
        // 100ML ROUND: two variants differing only in cycle time — the
        // supervisor must be asked which one this run uses.
        foreach ([['12.30', 810, 162], ['11.50', 810, 162]] as [$ct, $perBox, $perTray]) {
            $std = ProductionStandard::updateOrCreate(
                ['source_product_name' => '100ML ROUND', 'cavities' => 5, 'unit_weight_grams' => '12.9000', 'cycle_time' => $ct],
                ['item_id' => $bottle->id, 'status' => 'draft', 'source' => 'acceptance-fixture'],
            );
            ProductionStandardPackaging::updateOrCreate(
                ['production_standard_id' => $std->id, 'mode' => ProductionStandardPackaging::MODE_TRAY],
                ['nos_per_tray' => $perTray, 'trays_per_box' => 5, 'nos_per_box' => $perBox, 'is_default' => true],
            );
        }

        // 60ML ROUND cavity 6, pouch + tray — the packaging choice.
        $six = ProductionStandard::updateOrCreate(
            ['source_product_name' => '60ML ROUND', 'cavities' => 6, 'unit_weight_grams' => '10.5000', 'cycle_time' => '11.60'],
            ['item_id' => $sixCav->id, 'status' => 'draft', 'source' => 'acceptance-fixture'],
        );
        ProductionStandardPackaging::updateOrCreate(
            ['production_standard_id' => $six->id, 'mode' => ProductionStandardPackaging::MODE_POUCH],
            ['nos_per_pouch' => 245, 'pouches_per_box' => 5, 'nos_per_box' => 1225],
        );
        ProductionStandardPackaging::updateOrCreate(
            ['production_standard_id' => $six->id, 'mode' => ProductionStandardPackaging::MODE_TRAY],
            ['nos_per_tray' => 230, 'trays_per_box' => 5, 'nos_per_box' => 1150],
        );

        // 90ML RIB cavity 7, tray only — sole option, auto-selected.
        $seven = ProductionStandard::updateOrCreate(
            ['source_product_name' => '90ML RIB', 'cavities' => 7, 'unit_weight_grams' => '8.5000', 'cycle_time' => '12.00'],
            ['item_id' => $sevenCav->id, 'status' => 'draft', 'source' => 'acceptance-fixture'],
        );
        ProductionStandardPackaging::updateOrCreate(
            ['production_standard_id' => $seven->id, 'mode' => ProductionStandardPackaging::MODE_TRAY],
            ['nos_per_tray' => 132, 'trays_per_box' => 10, 'nos_per_box' => 1320, 'is_default' => true],
        );
    }

    /** Resin + masterbatch + a Nos-unit consumable, so all three paths run. */
    private function recipe(Item $bottle, Item $resin, Item $mb, Item $cap): void
    {
        $bom = Bom::firstOrCreate(
            ['item_id' => $bottle->id, 'version' => '1'],
            ['name' => '100ML ROUND recipe', 'is_active' => true],
        );
        $bom->update(['is_active' => true]);

        foreach ([[$resin->id, '0.0129'], [$mb->id, '0.0003'], [$cap->id, '1.0000']] as [$componentId, $per]) {
            $line = $bom->lines()->firstOrNew(['component_item_id' => $componentId]);
            $line->quantity_per = $per;
            $line->save();
        }
    }

    private function stock(Item $resin, Item $mb, Item $cap, Warehouse $rm): void
    {
        $stock = app(StockMovementService::class);

        foreach ([[$resin, '2000'], [$mb, '100'], [$cap, '50000']] as [$item, $qty]) {
            // Top up only to the target, so re-running does not inflate.
            $balance = $item->id
                ? (string) (StockBalance::query()
                    ->where('item_id', $item->id)->where('warehouse_id', $rm->id)->value('quantity') ?? '0')
                : '0';

            if (bccomp($balance, $qty, 4) === -1) {
                $stock->recordReceipt(
                    itemId: $item->id, warehouseId: $rm->id,
                    quantity: bcsub($qty, $balance, 4), unitCost: '0',
                    reference: 'acceptance-fixture opening',
                );
            }
        }
    }

    /**
     * The lot behind the acceptance scenario: 2 bags of 100 kg, barcoded.
     * Two bags so "more than one bag feeding one batch" is exercisable.
     */
    private function lotWithBags(Item $resin, Warehouse $rm): void
    {
        if (MaterialLot::query()->where('supplier_lot_no', 'ACC-LOT-001')->exists()) {
            return;
        }

        app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id,
            'supplier_lot_no' => 'ACC-LOT-001',
            'received_date' => now()->subDay()->toDateString(),
            'bag_count' => 2,
            'bag_weight_kg' => '100',
            'total_received_kg' => '200',
            'warehouse_id' => $rm->id,
            'barcodes' => ['ACC-BAG-0001', 'ACC-BAG-0002'],
        ], null);
    }

    /** One user per approval desk, so the whole chain is walkable locally. */
    private function users(): void
    {
        // Derived from PermissionService, never hand-listed. The hand-written
        // list this replaces had drifted: it created "tally.view"/"tally.manage"
        // while every route group and PermissionSeeder use the module key
        // "tally-sync". A fixture user therefore held a permission that gated
        // nothing and lacked the one that gates the Tally screens, so the whole
        // Tally surface 403'd on any database seeded from this fixture alone —
        // looking exactly like a product bug rather than a fixture gap.
        $permissions = collect(app(PermissionService::class)->allPermissionNames())
            ->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        $desks = [
            ['supervisor@example.com', 'Fixture Supervisor', null],
            ['pm@example.com', 'Fixture Plant Manager', 'Plant Manager'],
            ['accounts@example.com', 'Fixture Accounts', 'Accounts'],
        ];

        foreach ($desks as [$email, $name, $role]) {
            $user = User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'is_active' => true,
            ]);
            $user->syncPermissions($permissions);
            if ($role !== null) {
                $user->assignRole(Role::findOrCreate($role, 'web'));
            }
        }
    }
}
