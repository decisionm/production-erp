<?php

namespace Database\Seeders;

use App\Modules\Compliance\Models\GstRate;
use App\Modules\Compliance\Models\GstRegistration;
use App\Modules\Finance\Models\Enums\GLAccountType;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\LeaveType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Maintenance\Services\AssetService;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Services\SalaryStructureService;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\RoutingService;
use App\Modules\Production\Services\WorkCenterService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Models\SpcCharacteristic;
use App\Modules\Quality\Services\CapaService;
use App\Modules\Quality\Services\NonConformanceReportService;
use App\Modules\Quality\Services\SpcMeasurementService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\InvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Database\Seeder;

/**
 * Pilot customer demo data: a small PET/HDPE bottle manufacturer in
 * Puducherry (GST state code 34) — preforms are injection molded in-house
 * then stretch-blow molded into PET bottles, while small HDPE bottles are
 * extrusion blow molded directly from resin (no preform step), which is
 * why the two BOMs below take different shapes. Not exhaustive — rich
 * master data across every module, plus a handful of illustrative
 * transactions per module rather than a full transactional history.
 */
class BottleManufacturingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedGstRegistrationAndRates();
        $warehouses = $this->seedWarehouses();
        $items = $this->seedItems();
        $this->seedGlAccounts();
        [$preformBom, $bottleBom, $hdpeBom] = $this->seedBoms($items);
        $workCenters = $this->seedWorkCentersAndRouting($items);
        $vendors = $this->seedVendors();
        $customers = $this->seedCustomers();
        $employees = $this->seedEmployeesAndPayrollSetup();
        $this->seedAssetsAndMaintenance($employees);
        $this->seedSpc($items);

        $this->seedProcurementCycle($vendors, $items, $warehouses);
        $this->seedProductionCycle($items, $warehouses, $preformBom, $bottleBom, $hdpeBom);
        $this->seedSalesCycle($customers, $items);
        $this->seedQualityExample($items);
    }

    private function seedGstRegistrationAndRates(): void
    {
        GstRegistration::create([
            'gstin' => '34AABCP1234C1Z5',
            'state_code' => '34',
            'state_name' => 'Puducherry',
            'is_primary' => true,
            'is_active' => true,
        ]);

        $rates = [
            ['hsn_sac_code' => '3907', 'description' => 'PET Resin', 'rate_percent' => '18.00'],
            ['hsn_sac_code' => '3901', 'description' => 'HDPE Resin', 'rate_percent' => '18.00'],
            ['hsn_sac_code' => '3206', 'description' => 'Colour Masterbatch', 'rate_percent' => '18.00'],
            ['hsn_sac_code' => '3923', 'description' => 'Plastic Bottles, Preforms, Caps & Closures', 'rate_percent' => '18.00'],
            ['hsn_sac_code' => '4821', 'description' => 'Printed Labels', 'rate_percent' => '12.00'],
            ['hsn_sac_code' => '4819', 'description' => 'Corrugated Cartons', 'rate_percent' => '18.00'],
        ];

        foreach ($rates as $rate) {
            GstRate::create(['is_active' => true, ...$rate]);
        }
    }

    /** @return array<string, Warehouse> */
    private function seedWarehouses(): array
    {
        $warehouses = [
            'RM-STORE' => 'Raw Material Store',
            'WIP' => 'Work In Progress',
            'FG-STORE' => 'Finished Goods Store',
        ];

        $created = [];
        foreach ($warehouses as $code => $name) {
            $created[$code] = Warehouse::create(['code' => $code, 'name' => $name, 'is_active' => true]);
        }

        return $created;
    }

    /** @return array<string, Item> */
    private function seedItems(): array
    {
        $items = [
            ['sku' => 'PET-RESIN', 'name' => 'PET Resin (Virgin Grade)', 'uom' => 'kg', 'hsn_sac_code' => '3907', 'reorder_level' => '200.0000'],
            ['sku' => 'HDPE-RESIN', 'name' => 'HDPE Resin', 'uom' => 'kg', 'hsn_sac_code' => '3901', 'reorder_level' => '150.0000'],
            ['sku' => 'MB-CLEAR', 'name' => 'Colour Masterbatch (Clear Additive)', 'uom' => 'kg', 'hsn_sac_code' => '3206', 'reorder_level' => '10.0000'],
            ['sku' => 'MB-BLUE', 'name' => 'Colour Masterbatch (Blue)', 'uom' => 'kg', 'hsn_sac_code' => '3206', 'reorder_level' => '10.0000'],
            ['sku' => 'CAP-28MM', 'name' => '28mm Tamper-Evident Cap', 'uom' => 'pcs', 'hsn_sac_code' => '3923', 'reorder_level' => '5000.0000'],
            ['sku' => 'LABEL-500ML', 'name' => 'Label — 500ml Bottle', 'uom' => 'pcs', 'hsn_sac_code' => '4821', 'reorder_level' => '5000.0000'],
            ['sku' => 'CARTON-24', 'name' => 'Corrugated Carton (24-Bottle)', 'uom' => 'pcs', 'hsn_sac_code' => '4819', 'reorder_level' => '200.0000'],
            ['sku' => 'PREFORM-28G', 'name' => 'PET Preform 28g (500ml Neck)', 'uom' => 'pcs', 'hsn_sac_code' => '3923', 'reorder_level' => '1000.0000', 'nominal_weight_grams' => '28.0000'],
            ['sku' => 'BTL-PET-500', 'name' => '500ml PET Bottle (Round)', 'uom' => 'pcs', 'hsn_sac_code' => '3923', 'reorder_level' => '1000.0000', 'nominal_weight_grams' => '25.5000'],
            ['sku' => 'BTL-PET-1000', 'name' => '1 Litre PET Bottle', 'uom' => 'pcs', 'hsn_sac_code' => '3923', 'reorder_level' => '500.0000', 'nominal_weight_grams' => '38.0000'],
            ['sku' => 'BTL-HDPE-200', 'name' => '200ml HDPE Bottle', 'uom' => 'pcs', 'hsn_sac_code' => '3923', 'reorder_level' => '500.0000', 'nominal_weight_grams' => '12.0000'],
        ];

        $created = [];
        foreach ($items as $item) {
            $created[$item['sku']] = Item::create(['is_active' => true, ...$item]);
        }

        return $created;
    }

    private function seedGlAccounts(): void
    {
        $accounts = [
            ['code' => '1001', 'name' => 'Cash', 'type' => GLAccountType::Asset],
            ['code' => '1002', 'name' => 'Bank — Current Account', 'type' => GLAccountType::Asset],
            ['code' => '1101', 'name' => 'Accounts Receivable', 'type' => GLAccountType::Asset],
            ['code' => '1201', 'name' => 'Raw Material Inventory', 'type' => GLAccountType::Asset],
            ['code' => '1202', 'name' => 'Finished Goods Inventory', 'type' => GLAccountType::Asset],
            ['code' => '1301', 'name' => 'Plant & Machinery', 'type' => GLAccountType::Asset],
            ['code' => '2001', 'name' => 'Accounts Payable', 'type' => GLAccountType::Liability],
            ['code' => '2101', 'name' => 'GST Payable', 'type' => GLAccountType::Liability],
            ['code' => '2201', 'name' => 'Salaries Payable', 'type' => GLAccountType::Liability],
            ['code' => '3001', 'name' => "Owner's Capital", 'type' => GLAccountType::Equity],
            ['code' => '4001', 'name' => 'Sales Revenue', 'type' => GLAccountType::Revenue],
            ['code' => '5001', 'name' => 'Raw Material Consumed', 'type' => GLAccountType::Expense],
            ['code' => '5002', 'name' => 'Salaries & Wages', 'type' => GLAccountType::Expense],
            ['code' => '5003', 'name' => 'Power & Fuel', 'type' => GLAccountType::Expense],
            ['code' => '5004', 'name' => 'Repairs & Maintenance', 'type' => GLAccountType::Expense],
            ['code' => '5005', 'name' => 'Factory Rent', 'type' => GLAccountType::Expense],
        ];

        foreach ($accounts as $account) {
            GLAccount::create(['is_active' => true, ...$account]);
        }
    }

    /**
     * @param  array<string, Item>  $items
     * @return array{0: Bom, 1: Bom, 2: Bom}
     */
    private function seedBoms(array $items): array
    {
        $boms = app(BomService::class);

        // In-house injection molded preform: resin + a touch of masterbatch.
        $preformBom = $boms->create([
            'item_id' => $items['PREFORM-28G']->id,
            'name' => 'Preform 28g — Standard',
            'lines' => [
                ['component_item_id' => $items['PET-RESIN']->id, 'quantity_per' => '0.0280'],
                ['component_item_id' => $items['MB-CLEAR']->id, 'quantity_per' => '0.0005'],
            ],
        ]);

        // Stretch-blow molded from the preform above (multi-level — MRP
        // explodes through this into the preform's own resin requirement).
        $bottleBom = $boms->create([
            'item_id' => $items['BTL-PET-500']->id,
            'name' => '500ml PET Bottle — Standard',
            'lines' => [
                ['component_item_id' => $items['PREFORM-28G']->id, 'quantity_per' => '1.0000'],
                ['component_item_id' => $items['CAP-28MM']->id, 'quantity_per' => '1.0000'],
                ['component_item_id' => $items['LABEL-500ML']->id, 'quantity_per' => '1.0000'],
            ],
        ]);

        // Extrusion blow molded directly from HDPE resin — no preform step,
        // unlike the PET route above (a deliberately different shape to
        // reflect how these two processes actually differ on the shop floor).
        $hdpeBom = $boms->create([
            'item_id' => $items['BTL-HDPE-200']->id,
            'name' => '200ml HDPE Bottle — Standard',
            'lines' => [
                ['component_item_id' => $items['HDPE-RESIN']->id, 'quantity_per' => '0.0150'],
                ['component_item_id' => $items['MB-BLUE']->id, 'quantity_per' => '0.0003'],
                ['component_item_id' => $items['CAP-28MM']->id, 'quantity_per' => '1.0000'],
            ],
        ]);

        return [$preformBom, $bottleBom, $hdpeBom];
    }

    /**
     * @param  array<string, Item>  $items
     * @return array<string, WorkCenter>
     */
    private function seedWorkCentersAndRouting(array $items): array
    {
        $workCenterService = app(WorkCenterService::class);

        $centers = [
            'INJ-01' => 'Injection Molding Machine 1',
            'BLOW-01' => 'Stretch Blow Molding Machine 1',
            'EBM-01' => 'Extrusion Blow Molding Machine 1',
            'LABEL-01' => 'Labeling Station',
            'PACK-01' => 'Packing Station',
        ];

        $created = [];
        foreach ($centers as $code => $name) {
            $created[$code] = $workCenterService->create(['code' => $code, 'name' => $name]);
        }

        app(RoutingService::class)->create([
            'item_id' => $items['BTL-PET-500']->id,
            'name' => '500ml PET Bottle — Standard Routing',
            'operations' => [
                ['work_center_id' => $created['INJ-01']->id, 'sequence' => 10, 'name' => 'Injection Mold Preform', 'standard_time_minutes' => '8.00'],
                ['work_center_id' => $created['BLOW-01']->id, 'sequence' => 20, 'name' => 'Stretch Blow Mold Bottle', 'standard_time_minutes' => '5.00'],
                ['work_center_id' => $created['LABEL-01']->id, 'sequence' => 30, 'name' => 'Apply Label', 'standard_time_minutes' => '2.00'],
                ['work_center_id' => $created['PACK-01']->id, 'sequence' => 40, 'name' => 'Pack into Carton', 'standard_time_minutes' => '3.00'],
            ],
        ]);

        return $created;
    }

    /** @return array<string, Vendor> */
    private function seedVendors(): array
    {
        $vendors = [
            [
                'code' => 'VEN-RESIN',
                'name' => 'Sri Manakula Polymers Pvt Ltd',
                'email' => 'sales@manakulapolymers.example',
                'phone' => '9994412345',
                'address' => 'Industrial Estate, Thattanchavady, Puducherry - 605009',
                'gstin' => '34AAACS5678D1Z2',
                'state_code' => '34',
            ],
            [
                'code' => 'VEN-CAPS',
                'name' => 'Chennai Closures & Caps Ltd',
                'email' => 'orders@chennaiclosures.example',
                'phone' => '9840098765',
                'address' => 'Ambattur Industrial Estate, Chennai, Tamil Nadu - 600058',
                'gstin' => '33AABCC4321E1Z8',
                'state_code' => '33',
            ],
            [
                'code' => 'VEN-LABEL',
                'name' => 'Auro Print & Packaging',
                'email' => 'info@auroprint.example',
                'phone' => '9443376543',
                'address' => 'Mudaliarpet, Puducherry - 605004',
                'gstin' => '34AADCP8765F1Z1',
                'state_code' => '34',
            ],
        ];

        $created = [];
        foreach ($vendors as $vendor) {
            $created[$vendor['code']] = Vendor::create(['is_active' => true, ...$vendor]);
        }

        return $created;
    }

    /** @return array<string, Customer> */
    private function seedCustomers(): array
    {
        $customers = [
            [
                'code' => 'CUS-AURO',
                'name' => 'Sri Aurobindo Beverages Pvt Ltd',
                'email' => 'procurement@aurobindobeverages.example',
                'phone' => '9994455667',
                'address' => 'Lawspet Industrial Area, Puducherry - 605008',
                'gstin' => '34AABCA1122G1Z4',
                'state_code' => '34',
            ],
            [
                'code' => 'CUS-TNDAIRY',
                'name' => 'Tamil Nadu Dairy Products Co',
                'email' => 'purchase@tndairy.example',
                'phone' => '9840011223',
                'address' => 'Cuddalore Main Road, Tamil Nadu - 607001',
                'gstin' => '33AABCT3344H1Z7',
                'state_code' => '33',
            ],
            [
                'code' => 'CUS-KERALA',
                'name' => 'Kerala Aqua Packaging',
                'email' => 'orders@keralaaqua.example',
                'phone' => '9946655443',
                'address' => 'Kaloor, Kochi, Kerala - 682017',
                'gstin' => '32AABCK5566J1Z0',
                'state_code' => '32',
            ],
        ];

        $created = [];
        foreach ($customers as $customer) {
            $created[$customer['code']] = Customer::create(['is_active' => true, ...$customer]);
        }

        return $created;
    }

    /** @return array<string, Employee> */
    private function seedEmployeesAndPayrollSetup(): array
    {
        $employees = [
            ['employee_code' => 'EMP-001', 'name' => 'Karthik Subramaniam', 'designation' => 'Plant Manager', 'department' => 'Production', 'date_of_joining' => '2019-04-01'],
            ['employee_code' => 'EMP-002', 'name' => 'Lakshmi Narayanan', 'designation' => 'Production Supervisor', 'department' => 'Production', 'date_of_joining' => '2020-06-15'],
            ['employee_code' => 'EMP-003', 'name' => 'Priya Raman', 'designation' => 'QC Inspector', 'department' => 'Quality', 'date_of_joining' => '2021-01-10'],
            ['employee_code' => 'EMP-004', 'name' => 'Selvam Murugan', 'designation' => 'Machine Operator — Injection', 'department' => 'Production', 'date_of_joining' => '2021-08-01'],
            ['employee_code' => 'EMP-005', 'name' => 'Bala Krishnan', 'designation' => 'Machine Operator — Blow Molding', 'department' => 'Production', 'date_of_joining' => '2022-02-14'],
            ['employee_code' => 'EMP-006', 'name' => 'Divya Chandran', 'designation' => 'Accounts Executive', 'department' => 'Finance', 'date_of_joining' => '2020-11-01'],
            ['employee_code' => 'EMP-007', 'name' => 'Meera Pillai', 'designation' => 'HR Executive', 'department' => 'HR', 'date_of_joining' => '2022-05-20'],
        ];

        $created = [];
        foreach ($employees as $employee) {
            $created[$employee['employee_code']] = Employee::create(['status' => 'active', ...$employee]);
        }
        $created['EMP-002']->update(['manager_id' => $created['EMP-001']->id]);
        $created['EMP-003']->update(['manager_id' => $created['EMP-001']->id]);
        $created['EMP-004']->update(['manager_id' => $created['EMP-002']->id]);
        $created['EMP-005']->update(['manager_id' => $created['EMP-002']->id]);

        foreach ([
            ['code' => 'EL', 'name' => 'Earned Leave', 'default_annual_days' => '12.00'],
            ['code' => 'CL', 'name' => 'Casual Leave', 'default_annual_days' => '7.00'],
            ['code' => 'SL', 'name' => 'Sick Leave', 'default_annual_days' => '7.00'],
        ] as $leaveType) {
            LeaveType::create(['is_active' => true, ...$leaveType]);
        }

        $basic = SalaryComponent::create(['code' => 'BASIC', 'name' => 'Basic', 'type' => 'earning', 'calculation_type' => 'fixed_amount', 'is_active' => true]);
        $hra = SalaryComponent::create(['code' => 'HRA', 'name' => 'House Rent Allowance', 'type' => 'earning', 'calculation_type' => 'percentage_of_basic', 'percentage' => '40.00', 'is_active' => true]);
        $special = SalaryComponent::create(['code' => 'SPECIAL', 'name' => 'Special Allowance', 'type' => 'earning', 'calculation_type' => 'fixed_amount', 'is_active' => true]);
        $pt = SalaryComponent::create(['code' => 'PT', 'name' => 'Professional Tax', 'type' => 'deduction', 'calculation_type' => 'fixed_amount', 'is_active' => true]);

        app(SalaryStructureService::class)->create([
            'employee_id' => $created['EMP-001']->id,
            'effective_from' => '2026-04-01',
            'lines' => [
                ['salary_component_id' => $basic->id, 'amount' => '45000'],
                ['salary_component_id' => $hra->id],
                ['salary_component_id' => $special->id, 'amount' => '10000'],
                ['salary_component_id' => $pt->id, 'amount' => '200'],
            ],
        ]);

        return $created;
    }

    /** @param array<string, Employee> $employees */
    private function seedAssetsAndMaintenance(array $employees): void
    {
        $assetService = app(AssetService::class);

        $injectionMachine = $assetService->create([
            'code' => 'AST-INJ-01', 'name' => 'Injection Molding Machine 1', 'category' => 'Machinery',
            'location' => 'Shop Floor — Bay 1', 'purchase_date' => '2019-03-15', 'purchase_cost' => '4500000',
        ]);
        $blowMachine = $assetService->create([
            'code' => 'AST-BLOW-01', 'name' => 'Stretch Blow Molding Machine 1', 'category' => 'Machinery',
            'location' => 'Shop Floor — Bay 2', 'purchase_date' => '2019-03-15', 'purchase_cost' => '3200000',
        ]);
        $assetService->create([
            'code' => 'AST-COMP-01', 'name' => 'Air Compressor 1', 'category' => 'Utility',
            'location' => 'Utility Room', 'purchase_date' => '2020-01-10', 'purchase_cost' => '650000',
        ]);
        $assetService->create([
            'code' => 'AST-DG-01', 'name' => 'DG Set 125kVA', 'category' => 'Utility',
            'location' => 'Backup Power Yard', 'purchase_date' => '2019-06-01', 'purchase_cost' => '1200000',
        ]);

        app(MaintenanceScheduleService::class)->create([
            'asset_id' => $injectionMachine->id,
            'name' => 'Quarterly Preventive Maintenance',
            'frequency_days' => 90,
            'next_due_date' => now()->addDays(30)->toDateString(),
        ]);

        app(MaintenanceWorkOrderService::class)->create([
            'asset_id' => $blowMachine->id,
            'type' => 'corrective',
            'description' => 'Mold clamp pressure fluctuating — intermittent short-shot bottles reported by QC.',
            'assigned_to' => $employees['EMP-005']->id,
        ]);
    }

    /** @param array<string, Item> $items */
    private function seedSpc(array $items): void
    {
        $characteristic = SpcCharacteristic::create([
            'item_id' => $items['BTL-PET-500']->id,
            'name' => 'Neck Diameter',
            'unit_of_measure' => 'mm',
            'target_value' => '28.0000',
            'lower_spec_limit' => '27.8000',
            'upper_spec_limit' => '28.2000',
            'is_active' => true,
        ]);

        $measurements = app(SpcMeasurementService::class);
        $readings = [27.98, 28.02, 27.95, 28.05, 28.00, 27.97, 28.03, 28.01, 27.99, 28.04];
        foreach ($readings as $offset => $value) {
            $measurements->record($characteristic, [
                'value' => (string) $value,
                'measured_at' => now()->subHours(count($readings) - $offset)->toIso8601String(),
            ], null);
        }
    }

    /**
     * @param  array<string, Vendor>  $vendors
     * @param  array<string, Item>  $items
     * @param  array<string, Warehouse>  $warehouses
     */
    private function seedProcurementCycle(array $vendors, array $items, array $warehouses): void
    {
        $poService = app(PurchaseOrderService::class);

        $order = $poService->create([
            'vendor_id' => $vendors['VEN-RESIN']->id,
            'order_date' => now()->subDays(10)->toDateString(),
            'expected_date' => now()->subDays(3)->toDateString(),
            'lines' => [
                ['item_id' => $items['PET-RESIN']->id, 'quantity' => '1000.0000', 'unit_price' => '95.0000'],
                ['item_id' => $items['MB-CLEAR']->id, 'quantity' => '25.0000', 'unit_price' => '340.0000'],
            ],
        ], null);

        $order = $poService->send($order);

        app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouses['RM-STORE']->id,
            'reference' => 'GRN against '.$order->id,
            'received_date' => now()->subDays(3)->toDateString(),
            'lines' => [
                ['purchase_order_line_id' => $order->lines[0]->id, 'quantity' => '1000.0000'],
                ['purchase_order_line_id' => $order->lines[1]->id, 'quantity' => '25.0000'],
            ],
        ], null);

        // A second, smaller receipt of HDPE resin and caps so the direct
        // extrusion-blow route and downstream bottle assembly both have
        // stock to draw on too.
        $capsOrder = $poService->send($poService->create([
            'vendor_id' => $vendors['VEN-CAPS']->id,
            'order_date' => now()->subDays(8)->toDateString(),
            'lines' => [
                ['item_id' => $items['CAP-28MM']->id, 'quantity' => '10000.0000', 'unit_price' => '1.2000'],
            ],
        ], null));

        app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $capsOrder->id,
            'warehouse_id' => $warehouses['RM-STORE']->id,
            'received_date' => now()->subDays(2)->toDateString(),
            'lines' => [
                ['purchase_order_line_id' => $capsOrder->lines[0]->id, 'quantity' => '10000.0000'],
            ],
        ], null);

        $labelOrder = $poService->send($poService->create([
            'vendor_id' => $vendors['VEN-LABEL']->id,
            'order_date' => now()->subDays(8)->toDateString(),
            'lines' => [
                ['item_id' => $items['LABEL-500ML']->id, 'quantity' => '10000.0000', 'unit_price' => '0.8500'],
            ],
        ], null));

        app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $labelOrder->id,
            'warehouse_id' => $warehouses['RM-STORE']->id,
            'received_date' => now()->subDays(2)->toDateString(),
            'lines' => [
                ['purchase_order_line_id' => $labelOrder->lines[0]->id, 'quantity' => '10000.0000'],
            ],
        ], null);

        // HDPE resin receipt via direct stock movement — not every raw
        // material needs a full PO cycle in this demo dataset.
        app(StockMovementService::class)->recordReceipt(
            itemId: $items['HDPE-RESIN']->id,
            warehouseId: $warehouses['RM-STORE']->id,
            quantity: '300.0000',
            unitCost: '110.0000',
            reference: 'Opening stock',
        );
        app(StockMovementService::class)->recordReceipt(
            itemId: $items['MB-BLUE']->id,
            warehouseId: $warehouses['RM-STORE']->id,
            quantity: '10.0000',
            unitCost: '340.0000',
            reference: 'Opening stock',
        );

        // WorkOrderService issues materials from — and receives output
        // into — a single warehouse per work order, so everything a work
        // order needs has to be staged in that one warehouse first. Move
        // what production needs from the raw material store onto the shop
        // floor (WIP) before any work order runs.
        $stock = app(StockMovementService::class);
        $toWip = fn (string $sku, string $qty) => $stock->recordTransfer(
            itemId: $items[$sku]->id,
            fromWarehouseId: $warehouses['RM-STORE']->id,
            toWarehouseId: $warehouses['WIP']->id,
            quantity: $qty,
            reference: 'Issue to shop floor',
        );
        $toWip('PET-RESIN', '1000.0000');
        $toWip('MB-CLEAR', '25.0000');
        $toWip('CAP-28MM', '10000.0000');
        $toWip('LABEL-500ML', '10000.0000');
        $toWip('HDPE-RESIN', '300.0000');
        $toWip('MB-BLUE', '10.0000');
    }

    /**
     * @param  array<string, Item>  $items
     * @param  array<string, Warehouse>  $warehouses
     */
    private function seedProductionCycle(array $items, array $warehouses, Bom $preformBom, Bom $bottleBom, Bom $hdpeBom): void
    {
        $workOrders = app(WorkOrderService::class);

        // Stage 1 — mold preforms from resin.
        $preformWo = $workOrders->create([
            'item_id' => $items['PREFORM-28G']->id,
            'bom_id' => $preformBom->id,
            'warehouse_id' => $warehouses['WIP']->id,
            'quantity_planned' => '5000.0000',
        ]);
        $preformWo = $workOrders->release($preformWo);
        $workOrders->complete($preformWo, '4950.0000'); // slight yield loss, realistic

        // Stage 2 — stretch-blow mold bottles from the preforms just made,
        // plus caps and labels received in the procurement cycle above.
        $bottleWo = $workOrders->create([
            'item_id' => $items['BTL-PET-500']->id,
            'bom_id' => $bottleBom->id,
            'warehouse_id' => $warehouses['WIP']->id,
            'quantity_planned' => '4000.0000',
        ]);
        $bottleWo = $workOrders->release($bottleWo);
        $bottleWo = $workOrders->complete($bottleWo, '3980.0000');

        // HDPE route — direct from resin, no preform stage.
        $hdpeWo = $workOrders->create([
            'item_id' => $items['BTL-HDPE-200']->id,
            'bom_id' => $hdpeBom->id,
            'warehouse_id' => $warehouses['WIP']->id,
            'quantity_planned' => '2000.0000',
        ]);
        $hdpeWo = $workOrders->release($hdpeWo);
        $hdpeWo = $workOrders->complete($hdpeWo, '1990.0000');

        // QC releases finished goods from the shop floor into the finished
        // goods store, ready for dispatch.
        $stock = app(StockMovementService::class);
        $stock->recordTransfer(
            itemId: $items['BTL-PET-500']->id,
            fromWarehouseId: $warehouses['WIP']->id,
            toWarehouseId: $warehouses['FG-STORE']->id,
            quantity: (string) $bottleWo->quantity_completed,
            reference: 'QC release to FG store',
        );
        $stock->recordTransfer(
            itemId: $items['BTL-HDPE-200']->id,
            fromWarehouseId: $warehouses['WIP']->id,
            toWarehouseId: $warehouses['FG-STORE']->id,
            quantity: (string) $hdpeWo->quantity_completed,
            reference: 'QC release to FG store',
        );
    }

    /**
     * @param  array<string, Customer>  $customers
     * @param  array<string, Item>  $items
     */
    private function seedSalesCycle(array $customers, array $items): void
    {
        $salesOrders = app(SalesOrderService::class);

        $order = $salesOrders->create([
            'customer_id' => $customers['CUS-AURO']->id,
            'order_date' => now()->subDays(2)->toDateString(),
            'expected_date' => now()->addDays(3)->toDateString(),
            'lines' => [
                ['item_id' => $items['BTL-PET-500']->id, 'quantity' => '2000.0000', 'unit_price' => '4.5000'],
            ],
        ], null);

        $order = $salesOrders->confirm($order);

        $delivery = app(DeliveryService::class)->create([
            'sales_order_id' => $order->id,
            'warehouse_id' => Warehouse::where('code', 'FG-STORE')->value('id'),
            'delivered_date' => now()->subDay()->toDateString(),
            'lines' => [
                ['sales_order_line_id' => $order->lines[0]->id, 'quantity' => '2000.0000'],
            ],
        ], null);

        $invoiceService = app(InvoiceService::class);
        $invoice = $invoiceService->create([
            'sales_order_id' => $order->id,
            'invoice_date' => now()->subDay()->toDateString(),
            'due_date' => now()->addDays(29)->toDateString(),
            'lines' => [
                ['sales_order_line_id' => $order->lines[0]->id, 'quantity' => '2000.0000', 'unit_price' => '4.5000'],
            ],
        ], null);
        $invoiceService->issue($invoice);

        unset($delivery);
    }

    /** @param array<string, Item> $items */
    private function seedQualityExample(array $items): void
    {
        $ncr = app(NonConformanceReportService::class)->create([
            'item_id' => $items['PREFORM-28G']->id,
            'description' => 'Short-shot preforms detected during in-process check — approx. 2% of batch showing incomplete fill near the neck.',
            'severity' => 'major',
            'quantity_affected' => '110.0000',
            'raised_date' => now()->subDays(4)->toDateString(),
        ], null);

        app(CapaService::class)->create([
            'non_conformance_report_id' => $ncr->id,
            'title' => 'Eliminate short-shot preforms on INJ-01',
            'problem_statement' => 'Intermittent short-shot defects on 28g preforms from Injection Molding Machine 1, traced to inconsistent clamp pressure.',
            'due_date' => now()->addDays(14)->toDateString(),
        ], null);
    }
}
