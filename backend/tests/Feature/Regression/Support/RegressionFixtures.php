<?php

namespace Tests\Feature\Regression\Support;

use App\Models\User;
use App\Modules\Compliance\Models\GstRate;
use App\Modules\Compliance\Models\GstRegistration;
use App\Modules\Core\Services\PermissionService;
use App\Modules\CRM\Models\Enums\LeadActivityType;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadActivity;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\CRM\Models\Quotation;
use App\Modules\Finance\Models\Enums\GLAccountType;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\Enums\AttendanceStatus;
use App\Modules\HRMS\Models\LeaveBalance;
use App\Modules\HRMS\Models\LeaveRequest;
use App\Modules\HRMS\Models\LeaveType;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Enums\MaterialRequestStatus;
use App\Modules\Inventory\Models\Enums\SerialNumberStatus;
use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\MaterialRequest;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Payroll\Models\Enums\SalaryCalculationType;
use App\Modules\Payroll\Models\Enums\SalaryComponentType;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Events\PurchaseOrderSent;
use App\Modules\Procurement\Models\Enums\PurchaseRequisitionStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\FactorySetting;
use App\Modules\Production\Models\MachineDowntimeLog;
use App\Modules\Production\Models\MasterbatchDosing;
use App\Modules\Production\Models\Mold;
use App\Modules\Production\Models\MoldChangeLog;
use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Models\PowerInterruptionLog;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ReworkOrder;
use App\Modules\Production\Models\Routing;
use App\Modules\Production\Models\ScrapReason;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\ShiftStockCount;
use App\Modules\Production\Models\SubcontractOrder;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Models\Capa;
use App\Modules\Quality\Models\Enums\CalibrationResult;
use App\Modules\Quality\Models\Enums\InspectionResult;
use App\Modules\Quality\Models\IncomingInspection;
use App\Modules\Quality\Models\MeasuringInstrument;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Models\SpcCharacteristic;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallyStockSnapshot;
use App\Modules\TallySync\Models\TallySyncEntry;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

/**
 * THE ONE ROW PER MODULE the Phase 7 regression smoke reads back
 * (P7-05, WS-E). Every value is synthetic — "Regression Bottle", RG-*
 * codes, rate 1.00 — never a factory figure; nothing here is evidence of
 * anything but the code paths it exercises. No Tally is read or written:
 * the events that would enqueue a voucher are faked, and the sync rows are
 * fixture rows.
 *
 * Built through the REAL endpoints where the write path is what makes the
 * row (purchase order → send → goods receipt with lots and bags; typed
 * delivery; invoice; carton labels; agent token) and with Model::create
 * where a controller would only add validation the smoke does not need.
 */
trait RegressionFixtures
{
    /** Every fixture, keyed by a short name — see seedEveryModule(). */
    protected array $fx = [];

