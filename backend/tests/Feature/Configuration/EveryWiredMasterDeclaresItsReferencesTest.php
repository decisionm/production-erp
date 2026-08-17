<?php

namespace Tests\Feature\Configuration;

use App\Modules\HRMS\Services\EmployeeService;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Production\Services\DowntimeReasonService;
use App\Modules\Production\Services\MoldService;
use App\Modules\Production\Services\ProductionConfigurationService;
use App\Modules\Production\Services\ProductionStandardPackagingService;
use App\Modules\Production\Services\ProductionStandardService;
use App\Modules\Production\Services\ScrapReasonService;
use App\Modules\Production\Services\ShiftService;
use App\Modules\Production\Services\WorkCenterService;
use App\Support\Configuration\SchemaCascades;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ONE place that holds every wired master to the declaration-coverage rule.
 *
 * The rule already existed — `assertEveryUndefendedReferenceIsDeclared()` —
 * but only four of the eleven masters ever called it, and the four that did
 * were the four least exposed. **Item was not among them, and Item carries
 * the largest declaration in the repository (43 inbound foreign-key
 * columns).** An adversarial review found a real SET NULL hole in exactly
 * that gap: `production_standard_packagings.item_id` was declared but not
 * `->includeTrashed()`, while the same commit gave that table its first
 * `deleted_at`. An Item named only by an ARCHIVED pack variant therefore read
 * as clear, and hard-deleting it blanked which product that variant packed.
 *
 * A per-entity assertion that most entities do not call is not a rule, it is
 * a habit. This file makes it a rule: every master wired to the contract is
 * listed here, and adding a twelfth without adding a line here is the kind of
 * omission the next reviewer should catch immediately.
 *
 * Deliberately NOT merged into each entity's own lifecycle test: the point is
 * that the LIST is visible in one place and reads as a census. Scattered back
 * across eleven files, the twelfth master's absence becomes invisible again —
 * which is the failure this file exists to prevent.
 */
class EveryWiredMasterDeclaresItsReferencesTest extends ProductDefinitionLifecycleTestCase
{
    use RefreshDatabase;

    /**
     * Every master wired by D-WIRING, with the table it owns.
     *
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function wiredMasters(): array
    {
        return [
            'warehouse' => [WarehouseService::class, 'warehouses'],
            'item' => [ItemService::class, 'items'],
            'work centre' => [WorkCenterService::class, 'work_centers'],
            'mould' => [MoldService::class, 'molds'],
            'shift' => [ShiftService::class, 'shifts'],
            'scrap reason' => [ScrapReasonService::class, 'scrap_reasons'],
            'downtime reason' => [DowntimeReasonService::class, 'downtime_reasons'],
            'production standard' => [ProductionStandardService::class, 'production_standards'],
            'production standard packaging' => [ProductionStandardPackagingService::class, 'production_standard_packagings'],
            'production configuration' => [ProductionConfigurationService::class, 'production_configurations'],
            'employee' => [EmployeeService::class, 'employees'],
        ];
    }

    /**
     * @param  class-string  $serviceClass
     */
    #[DataProvider('wiredMasters')]
    public function test_every_undefended_reference_is_declared_and_can_see_archived_children(string $serviceClass, string $table): void
    {
        // A master every one of whose inbound keys CASCADEs has nothing for
        // this assertion to prove — the shipped SchemaCascades backstop
        // already refuses per column for anything undeclared, which is the
        // whole reason that backstop exists. `downtime_reasons` is the one
        // such master today.
        //
        // Handled explicitly rather than by dropping it from the list: its
        // presence here is the record that it WAS checked and why it needs no
        // declaration, so a future migration that adds a SET NULL reference to
        // it turns this branch off and the real assertion takes over
        // automatically. Silently omitting it would lose exactly that.
        if ($this->undefendedReferencesTo($table) === []) {
            $cascades = SchemaCascades::referencing(DB::connection(), $table);

            $this->assertNotSame(
                [],
                $cascades ?? [],
                "{$table} has no non-cascading inbound foreign key AND no cascading one either — nothing references "
                .'it at all, so either the table name here is wrong or this master is not the one that was wired.',
            );

            return;
        }

        $this->assertEveryUndefendedReferenceIsDeclared(app($serviceClass), $table);
    }
}
