<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Finance\Models\Enums\GLAccountType;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\LeaveType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\Enums\AssetStatus;
use App\Modules\Maintenance\Models\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Payroll\Models\Enums\SalaryCalculationType;
use App\Modules\Payroll\Models\Enums\SalaryComponentType;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\MoldStatus;
use App\Modules\Production\Models\Mold;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\Routing;
use App\Modules\Production\Models\ScrapReason;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Models\Enums\MeasuringInstrumentStatus;
use App\Modules\Quality\Models\MeasuringInstrument;
use App\Modules\Quality\Models\SpcCharacteristic;
use App\Modules\Sales\Models\Customer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * WS-B of the Configuration Lifecycle Contract (audit 17-Aug-2026, §1):
 * eleven `is_active`/`status` flags were SET but filtered NOWHERE, so a
 * retired mould and a withdrawn scrap reason were selectable on the floor,
 * and Item/Warehouse were unfiltered on the stock/GRN write paths.
 *
 * EVERY test here WIDENS THE REFUSAL SET ON LIVE DATA, so each one proves
 * BOTH halves in one method:
 *   1. the ACTIVE row still passes the rule (no validation error on the
 *      field) — the half that catches an over-wide predicate; and
 *   2. the RETIRED/INACTIVE row is now refused with a 422 naming that
 *      exact field — the half that is the new behaviour.
 * Asserting (1) first is what makes (2) non-vacuous.
 *
 * WHAT IS DELIBERATELY *NOT* WIDENED, and why:
 *   - Mold `under_repair`, Asset `under_maintenance`: only `retired` is
 *     refused. An asset under maintenance MUST still accept a maintenance
 *     work order, and whether a mould under repair may be scheduled is a
 *     factory call nobody has made — see the owner question in the phase
 *     report. The audit's finding is scoped to "a retired mould".
 *   - Soft-deleted rows: `Rule::exists()` counts them (see
 *     ProductionStandardService::attachItem's docblock). The house pattern
 *     used here filters on the active flag only; the trashed-row hole is
 *     recorded as a residual gap, not closed by a second widening nobody
 *     asked for and no test here pins.
 *   - A Tally-mirror purchase order (`source: tally`) keeps accepting a
 *     retired vendor: the ERP mirrors Tally's book, it never refuses to
 *     record what Tally already holds.
 *
 * The last three tests pin the other direction: a batch, a configuration
 * and a stock movement that ALREADY name a now-retired master still render
 * it. Widening what may be CHOSEN must never narrow what may be READ.
 */
class ActiveSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->create(['name' => 'Lifecycle Admin', 'is_active' => true]);
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        // These tests exercise the master-selection rules, not the product
        // readiness gate (same minimal-fixture rationale as
        // CompletionDowntimeTest).
        config()->set('production.readiness.enforced', false);
    }

    private function admin(): User
    {
        return User::query()->firstOrFail();
    }

    /**
     * The one shape every widening is proved in: post once while the master
     * is live (the named fields must NOT be refused), retire it, post the
     * identical payload again (the named fields MUST be refused).
     *
     * @param  list<string>  $fields
     * @param  callable(): TestResponse  $post
     * @param  callable(): void  $retire
     */
    private function assertSelectableOnlyWhileActive(array $fields, callable $post, callable $retire): void
    {
        $live = $post();
        $this->assertNotContains(
            $live->getStatusCode(),
            [401, 403, 404, 405, 500],
            'the endpoint never ran its validation, so the refusal below would prove nothing',
        );
        $live->assertJsonMissingValidationErrors($fields);

        $retire();

        $post()->assertUnprocessable()->assertJsonValidationErrors($fields);
    }

    // =====================================================================
    // Vendor
    // =====================================================================

    public function test_a_retired_vendor_cannot_take_a_new_purchase_order(): void
    {
        $vendor = Vendor::create(['code' => 'V-1', 'name' => 'Retiring Vendor']);
        $resin = Item::create(['sku' => 'RM-1', 'name' => 'Resin', 'uom' => 'Kgs']);

        $this->assertSelectableOnlyWhileActive(
            ['vendor_id'],
            fn () => $this->postJson('/api/v1/procurement/purchase-orders', [
                'vendor_id' => $vendor->id,
                'order_date' => '2026-08-10',
                'lines' => [['item_id' => $resin->id, 'quantity' => '10', 'unit_price' => '1.00']],
            ]),
            fn () => $vendor->update(['is_active' => false]),
        );
    }

    public function test_a_tally_mirror_purchase_order_still_records_a_retired_vendor(): void
    {
        $vendor = Vendor::create(['code' => 'V-2', 'name' => 'Retired In The Erp', 'is_active' => false]);
        $resin = Item::create(['sku' => 'RM-1', 'name' => 'Resin', 'uom' => 'Kgs']);

        // Tally is the PO source of truth (PurchaseOrderService's class
        // docblock); the mirror is a read-only reflection of an order that
        // already exists there, so the ERP records it rather than arguing.
        $this->postJson('/api/v1/procurement/purchase-orders', [
            'source' => 'tally',
            'tally_order_no' => 'MIRROR-0001',
            'vendor_id' => $vendor->id,
            'order_date' => '2026-08-10',
            'lines' => [['item_id' => $resin->id, 'quantity' => '10', 'unit_price' => '1.00']],
        ])->assertCreated();
    }

    public function test_a_retired_vendor_cannot_take_a_new_subcontract_order(): void
    {
        $vendor = Vendor::create(['code' => 'V-3', 'name' => 'Subcontractor']);
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);
        $warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);

        $this->assertSelectableOnlyWhileActive(
            ['vendor_id'],
            fn () => $this->postJson('/api/v1/production/subcontract-orders', [
                'vendor_id' => $vendor->id,
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'quantity_planned' => '10',
            ]),
            fn () => $vendor->update(['is_active' => false]),
        );
    }

    // =====================================================================
    // Customer
    // =====================================================================

    public function test_a_retired_customer_cannot_take_a_new_sales_order(): void
    {
        $customer = Customer::create(['code' => 'C-1', 'name' => 'Retiring Customer']);
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);

        $this->assertSelectableOnlyWhileActive(
            ['customer_id'],
            fn () => $this->postJson('/api/v1/sales/sales-orders', [
                'customer_id' => $customer->id,
                'order_date' => '2026-08-12',
                'lines' => [['item_id' => $item->id, 'quantity' => '10', 'unit_price' => '1.00']],
            ]),
            fn () => $customer->update(['is_active' => false]),
        );
    }

    public function test_a_retired_customer_cannot_take_a_new_opportunity(): void
    {
        $customer = Customer::create(['code' => 'C-2', 'name' => 'Retiring Customer']);

        $this->assertSelectableOnlyWhileActive(
            ['customer_id'],
            fn () => $this->postJson('/api/v1/crm/opportunities', [
                'name' => 'A deal',
                'customer_id' => $customer->id,
            ]),
            fn () => $customer->update(['is_active' => false]),
        );
    }

    // =====================================================================
    // GLAccount
    // =====================================================================

    public function test_a_deactivated_gl_account_cannot_take_a_new_journal_line(): void
    {
        $bank = GLAccount::create(['code' => '1000', 'name' => 'Bank', 'type' => GLAccountType::Asset, 'is_active' => true]);
        $revenue = GLAccount::create(['code' => '4000', 'name' => 'Revenue', 'type' => GLAccountType::Revenue, 'is_active' => true]);

        $this->assertSelectableOnlyWhileActive(
            ['lines.0.gl_account_id'],
            fn () => $this->postJson('/api/v1/finance/journal-entries', [
                'entry_date' => '2026-08-12',
                'lines' => [
                    ['gl_account_id' => $bank->id, 'debit' => '1.00', 'credit' => '0.00'],
                    ['gl_account_id' => $revenue->id, 'debit' => '0.00', 'credit' => '1.00'],
                ],
            ]),
            fn () => $bank->update(['is_active' => false]),
        );
    }

    // =====================================================================
    // LeaveType
    // =====================================================================

    public function test_a_withdrawn_leave_type_cannot_take_a_new_leave_request(): void
    {
        $employee = Employee::create(['employee_code' => 'E-1', 'name' => 'Operator', 'date_of_joining' => '2026-01-01']);
        $leaveType = LeaveType::create(['code' => 'CL', 'name' => 'Casual Leave', 'default_annual_days' => 12]);

        $this->assertSelectableOnlyWhileActive(
            ['leave_type_id'],
            fn () => $this->postJson('/api/v1/hrms/leave-requests', [
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => '2026-08-20',
                'end_date' => '2026-08-20',
                'days' => 1,
            ]),
            fn () => $leaveType->update(['is_active' => false]),
        );
    }

    public function test_a_withdrawn_leave_type_cannot_take_a_new_allocation(): void
    {
        $employee = Employee::create(['employee_code' => 'E-2', 'name' => 'Operator', 'date_of_joining' => '2026-01-01']);
        $leaveType = LeaveType::create(['code' => 'SL', 'name' => 'Sick Leave', 'default_annual_days' => 6]);

        $year = 2026;
        $this->assertSelectableOnlyWhileActive(
            ['leave_type_id'],
            function () use ($employee, $leaveType, &$year) {
                // A fresh year each call: the composite uniqueness rule would
                // otherwise refuse the second post for its own reason.
                return $this->postJson('/api/v1/hrms/leave-balances', [
                    'employee_id' => $employee->id,
                    'leave_type_id' => $leaveType->id,
                    'year' => $year++,
                    'allocated_days' => 6,
                ]);
            },
            fn () => $leaveType->update(['is_active' => false]),
        );
    }

    // =====================================================================
    // SalaryComponent
    // =====================================================================

    public function test_a_withdrawn_salary_component_cannot_join_a_new_salary_structure(): void
    {
        $employee = Employee::create(['employee_code' => 'E-3', 'name' => 'Operator', 'date_of_joining' => '2026-01-01']);
        $component = SalaryComponent::create([
            'code' => 'BASIC', 'name' => 'Basic',
            'type' => SalaryComponentType::Earning,
            'calculation_type' => SalaryCalculationType::FixedAmount,
        ]);

        $year = 2026;
        $this->assertSelectableOnlyWhileActive(
            ['lines.0.salary_component_id'],
            function () use ($employee, $component, &$year) {
                // A fresh effective_from each call: (employee, effective_from)
                // is unique, so the second post would otherwise fail for a
                // reason that has nothing to do with the component.
                return $this->postJson('/api/v1/payroll/salary-structures', [
                    'employee_id' => $employee->id,
                    'effective_from' => ($year++).'-01-01',
                    'lines' => [['salary_component_id' => $component->id, 'amount' => '100']],
                ]);
            },
            fn () => $component->update(['is_active' => false]),
        );
    }

    // =====================================================================
    // ScrapReason — the four completion paths the floor actually uses
    // =====================================================================

    public function test_a_withdrawn_scrap_reason_cannot_be_chosen_on_batch_completion(): void
    {
        $reason = ScrapReason::create(['code' => 'SR-1', 'name' => 'Flash', 'is_active' => true]);
        $entry = $this->runningBatch();

        $this->assertSelectableOnlyWhileActive(
            ['scrap_reason_id'],
            fn () => $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
                'quantity_produced' => '100',
                'quantity_scrap' => '1',
                'scrap_reason_id' => $reason->id,
            ]),
            fn () => $reason->update(['is_active' => false]),
        );
    }

    public function test_a_withdrawn_scrap_reason_cannot_be_chosen_on_a_completion_scrap_line(): void
    {
        $reason = ScrapReason::create(['code' => 'SR-2', 'name' => 'Lumps', 'is_active' => true]);
        $entry = $this->runningBatch();

        $this->assertSelectableOnlyWhileActive(
            ['scraps.0.scrap_reason_id'],
            fn () => $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
                'quantity_produced' => '100',
                'scraps' => [[
                    'type' => 'lumps',
                    'quantity_kg' => '1',
                    'scrap_reason_id' => $reason->id,
                ]],
            ]),
            fn () => $reason->update(['is_active' => false]),
        );
    }

    public function test_a_withdrawn_scrap_reason_cannot_be_chosen_on_a_shift_handover(): void
    {
        config(['production.traceability_enabled' => true]);

        $reason = ScrapReason::create(['code' => 'SR-3', 'name' => 'Short shot', 'is_active' => true]);
        $entry = $this->runningBatch();
        $incoming = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'is_active' => true]);

        $this->assertSelectableOnlyWhileActive(
            ['completion.scrap_reason_id', 'completion.scraps.0.scrap_reason_id'],
            fn () => $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/handover", [
                'shift_id' => $incoming->id,
                'completion' => [
                    'quantity_produced' => '100',
                    'quantity_scrap' => '1',
                    'scrap_reason_id' => $reason->id,
                    'scraps' => [[
                        'type' => 'lumps',
                        'quantity_kg' => '1',
                        'scrap_reason_id' => $reason->id,
                    ]],
                ],
            ]),
            fn () => $reason->update(['is_active' => false]),
        );
    }

    public function test_a_withdrawn_scrap_reason_cannot_be_typed_onto_a_page_row(): void
    {
        $reason = ScrapReason::create(['code' => 'SR-4', 'name' => 'Contamination', 'is_active' => true]);
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);

        $this->assertSelectableOnlyWhileActive(
            ['rows.0.scrap_reason_id'],
            fn () => $this->postJson('/api/v1/production/shift-production-entries/page', [
                'shift_id' => $shift->id,
                'production_date' => now()->subDay()->toDateString(),
                'rows' => [[
                    'work_center_id' => $machine->id,
                    'item_id' => $item->id,
                    'quantity_produced' => '100',
                    'quantity_scrap' => '1',
                    'scrap_reason_id' => $reason->id,
                ]],
            ]),
            fn () => $reason->update(['is_active' => false]),
        );
    }

    public function test_a_withdrawn_scrap_reason_cannot_be_chosen_on_a_work_order_completion(): void
    {
        $reason = ScrapReason::create(['code' => 'SR-5', 'name' => 'Off colour', 'is_active' => true]);
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);
        $warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);
        $workOrder = WorkOrder::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'quantity_planned' => '100', 'status' => 'released', 'created_by' => $this->admin()->id,
        ]);

        $this->assertSelectableOnlyWhileActive(
            ['scrap.0.scrap_reason_id'],
            fn () => $this->postJson("/api/v1/production/work-orders/{$workOrder->id}/complete", [
                'quantity_completed' => '10',
                'scrap' => [['scrap_reason_id' => $reason->id, 'quantity' => '1']],
            ]),
            fn () => $reason->update(['is_active' => false]),
        );
    }

    /**
     * KNOCK-ON, pinned deliberately rather than discovered later:
     * AmendBatchRequest extends CompleteBatchRequest, so the withdrawn-reason
     * refusal reaches the amend drawer too. Re-sending a reason that has
     * since been withdrawn is now refused — the floor must pick a live
     * reason or the reason must be reactivated. Reading the batch is
     * untouched (see the historical-read tests below); only re-submitting is
     * affected. Whether an amendment should be allowed to keep a reason that
     * was live when the batch ran is an owner question, recorded not answered.
     */
    public function test_the_amend_drawer_inherits_the_withdrawn_scrap_reason_refusal(): void
    {
        $reason = ScrapReason::create(['code' => 'SR-6', 'name' => 'Flash', 'is_active' => true]);
        $entry = $this->runningBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '100',
            'quantity_scrap' => '1',
            'scrap_reason_id' => $reason->id,
        ])->assertSuccessful();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/amend", [
            'quantity_produced' => '110',
            'quantity_scrap' => '1',
            'scrap_reason_id' => $reason->id,
        ])->assertJsonMissingValidationErrors(['scrap_reason_id']);

        $reason->update(['is_active' => false]);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/amend", [
            'quantity_produced' => '120',
            'quantity_scrap' => '1',
            'scrap_reason_id' => $reason->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['scrap_reason_id']);
    }

    // =====================================================================
    // Mold — RETIRED only (under_repair is an unanswered factory question)
    // =====================================================================

    public function test_a_retired_mould_cannot_be_chosen_at_start_batch(): void
    {
        $mold = Mold::create(['code' => 'M-1', 'name' => 'Mould 1', 'cavity_count' => 4]);
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);
        $warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);

        $this->assertSelectableOnlyWhileActive(
            ['mold_id'],
            fn () => $this->postJson('/api/v1/production/shift-production-entries', [
                'shift_id' => $shift->id,
                'work_center_id' => $machine->id,
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'mold_id' => $mold->id,
            ]),
            fn () => $mold->update(['status' => MoldStatus::Retired]),
        );
    }

    public function test_a_mould_under_repair_is_still_selectable_because_nobody_has_ruled_otherwise(): void
    {
        $mold = Mold::create(['code' => 'M-2', 'name' => 'Mould 2', 'cavity_count' => 4, 'status' => MoldStatus::UnderRepair]);
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);
        $warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);

        $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'mold_id' => $mold->id,
        ])->assertJsonMissingValidationErrors(['mold_id']);
    }

    public function test_a_retired_mould_cannot_be_written_into_a_new_production_configuration(): void
    {
        $mold = Mold::create(['code' => 'M-3', 'name' => 'Mould 3', 'cavity_count' => 4]);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);

        $this->assertSelectableOnlyWhileActive(
            ['mold_id'],
            fn () => $this->postJson('/api/v1/production/configurations', [
                'work_center_id' => $machine->id,
                'item_id' => $item->id,
                'mold_id' => $mold->id,
                'colour' => 'Natural',
            ]),
            fn () => $mold->update(['status' => MoldStatus::Retired]),
        );
    }

    /**
     * KNOCK-ON, pinned deliberately: StoreProductionConfigurationRequest is
     * bound to BOTH `store` and `update` on ProductionConfigurationController,
     * so editing an existing configuration that still names a retired mould is
     * refused too. That is the intended reading — a configuration GOVERNS
     * production, so it must not keep pointing at a mould the factory has
     * retired — and the escape hatch is the same edit: re-point the mould, or
     * clear it. Reading the configuration is untouched (see the historical
     * read test below).
     */
    public function test_editing_a_configuration_may_re_point_a_retired_mould_but_not_keep_it(): void
    {
        $mold = Mold::create(['code' => 'M-4', 'name' => 'Mould 4', 'cavity_count' => 4]);
        $replacement = Mold::create(['code' => 'M-5', 'name' => 'Mould 5', 'cavity_count' => 4]);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);

        $configuration = ProductionConfiguration::create([
            'work_center_id' => $machine->id, 'item_id' => $item->id, 'mold_id' => $mold->id,
            'colour' => 'Natural', 'default_cycle_time' => '10.00', 'default_cavities' => 4,
            'unit_weight_grams' => '10.0000', 'status' => 'draft', 'source' => 'WS-B FIXTURE',
        ]);

        $mold->update(['status' => MoldStatus::Retired]);

        $this->putJson("/api/v1/production/configurations/{$configuration->id}", [
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'mold_id' => $mold->id,
            'default_cycle_time' => '11.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['mold_id']);

        // The way out is the same edit: point it at a live mould.
        $this->putJson("/api/v1/production/configurations/{$configuration->id}", [
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'mold_id' => $replacement->id,
            'default_cycle_time' => '11.00',
        ])->assertJsonMissingValidationErrors(['mold_id']);

        // …or by clearing it.
        $this->putJson("/api/v1/production/configurations/{$configuration->id}", [
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'mold_id' => null,
            'default_cycle_time' => '11.00',
        ])->assertJsonMissingValidationErrors(['mold_id']);
    }

    // =====================================================================
    // Routing
    // =====================================================================

    public function test_a_withdrawn_routing_cannot_be_chosen_for_a_new_work_order(): void
    {
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);
        $warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);
        $bom = Bom::create(['item_id' => $item->id, 'name' => 'Recipe', 'version' => '1', 'is_active' => true]);
        $routing = Routing::create(['item_id' => $item->id, 'name' => 'Route', 'is_active' => true]);

        $this->assertSelectableOnlyWhileActive(
            ['routing_id'],
            fn () => $this->postJson('/api/v1/production/work-orders', [
                'item_id' => $item->id,
                'bom_id' => $bom->id,
                'routing_id' => $routing->id,
                'warehouse_id' => $warehouse->id,
                'quantity_planned' => '10',
            ]),
            fn () => $routing->update(['is_active' => false]),
        );
    }

    // =====================================================================
    // Asset — RETIRED only (under_maintenance MUST stay selectable)
    // =====================================================================

    public function test_a_retired_asset_cannot_take_a_new_maintenance_schedule(): void
    {
        $asset = Asset::create(['code' => 'A-1', 'name' => 'Compressor']);

        $this->assertSelectableOnlyWhileActive(
            ['asset_id'],
            fn () => $this->postJson('/api/v1/maintenance/schedules', [
                'asset_id' => $asset->id,
                'name' => 'Monthly PM',
                'frequency_days' => 30,
                'next_due_date' => '2026-09-01',
            ]),
            fn () => $asset->update(['status' => AssetStatus::Retired]),
        );
    }

    public function test_a_retired_asset_cannot_take_a_new_maintenance_work_order(): void
    {
        $asset = Asset::create(['code' => 'A-2', 'name' => 'Chiller']);

        $this->assertSelectableOnlyWhileActive(
            ['asset_id'],
            fn () => $this->postJson('/api/v1/maintenance/work-orders', [
                'asset_id' => $asset->id,
                'type' => 'corrective',
                'reported_date' => '2026-08-12',
            ]),
            fn () => $asset->update(['status' => AssetStatus::Retired]),
        );
    }

    public function test_an_asset_under_maintenance_still_takes_a_work_order(): void
    {
        $asset = Asset::create(['code' => 'A-3', 'name' => 'Dryer', 'status' => AssetStatus::UnderMaintenance]);

        // The point of the module: an asset being worked on is exactly the
        // asset a work order is raised against.
        $this->postJson('/api/v1/maintenance/work-orders', [
            'asset_id' => $asset->id,
            'type' => 'corrective',
            'reported_date' => '2026-08-12',
        ])->assertSuccessful();
    }

    // =====================================================================
    // MeasuringInstrument · SpcCharacteristic — route-bound parents
    // =====================================================================

    public function test_a_retired_instrument_cannot_take_a_new_calibration_record(): void
    {
        $instrument = MeasuringInstrument::create([
            'code' => 'INST-1', 'name' => 'Vernier', 'calibration_frequency_days' => 30,
            'next_calibration_due' => '2026-09-01',
        ]);

        $this->assertSelectableOnlyWhileActive(
            ['instrument'],
            fn () => $this->postJson("/api/v1/quality/instruments/{$instrument->id}/calibrations", [
                'calibrated_date' => '2026-08-12',
                'result' => 'pass',
            ]),
            fn () => $instrument->update(['status' => MeasuringInstrumentStatus::Retired]),
        );
    }

    public function test_a_withdrawn_spc_characteristic_cannot_take_a_new_measurement(): void
    {
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);
        $characteristic = SpcCharacteristic::create([
            'item_id' => $item->id, 'name' => 'Neck weight', 'unit_of_measure' => 'g',
        ]);

        $this->assertSelectableOnlyWhileActive(
            ['spc_characteristic'],
            fn () => $this->postJson("/api/v1/quality/spc-characteristics/{$characteristic->id}/measurements", [
                'value' => '10.0000',
                'measured_at' => '2026-08-12 08:00:00',
            ]),
            fn () => $characteristic->update(['is_active' => false]),
        );
    }

    // =====================================================================
    // Item and Warehouse — the stock / GRN write paths
    // =====================================================================

    public function test_a_retired_item_or_warehouse_cannot_take_a_stock_receipt(): void
    {
        $item = Item::create(['sku' => 'RM-1', 'name' => 'Resin', 'uom' => 'Kgs']);
        $warehouse = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);

        $this->assertSelectableOnlyWhileActive(
            ['item_id', 'warehouse_id'],
            fn () => $this->postJson('/api/v1/inventory/stock-movements/receipts', [
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => '10',
                'unit_cost' => '1.00',
            ]),
            function () use ($item, $warehouse) {
                $item->update(['is_active' => false]);
                $warehouse->update(['is_active' => false]);
            },
        );
    }

    public function test_a_retired_item_or_warehouse_cannot_take_a_stock_issue(): void
    {
        $item = Item::create(['sku' => 'RM-1', 'name' => 'Resin', 'uom' => 'Kgs']);
        $warehouse = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);
        app(StockMovementService::class)->recordReceipt(
            itemId: $item->id, warehouseId: $warehouse->id, quantity: '100', unitCost: '1.00', reference: 'seed',
        );

        $this->assertSelectableOnlyWhileActive(
            ['item_id', 'warehouse_id'],
            fn () => $this->postJson('/api/v1/inventory/stock-movements/issues', [
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => '1',
            ]),
            function () use ($item, $warehouse) {
                $item->update(['is_active' => false]);
                $warehouse->update(['is_active' => false]);
            },
        );
    }

    public function test_a_retired_item_or_warehouse_cannot_take_a_stock_transfer(): void
    {
        $item = Item::create(['sku' => 'RM-1', 'name' => 'Resin', 'uom' => 'Kgs']);
        $from = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);
        $to = Warehouse::create(['code' => 'WIP', 'name' => 'WIP Store']);
        app(StockMovementService::class)->recordReceipt(
            itemId: $item->id, warehouseId: $from->id, quantity: '100', unitCost: '1.00', reference: 'seed',
        );

        $this->assertSelectableOnlyWhileActive(
            ['item_id', 'from_warehouse_id', 'to_warehouse_id'],
            fn () => $this->postJson('/api/v1/inventory/stock-movements/transfers', [
                'item_id' => $item->id,
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'quantity' => '1',
            ]),
            function () use ($item, $from, $to) {
                $item->update(['is_active' => false]);
                $from->update(['is_active' => false]);
                $to->update(['is_active' => false]);
            },
        );
    }

    public function test_a_retired_warehouse_cannot_receive_a_goods_receipt(): void
    {
        $vendor = Vendor::create(['code' => 'V-1', 'name' => 'Vendor']);
        $resin = Item::create(['sku' => 'RM-1', 'name' => 'Resin', 'uom' => 'Kgs']);
        $warehouse = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);
        $order = PurchaseOrder::create([
            'vendor_id' => $vendor->id, 'status' => PurchaseOrderStatus::Sent, 'order_date' => '2026-08-10',
        ]);
        $line = $order->lines()->create(['item_id' => $resin->id, 'quantity' => '100', 'unit_price' => '1.00']);

        $key = 0;
        $this->assertSelectableOnlyWhileActive(
            ['warehouse_id'],
            function () use ($order, $line, $warehouse, &$key) {
                // A fresh receipt_key each call: replay protection would
                // otherwise answer the second post from the first one.
                return $this->postJson('/api/v1/procurement/goods-receipts', [
                    'receipt_key' => 'grn-key-'.($key++),
                    'purchase_order_id' => $order->id,
                    'warehouse_id' => $warehouse->id,
                    'received_date' => '2026-08-11',
                    'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => '10']],
                ]);
            },
            fn () => $warehouse->update(['is_active' => false]),
        );
    }

    public function test_a_retired_item_or_warehouse_cannot_be_drawn_onto_a_maintenance_work_order(): void
    {
        $asset = Asset::create(['code' => 'A-1', 'name' => 'Compressor']);
        $workOrder = MaintenanceWorkOrder::create([
            'asset_id' => $asset->id, 'type' => MaintenanceWorkOrderType::Corrective,
            'reported_date' => '2026-08-12', 'created_by' => $this->admin()->id,
        ]);
        $part = Item::create(['sku' => 'SP-1', 'name' => 'Bearing', 'uom' => 'Nos']);
        $warehouse = Warehouse::create(['code' => 'SP', 'name' => 'Spares Store']);
        app(StockMovementService::class)->recordReceipt(
            itemId: $part->id, warehouseId: $warehouse->id, quantity: '10', unitCost: '1.00', reference: 'seed',
        );

        $this->assertSelectableOnlyWhileActive(
            ['item_id', 'warehouse_id'],
            fn () => $this->postJson("/api/v1/maintenance/work-orders/{$workOrder->id}/parts", [
                'item_id' => $part->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => '1',
            ]),
            function () use ($part, $warehouse) {
                $part->update(['is_active' => false]);
                $warehouse->update(['is_active' => false]);
            },
        );
    }

    public function test_a_retired_item_cannot_take_a_new_material_lot(): void
    {
        config(['production.traceability_enabled' => true]);

        $resin = Item::create(['sku' => 'RM-1', 'name' => 'Resin', 'uom' => 'Kgs']);

        $lot = 0;
        $this->assertSelectableOnlyWhileActive(
            ['item_id'],
            function () use ($resin, &$lot) {
                return $this->postJson('/api/v1/inventory/material-lots', [
                    'item_id' => $resin->id,
                    'supplier_lot_no' => 'LOT-'.($lot++),
                    'received_date' => '2026-08-11',
                    'bag_count' => 2,
                    'bag_weight_kg' => '25',
                    'total_received_kg' => '50',
                ]);
            },
            fn () => $resin->update(['is_active' => false]),
        );
    }

    // =====================================================================
    // Historical reads must NOT break
    // =====================================================================

    public function test_a_completed_batch_still_names_its_scrap_reason_and_item_after_both_are_retired(): void
    {
        $reason = ScrapReason::create(['code' => 'SR-9', 'name' => 'Withdrawn Reason', 'is_active' => true]);
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create(['sku' => 'FG-9', 'name' => 'Retired Bottle', 'uom' => 'Nos']);
        $warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);

        ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id, 'production_date' => '2026-08-12',
            'batch_number' => '20260812-MC01-001', 'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '100', 'quantity_scrap' => '1', 'scrap_reason_id' => $reason->id,
        ]);

        $before = $this->getJson('/api/v1/production/shift-production-entries')->assertOk();
        $before->assertSee('Withdrawn Reason')->assertSee('Retired Bottle');

        $reason->update(['is_active' => false]);
        $item->update(['is_active' => false]);

        $this->getJson('/api/v1/production/shift-production-entries')
            ->assertOk()
            ->assertSee('Withdrawn Reason')
            ->assertSee('Retired Bottle');
    }

    public function test_a_production_configuration_still_names_its_mould_after_the_mould_is_retired(): void
    {
        $mold = Mold::create(['code' => 'M-9', 'name' => 'Retired Mould', 'cavity_count' => 4]);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);

        ProductionConfiguration::create([
            'work_center_id' => $machine->id, 'item_id' => $item->id, 'mold_id' => $mold->id,
            'colour' => 'Natural', 'default_cycle_time' => '10.00', 'default_cavities' => 4,
            'unit_weight_grams' => '10.0000', 'status' => 'approved', 'source' => 'WS-B FIXTURE',
        ]);

        $this->getJson('/api/v1/production/configurations')->assertOk()->assertSee('Retired Mould');

        $mold->update(['status' => MoldStatus::Retired]);

        $this->getJson('/api/v1/production/configurations')->assertOk()->assertSee('Retired Mould');
    }

    public function test_a_recorded_stock_movement_still_names_its_item_and_warehouse_after_both_are_retired(): void
    {
        $item = Item::create(['sku' => 'RM-9', 'name' => 'Retired Resin', 'uom' => 'Kgs']);
        $warehouse = Warehouse::create(['code' => 'RM-9', 'name' => 'Retired Store']);
        app(StockMovementService::class)->recordReceipt(
            itemId: $item->id, warehouseId: $warehouse->id, quantity: '10', unitCost: '1.00', reference: 'history',
        );

        $this->getJson('/api/v1/inventory/stock-movements')->assertOk()
            ->assertSee('Retired Resin')->assertSee('Retired Store');

        $item->update(['is_active' => false]);
        $warehouse->update(['is_active' => false]);

        $this->getJson('/api/v1/inventory/stock-movements')->assertOk()
            ->assertSee('Retired Resin')->assertSee('Retired Store');
    }

    // ---- fixtures ---------------------------------------------------------

    /** A batch in progress, on its own machine, ready to be completed. */
    private function runningBatch(): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos', 'nos_per_box' => 100]);
        $warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id, 'production_date' => now()->toDateString(),
            'batch_number' => 'WSB-0001', 'batch_status' => BatchStatus::InProgress,
            'quantity_scrap' => '0',
        ]);
    }
}