    /**
     * An Administrator holding EVERY catalogue permission (PermissionSeeder's
     * own role), acting for the rest of the test.
     */
    protected function actAsAdministrator(): User
    {
        $this->seed(PermissionSeeder::class);

        $admin = User::factory()->create(['name' => 'Regression Admin', 'is_active' => true]);
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** @param  list<string>  $permissions */
    protected function userHolding(array $permissions, string $name = 'Regression Reader'): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /**
     * A user holding every catalogue permission EXCEPT the named ones — the
     * strongest reader who must still be refused by a module's own gate.
     *
     * @param  list<string>  $except
     */
    protected function userHoldingEverythingExcept(array $except, string $name = 'Regression Almost-Admin'): User
    {
        $all = app(PermissionService::class)->allPermissionNames();

        return $this->userHolding(array_values(array_diff($all, $except)), $name);
    }

    /**
     * Seed one row for every module's top-level list. Requires an
     * authenticated caller with production/procurement/sales/tally-sync
     * manage (the Administrator) because part of the chain goes through the
     * endpoints. Traceability is switched ON while the goods receipt lands
     * so a material lot and its bags exist; the caller decides the flag
     * afterwards.
     *
     * @return array<string, mixed>
     */
    protected function seedEveryModule(User $actor): array
    {
        Event::fake([GoodsReceiptNoteReceived::class, PurchaseOrderSent::class]);

        // ---- Inventory --------------------------------------------------
        $bottle = Item::create(['sku' => 'RG-BTL', 'name' => 'Regression Bottle', 'uom' => 'Nos', 'tally_stock_item_guid' => 'rg-guid-bottle', 'hsn_sac_code' => '99999999']);
        $resin = Item::create(['sku' => 'RG-RESIN', 'name' => 'Regression Resin', 'uom' => 'Kgs', 'tally_stock_item_guid' => 'rg-guid-resin', 'category' => ItemCategory::RawMaterial]);
        $masterbatch = Item::create(['sku' => 'RG-MB', 'name' => 'Regression Masterbatch', 'uom' => 'Kgs']);
        $carton = Item::create(['sku' => 'RG-CTN', 'name' => 'Regression Carton', 'uom' => 'Nos']);
        $fg = Warehouse::create(['code' => 'RG-FG', 'name' => 'Regression FG Store', 'tally_guid' => 'rg-gd-fg']);
        $rm = Warehouse::create(['code' => 'RG-RM', 'name' => 'Regression RM Store', 'tally_guid' => 'rg-gd-rm']);

        app(StockMovementService::class)->recordReceipt(
            itemId: $bottle->id, warehouseId: $fg->id, quantity: '5000', unitCost: '1.00', reference: 'regression seed',
        );

        $batch = Batch::create(['item_id' => $bottle->id, 'batch_number' => 'RG-B1']);
        $serial = SerialNumber::create(['item_id' => $bottle->id, 'serial_number' => 'RG-SN1', 'status' => SerialNumberStatus::Registered, 'warehouse_id' => $fg->id]);

        // ---- Procurement (through the endpoints; traceability on for the lot) ----
        $vendor = Vendor::create(['code' => 'RG-V1', 'name' => 'Regression Vendor']);
        $requisition = PurchaseRequisition::create(['status' => PurchaseRequisitionStatus::Draft, 'requested_by' => $actor->id]);

        $flagBefore = (bool) config('production.traceability_enabled');
        config(['production.traceability_enabled' => true]);

        $poId = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $vendor->id,
            'order_date' => '2026-08-10',
            'lines' => [['item_id' => $resin->id, 'quantity' => '100', 'unit_price' => '1.00']],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/procurement/purchase-orders/{$poId}/send")->assertOk();
        $po = PurchaseOrder::findOrFail($poId);

        $grnId = $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => 'rg-receipt-1',
            'purchase_order_id' => $po->id,
            'warehouse_id' => $rm->id,
            'reference' => 'RG-DC-1',
            'received_date' => '2026-08-11',
            'lines' => [[
                'purchase_order_line_id' => $po->lines()->firstOrFail()->id,
                'quantity' => '50',
                'lots' => [[
                    'supplier_lot_no' => 'RG-LOT-1',
                    'bag_count' => 2,
                    'bag_weight_kg' => '25',
                    'barcodes' => ['RG-BAG-1', 'RG-BAG-2'],
                ]],
            ]],
        ])->assertCreated()->json('data.id');
        $grn = GoodsReceiptNote::findOrFail($grnId);
        $lot = MaterialLot::query()->firstOrFail();

        // ---- a supplier bill (28-Aug) — the paper invoice, recorded ---------
        $bill = SupplierBill::create([
            'vendor_id' => $vendor->id, 'purchase_order_id' => $po->id,
            'bill_number' => 'RG-INV-1', 'bill_date' => '2026-08-12',
            'subtotal' => '50.0000', 'igst' => '9.0000', 'total' => '59.0000',
        ]);
        $bill->lines()->create([
            'goods_receipt_note_line_id' => $grn->lines()->firstOrFail()->id,
            'item_id' => $resin->id, 'quantity' => '50.0000', 'rate' => '1.0000', 'amount' => '50.0000',
        ]);

        config(['production.traceability_enabled' => $flagBefore]);

        // ---- Sales --------------------------------------------------------
        $customer = Customer::create(['code' => 'RG-C1', 'name' => 'Regression Customer', 'gstin' => '33AAAAA0000A1Z5', 'state_code' => '33']);
        $order = SalesOrder::create(['customer_id' => $customer->id, 'status' => SalesOrderStatus::Confirmed, 'order_date' => '2026-08-12']);
        $line = $order->lines()->create(['item_id' => $bottle->id, 'quantity' => '1000', 'unit_price' => '1.00', 'quantity_delivered' => 0]);

        // Dispatch is gated on internal quality approval (DEC-20260831-006), and
        // this fixture's subject is every module's LIST endpoint, not the gate.
        // Stamped directly rather than through the service, because the line is
        // deliberately never held here — holding is Store Fulfilment's subject.
        $line->forceFill([
            'quality_approved_at' => now(),
            'quality_approved_quantity' => '1000.0000',
        ])->save();

        $deliveryId = $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $fg->id,
            'delivered_date' => '2026-08-13',
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => '100']],
        ])->assertSuccessful()->json('data.id');
        $delivery = Delivery::findOrFail($deliveryId);

        // An invoice as HISTORY. The ERP raises none any more
        // (DEC-20260903-004) and the API-surface smoke test still has to be
        // able to READ one, so the row is written through the models — which
        // is exactly the shape of every invoice left on live.
        $invoice = Invoice::create([
            'sales_order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'status' => InvoiceStatus::Draft,
            'invoice_date' => '2026-08-13',
        ]);
        $invoice->lines()->create([
            'sales_order_line_id' => $line->id,
            'item_id' => $line->item_id,
            'quantity' => '100',
            'unit_price' => '1.00',
        ]);

        // ---- Production ---------------------------------------------------
        $shift = Shift::create(['name' => 'Regression Day', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::create(['code' => 'RG-M1', 'name' => 'Regression Machine 1', 'display_sequence' => 1, 'is_active' => true]);
        $mold = Mold::create(['code' => 'RG-MOLD', 'name' => 'Regression Mold', 'cavity_count' => 4]);

        $standard = ProductionStandard::create([
            'source_product_name' => 'RG PRODUCT', 'item_id' => $bottle->id,
            'cavities' => 4, 'unit_weight_grams' => 10, 'cycle_time' => 10, 'status' => 'approved',
        ]);
        $packaging = $standard->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 100]);

        $configuration = ProductionConfiguration::create([
            'work_center_id' => $machine->id, 'item_id' => $bottle->id, 'colour' => 'Natural',
            'default_cycle_time' => '10.00', 'default_cavities' => 4, 'unit_weight_grams' => '10.0000',
            'status' => 'approved', 'source' => 'REGRESSION-FIXTURE',
        ]);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id, 'item_id' => $bottle->id,
            'warehouse_id' => $fg->id, 'production_date' => '2026-08-12', 'batch_number' => '20260812-RGM1-001',
            'status' => ShiftProductionEntryStatus::Pending, 'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '1000', 'quantity_scrap' => '0', 'nos_per_box' => '100',
        ]);
        $running = ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id, 'item_id' => $bottle->id,
            'warehouse_id' => $fg->id, 'production_date' => '2026-08-13', 'batch_number' => '20260813-RGM1-001',
            'batch_status' => BatchStatus::InProgress, 'quantity_produced' => null, 'quantity_scrap' => '0',
        ]);
        $cartons = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")->assertSuccessful()->json('data');
        $cartonNo = $cartons[0]['carton_no'] ?? null;

        $bom = Bom::create(['item_id' => $bottle->id, 'name' => 'Regression BOM', 'version' => 1, 'is_active' => true]);
        $routing = Routing::create(['item_id' => $bottle->id, 'name' => 'Regression Routing', 'is_active' => true]);
        $workOrder = WorkOrder::create(['item_id' => $bottle->id, 'bom_id' => $bom->id, 'warehouse_id' => $fg->id, 'quantity_planned' => '10', 'status' => 'draft', 'created_by' => $actor->id]);
        $subcontract = SubcontractOrder::create(['vendor_id' => $vendor->id, 'item_id' => $bottle->id, 'warehouse_id' => $fg->id, 'quantity_planned' => '10', 'status' => 'draft', 'created_by' => $actor->id]);
        $rework = ReworkOrder::create(['item_id' => $bottle->id, 'warehouse_id' => $fg->id, 'quantity_input' => '10', 'status' => 'draft', 'created_by' => $actor->id]);
        $scrapReason = ScrapReason::create(['code' => 'RG-SCRAP', 'name' => 'Regression scrap reason', 'is_active' => true]);
        $downtimeReason = DowntimeReason::create([
            'code' => 'RG-DT', 'category' => 'Regression', 'description' => 'Regression downtime reason',
            'planning_type' => 'unplanned', 'reduces_runtime' => true, 'requires_note' => false,
            'selectable_at_start' => false, 'is_active' => true,
        ]);
        $downtimeLog = MachineDowntimeLog::create([
            'work_center_id' => $machine->id, 'shift_id' => $shift->id, 'production_date' => '2026-08-12',
            'nature_of_problem' => 'Regression stop', 'from_time' => '2026-08-12 08:00:00', 'status' => 'open', 'created_by' => $actor->id,
        ]);
        $moldChangeLog = MoldChangeLog::create([
            'work_center_id' => $machine->id, 'shift_id' => $shift->id, 'production_date' => '2026-08-12',
            'changed_to_item_id' => $bottle->id, 'from_time' => '2026-08-12 09:00:00', 'status' => 'open', 'created_by' => $actor->id,
        ]);
        $powerLog = PowerInterruptionLog::create([
            'shift_id' => $shift->id, 'production_date' => '2026-08-12', 'from_time' => '2026-08-12 10:00:00',
            'to_time' => '2026-08-12 10:30:00', 'idle_hours' => '0.50', 'created_by' => $actor->id,
        ]);
        $stockCount = ShiftStockCount::create([
            'shift_id' => $shift->id, 'production_date' => '2026-08-12', 'location_label' => 'Regression bin',
            'item_id' => $resin->id, 'quantity_kg' => '1.0000', 'created_by' => $actor->id,
        ]);
        $factorySetting = FactorySetting::create(['key' => 'rg_regression_setting', 'value' => '1', 'data_type' => 'string', 'label' => 'Regression setting']);
        $dosing = MasterbatchDosing::create(['masterbatch_item_id' => $masterbatch->id, 'product_item_id' => $bottle->id, 'grams_per_bottle' => '0.1000']);
        $packingMapping = PackingMaterialMapping::create(['spec_kind' => PackingMaterialMapping::KIND_CARTON, 'spec_value' => 'RG-SPEC', 'item_id' => $carton->id]);

        // ---- Tally sync (fixture rows; nothing reaches Tally) ---------------
        $syncEntry = TallySyncEntry::create([
            'syncable_type' => $invoice->getMorphClass(), 'syncable_id' => $invoice->getKey(),
            'tally_voucher_type' => 'Sales', 'payload' => ['voucher_number' => 'RG-1'],
            'status' => TallySyncStatus::Pending, 'attempts' => 0,
        ]);
        $snapshot = TallyStockSnapshot::create([
            'company' => 'Regression Company', 'as_of' => '2026-08-01',
            'lines' => [[
                'item_guid' => 'rg-guid-resin', 'tally_item_name' => 'Regression Resin', 'unit' => 'Kgs',
                'godown' => 'Regression RM Store', 'closing_quantity' => '1.0000', 'closing_rate' => '1.00',
                'closing_value' => '1.00', 'erp_item_id' => $resin->id, 'erp_warehouse_id' => $rm->id,
                'importable' => true, 'problems' => [],
            ]],
            'totals' => ['lines' => 1, 'importable' => 1],
        ]);
        $this->postJson('/api/v1/tally-sync/agent-tokens', ['name' => 'Regression agent'])->assertSuccessful();

        // ---- Quality --------------------------------------------------------
        $inspection = IncomingInspection::create([
            'goods_receipt_note_line_id' => $grn->lines()->firstOrFail()->id, 'item_id' => $resin->id,
            'inspected_quantity' => '50', 'accepted_quantity' => '50', 'rejected_quantity' => '0',
            'result' => InspectionResult::Pass, 'inspection_date' => '2026-08-11', 'inspected_by' => $actor->id,
        ]);
        $ncr = NonConformanceReport::create(['item_id' => $resin->id, 'description' => 'Regression NCR', 'raised_date' => '2026-08-11', 'raised_by' => $actor->id]);
        $capa = Capa::create(['title' => 'Regression CAPA', 'problem_statement' => 'Regression problem', 'created_by' => $actor->id]);
        $instrument = MeasuringInstrument::create(['code' => 'RG-INST', 'name' => 'Regression gauge', 'calibration_frequency_days' => 30, 'next_calibration_due' => '2026-09-01']);
        $instrument->calibrationRecords()->create(['calibrated_date' => '2026-08-01', 'result' => CalibrationResult::Pass]);
        $characteristic = SpcCharacteristic::create(['item_id' => $bottle->id, 'name' => 'Regression weight', 'unit_of_measure' => 'g']);
        $characteristic->measurements()->create(['value' => '10.0000', 'measured_at' => '2026-08-12 08:00:00', 'recorded_by' => $actor->id]);

        // ---- Maintenance ----------------------------------------------------
        $asset = Asset::create(['code' => 'RG-A1', 'name' => 'Regression asset']);
        $schedule = MaintenanceSchedule::create(['asset_id' => $asset->id, 'name' => 'Regression PM', 'frequency_days' => 30, 'next_due_date' => '2026-09-01']);
        $maintenanceOrder = MaintenanceWorkOrder::create(['asset_id' => $asset->id, 'type' => MaintenanceWorkOrderType::Preventive, 'reported_date' => '2026-08-12', 'created_by' => $actor->id]);

        // ---- HRMS -----------------------------------------------------------
        $employee = Employee::create(['employee_code' => 'RG-E1', 'name' => 'Regression Employee', 'date_of_joining' => '2026-01-01']);
        $leaveType = LeaveType::create(['code' => 'RG-CL', 'name' => 'Regression leave', 'default_annual_days' => 12]);
        $leaveBalance = LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => 2026, 'allocated_days' => 12]);
        $leaveRequest = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-08-20', 'end_date' => '2026-08-20', 'days' => 1]);
        $attendance = Attendance::create(['employee_id' => $employee->id, 'date' => '2026-08-12', 'status' => AttendanceStatus::Present]);

        // ---- Payroll --------------------------------------------------------
        $component = SalaryComponent::create(['code' => 'RG-BASIC', 'name' => 'Regression basic', 'type' => SalaryComponentType::Earning, 'calculation_type' => SalaryCalculationType::FixedAmount]);
        $structure = SalaryStructure::create(['employee_id' => $employee->id, 'effective_from' => '2026-01-01']);
        $structure->lines()->create(['salary_component_id' => $component->id, 'amount' => '1.0000']);
        $run = PayrollRun::create(['year' => 2026, 'month' => 7]);
        $payslip = Payslip::create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'gross_earnings' => '1.0000', 'total_deductions' => '0.0000', 'net_pay' => '1.0000']);

        // ---- Finance --------------------------------------------------------
        $bank = GLAccount::create(['code' => 'RG-1000', 'name' => 'Regression bank', 'type' => GLAccountType::Asset, 'is_active' => true]);
        $revenue = GLAccount::create(['code' => 'RG-4000', 'name' => 'Regression revenue', 'type' => GLAccountType::Revenue, 'is_active' => true]);
        $journal = JournalEntry::create(['entry_date' => '2026-08-12', 'reference' => 'RG-JE-1', 'created_by' => $actor->id]);
        $journal->lines()->create(['gl_account_id' => $bank->id, 'debit' => '1.0000', 'credit' => '0.0000']);
        $journal->lines()->create(['gl_account_id' => $revenue->id, 'debit' => '0.0000', 'credit' => '1.0000']);

        // ---- CRM ------------------------------------------------------------
        $lead = Lead::create(['name' => 'Regression Lead', 'assigned_to' => $actor->id]);
        LeadActivity::create(['lead_id' => $lead->id, 'type' => LeadActivityType::Note, 'notes' => 'Regression note', 'activity_date' => '2026-08-12', 'created_by' => $actor->id]);
        $opportunity = Opportunity::create(['name' => 'Regression Opportunity', 'customer_id' => $customer->id, 'lead_id' => $lead->id]);
        $quotation = Quotation::create(['opportunity_id' => $opportunity->id, 'customer_id' => $customer->id, 'quotation_date' => '2026-08-12', 'created_by' => $actor->id]);
        $quotation->lines()->create(['item_id' => $bottle->id, 'quantity' => '1', 'unit_price' => '1.0000']);

        // ---- Store → production material flow (Phase 7.5) -------------------
        // A consumable request, so it legitimately names a machine; a resin
        // request would carry none (FC-01 / DEC-20260807-006).
        $materialRequest = MaterialRequest::create([
            'status' => MaterialRequestStatus::Submitted,
            'requested_by' => $actor->id,
            'requested_at' => '2026-08-12 07:00:00',
            'submitted_at' => '2026-08-12 07:05:00',
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
        ]);
        $materialRequest->lines()->create([
            'item_id' => $carton->id, 'quantity' => '40', 'uom' => $carton->uom,
        ]);

        // The store's answer to it: a handover into Production/WIP. Written
        // straight to the models rather than through StoreIssueService, so
        // this fixture stays a fixture — it must not move stock or need a
        // configured WIP location to exist (Phase 7.5, WS-B).
        $wip = Warehouse::firstOrCreate(['code' => 'WIP'], ['name' => 'Work In Progress', 'is_active' => false]);
        $storeIssue = StoreIssue::create([
            'issue_number' => 'SI-999001',
            'material_request_id' => $materialRequest->id,
            'status' => StoreIssueStatus::Issued,
            'issued_by' => $actor->id,
            'received_by' => $actor->id,
            'issued_at' => '2026-08-12 07:30:00',
        ]);
        $storeIssue->lines()->create([
            'material_request_line_id' => $materialRequest->lines()->value('id'),
            'quantity_requested' => '40',
            'item_id' => $carton->id,
            'from_warehouse_id' => $rm->id,
            'to_warehouse_id' => $wip->id,
            'quantity_issued' => '25',
            'quantity_returned' => '0',
            'uom' => $carton->uom,
        ]);

        // ---- Compliance -----------------------------------------------------
        $gstRate = GstRate::create(['hsn_sac_code' => '99999999', 'description' => 'Regression rate', 'rate_percent' => '18.00']);
        $gstRegistration = GstRegistration::create(['gstin' => '33AAAAA0000A1Z5', 'state_code' => '33', 'state_name' => 'Regression State', 'is_primary' => true]);

        return $this->fx = compact(
            'bottle', 'resin', 'masterbatch', 'carton', 'fg', 'rm', 'batch', 'serial',
            'vendor', 'requisition', 'po', 'grn', 'lot', 'bill',
            'customer', 'order', 'delivery', 'invoice',
            'shift', 'machine', 'mold', 'standard', 'packaging', 'configuration', 'entry', 'running', 'cartonNo',
            'bom', 'routing', 'workOrder', 'subcontract', 'rework', 'scrapReason', 'downtimeReason',
            'downtimeLog', 'moldChangeLog', 'powerLog', 'stockCount', 'factorySetting', 'dosing', 'packingMapping',
            'syncEntry', 'snapshot',
            'inspection', 'ncr', 'capa', 'instrument', 'characteristic',
            'asset', 'schedule', 'maintenanceOrder',
            'employee', 'leaveType', 'leaveBalance', 'leaveRequest', 'attendance',
            'component', 'structure', 'run', 'payslip',
            'bank', 'revenue', 'journal',
            'lead', 'opportunity', 'quotation',
            'gstRate', 'gstRegistration',
            'materialRequest', 'storeIssue',
        );
    }
}
