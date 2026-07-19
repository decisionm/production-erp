<?php

use App\Modules\Compliance\Http\Controllers\GstComputationController;
use App\Modules\Compliance\Http\Controllers\GstRateController;
use App\Modules\Compliance\Http\Controllers\GstRegistrationController;
use App\Modules\Compliance\Http\Controllers\GstReportController;
use App\Modules\Core\Http\Controllers\AuthController;
use App\Modules\Core\Http\Controllers\UserController;
use App\Modules\CRM\Http\Controllers\LeadController;
use App\Modules\CRM\Http\Controllers\OpportunityController;
use App\Modules\CRM\Http\Controllers\QuotationController;
use App\Modules\Finance\Http\Controllers\FinancialReportController;
use App\Modules\Finance\Http\Controllers\GLAccountController;
use App\Modules\Finance\Http\Controllers\JournalEntryController;
use App\Modules\HRMS\Http\Controllers\AttendanceController;
use App\Modules\HRMS\Http\Controllers\EmployeeController;
use App\Modules\HRMS\Http\Controllers\LeaveBalanceController;
use App\Modules\HRMS\Http\Controllers\LeaveRequestController;
use App\Modules\HRMS\Http\Controllers\LeaveTypeController;
use App\Modules\Inventory\Http\Controllers\BatchController;
use App\Modules\Inventory\Http\Controllers\ItemController;
use App\Modules\Inventory\Http\Controllers\SerialNumberController;
use App\Modules\Inventory\Http\Controllers\StockBalanceController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use App\Modules\Inventory\Http\Controllers\WarehouseController;
use App\Modules\Maintenance\Http\Controllers\AssetController;
use App\Modules\Maintenance\Http\Controllers\MaintenanceReportController;
use App\Modules\Maintenance\Http\Controllers\MaintenanceScheduleController;
use App\Modules\Maintenance\Http\Controllers\MaintenanceWorkOrderController;
use App\Modules\Payroll\Http\Controllers\PayrollRunController;
use App\Modules\Payroll\Http\Controllers\PayslipController;
use App\Modules\Payroll\Http\Controllers\SalaryComponentController;
use App\Modules\Payroll\Http\Controllers\SalaryStructureController;
use App\Modules\Procurement\Http\Controllers\GoodsReceiptController;
use App\Modules\Procurement\Http\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Http\Controllers\PurchaseRequisitionController;
use App\Modules\Procurement\Http\Controllers\VendorController;
use App\Modules\Production\Http\Controllers\BomController;
use App\Modules\Production\Http\Controllers\CapacityPlanController;
use App\Modules\Production\Http\Controllers\MrpController;
use App\Modules\Production\Http\Controllers\ReworkOrderController;
use App\Modules\Production\Http\Controllers\RoutingController;
use App\Modules\Production\Http\Controllers\ScrapReasonController;
use App\Modules\Production\Http\Controllers\SubcontractOrderController;
use App\Modules\Production\Http\Controllers\WorkCenterController;
use App\Modules\Production\Http\Controllers\WorkOrderController;
use App\Modules\Quality\Http\Controllers\CalibrationRecordController;
use App\Modules\Quality\Http\Controllers\CapaController;
use App\Modules\Quality\Http\Controllers\IncomingInspectionController;
use App\Modules\Quality\Http\Controllers\MeasuringInstrumentController;
use App\Modules\Quality\Http\Controllers\NonConformanceReportController;
use App\Modules\Quality\Http\Controllers\SpcCharacteristicController;
use App\Modules\Quality\Http\Controllers\SpcChartController;
use App\Modules\Quality\Http\Controllers\SpcMeasurementController;
use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\DeliveryController;
use App\Modules\Sales\Http\Controllers\InvoiceController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\TallySync\Http\Controllers\TallySyncAgentController;
use App\Modules\TallySync\Http\Controllers\TallySyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (versioned)
|--------------------------------------------------------------------------
|
| Every route here is a real product surface — it may be called by the
| bundled React SPA (session auth via Sanctum) or by other future clients
| (token auth via Sanctum personal access tokens). Never assume the SPA
| is the only caller: validate and shape responses accordingly.
|
*/

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::apiResource('users', UserController::class)->only(['index', 'store']);

        Route::prefix('inventory')->group(function () {
            Route::apiResource('items', ItemController::class)->only(['index', 'store', 'update']);
            Route::apiResource('warehouses', WarehouseController::class)->only(['index', 'store', 'update']);

            Route::get('stock-balances', [StockBalanceController::class, 'index']);

            Route::get('stock-movements', [StockMovementController::class, 'index']);
            Route::post('stock-movements/receipts', [StockMovementController::class, 'receipt']);
            Route::post('stock-movements/issues', [StockMovementController::class, 'issue']);
            Route::post('stock-movements/transfers', [StockMovementController::class, 'transfer']);

            Route::apiResource('batches', BatchController::class)->only(['index', 'store']);
            Route::get('batches/{batch}/ledger', [BatchController::class, 'ledger']);

            Route::apiResource('serial-numbers', SerialNumberController::class)->only(['index', 'store']);
            Route::get('serial-numbers/{serial_number}/history', [SerialNumberController::class, 'history']);
        });

        Route::prefix('procurement')->group(function () {
            Route::apiResource('vendors', VendorController::class)->only(['index', 'store', 'update']);

            Route::apiResource('purchase-requisitions', PurchaseRequisitionController::class)->only(['index', 'store']);
            Route::post('purchase-requisitions/{purchase_requisition}/approve', [PurchaseRequisitionController::class, 'approve']);
            Route::post('purchase-requisitions/{purchase_requisition}/reject', [PurchaseRequisitionController::class, 'reject']);

            Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'store']);
            Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'send']);

            Route::apiResource('goods-receipts', GoodsReceiptController::class)->only(['index', 'store']);
        });

        Route::prefix('sales')->group(function () {
            Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'update']);

            Route::apiResource('sales-orders', SalesOrderController::class)->only(['index', 'store']);
            Route::post('sales-orders/{sales_order}/confirm', [SalesOrderController::class, 'confirm']);

            Route::apiResource('deliveries', DeliveryController::class)->only(['index', 'store']);

            Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store']);
            Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
        });

        Route::prefix('finance')->group(function () {
            Route::apiResource('gl-accounts', GLAccountController::class)->only(['index', 'store', 'update']);

            Route::apiResource('journal-entries', JournalEntryController::class)->only(['index', 'store']);
            Route::post('journal-entries/{journal_entry}/post', [JournalEntryController::class, 'post']);

            Route::get('reports/trial-balance', [FinancialReportController::class, 'trialBalance']);
            Route::get('reports/profit-and-loss', [FinancialReportController::class, 'profitAndLoss']);
            Route::get('reports/balance-sheet', [FinancialReportController::class, 'balanceSheet']);
            Route::get('reports/receivables', [FinancialReportController::class, 'receivables']);
        });

        Route::prefix('crm')->group(function () {
            Route::apiResource('leads', LeadController::class)->only(['index', 'store', 'update']);
            Route::post('leads/{lead}/convert', [LeadController::class, 'convert']);

            Route::apiResource('opportunities', OpportunityController::class)->only(['index', 'store', 'update']);

            Route::apiResource('quotations', QuotationController::class)->only(['index', 'store']);
            Route::post('quotations/{quotation}/send', [QuotationController::class, 'send']);
            Route::post('quotations/{quotation}/accept', [QuotationController::class, 'accept']);
            Route::post('quotations/{quotation}/reject', [QuotationController::class, 'reject']);
        });

        Route::prefix('quality')->group(function () {
            Route::apiResource('incoming-inspections', IncomingInspectionController::class)->only(['index', 'store']);

            Route::apiResource('ncrs', NonConformanceReportController::class)->only(['index', 'store']);
            Route::post('ncrs/{ncr}/close', [NonConformanceReportController::class, 'close']);

            Route::apiResource('capas', CapaController::class)->only(['index', 'store', 'update']);
            Route::post('capas/{capa}/start', [CapaController::class, 'start']);
            Route::post('capas/{capa}/close', [CapaController::class, 'close']);

            Route::apiResource('instruments', MeasuringInstrumentController::class)->only(['index', 'store']);
            Route::get('instruments/{instrument}/calibrations', [CalibrationRecordController::class, 'index']);
            Route::post('instruments/{instrument}/calibrations', [CalibrationRecordController::class, 'store']);

            Route::apiResource('spc-characteristics', SpcCharacteristicController::class)->only(['index', 'store']);
            Route::get('spc-characteristics/{spc_characteristic}/measurements', [SpcMeasurementController::class, 'index']);
            Route::post('spc-characteristics/{spc_characteristic}/measurements', [SpcMeasurementController::class, 'store']);
            Route::get('spc-characteristics/{spc_characteristic}/chart', [SpcChartController::class, 'show']);
        });

        Route::prefix('compliance')->group(function () {
            Route::apiResource('gst-rates', GstRateController::class)->only(['index', 'store', 'update']);
            Route::apiResource('gst-registrations', GstRegistrationController::class)->only(['index', 'store', 'update']);

            Route::get('invoices/{invoice}/gst-breakdown', [GstComputationController::class, 'invoiceBreakdown']);
            Route::get('reports/gstr1', [GstReportController::class, 'gstr1']);
        });

        Route::prefix('tally-sync')->group(function () {
            Route::get('entries', [TallySyncController::class, 'index']);
            Route::post('entries/{tally_sync_entry}/retry', [TallySyncController::class, 'retry']);

            // Local agent endpoints — see TECHNICAL-DOCS.md §6. Gated by
            // Sanctum token abilities inside the controller, not just
            // auth:sanctum, since a real deployment issues the agent a
            // token scoped to exactly these two abilities.
            Route::get('pending', [TallySyncAgentController::class, 'pending']);
            Route::post('entries/{tally_sync_entry}/ack', [TallySyncAgentController::class, 'acknowledge']);
            Route::post('entries/{tally_sync_entry}/fail', [TallySyncAgentController::class, 'fail']);
        });

        Route::prefix('hrms')->group(function () {
            Route::apiResource('employees', EmployeeController::class)->only(['index', 'store', 'update']);

            Route::apiResource('leave-types', LeaveTypeController::class)->only(['index', 'store', 'update']);

            Route::get('leave-balances', [LeaveBalanceController::class, 'index']);
            Route::post('leave-balances', [LeaveBalanceController::class, 'store']);

            Route::apiResource('leave-requests', LeaveRequestController::class)->only(['index', 'store']);
            Route::post('leave-requests/{leave_request}/approve', [LeaveRequestController::class, 'approve']);
            Route::post('leave-requests/{leave_request}/reject', [LeaveRequestController::class, 'reject']);

            Route::get('attendance', [AttendanceController::class, 'index']);
            Route::post('attendance/mark', [AttendanceController::class, 'mark']);
        });

        Route::prefix('payroll')->group(function () {
            Route::apiResource('salary-components', SalaryComponentController::class)->only(['index', 'store']);

            Route::apiResource('salary-structures', SalaryStructureController::class)->only(['index', 'store']);

            Route::apiResource('runs', PayrollRunController::class)->only(['index', 'store']);
            Route::post('runs/{payroll_run}/process', [PayrollRunController::class, 'process']);
            Route::post('runs/{payroll_run}/mark-paid', [PayrollRunController::class, 'markPaid']);

            Route::apiResource('payslips', PayslipController::class)->only(['index', 'show']);
        });

        Route::prefix('production')->group(function () {
            Route::apiResource('work-centers', WorkCenterController::class)->only(['index', 'store', 'update']);

            Route::apiResource('boms', BomController::class)->only(['index', 'store']);

            Route::apiResource('routings', RoutingController::class)->only(['index', 'store']);

            Route::apiResource('work-orders', WorkOrderController::class)->only(['index', 'store']);
            Route::post('work-orders/{work_order}/release', [WorkOrderController::class, 'release']);
            Route::post('work-orders/{work_order}/complete', [WorkOrderController::class, 'complete']);

            Route::get('mrp/net-requirements', [MrpController::class, 'netRequirements']);

            Route::get('capacity/load-report', [CapacityPlanController::class, 'loadReport']);

            Route::apiResource('subcontract-orders', SubcontractOrderController::class)->only(['index', 'store']);
            Route::post('subcontract-orders/{subcontract_order}/send-materials', [SubcontractOrderController::class, 'sendMaterials']);
            Route::post('subcontract-orders/{subcontract_order}/receive', [SubcontractOrderController::class, 'receive']);

            Route::apiResource('scrap-reasons', ScrapReasonController::class)->only(['index', 'store']);

            Route::apiResource('rework-orders', ReworkOrderController::class)->only(['index', 'store']);
            Route::post('rework-orders/{rework_order}/release', [ReworkOrderController::class, 'release']);
            Route::post('rework-orders/{rework_order}/complete', [ReworkOrderController::class, 'complete']);
        });

        Route::prefix('maintenance')->group(function () {
            Route::apiResource('assets', AssetController::class)->only(['index', 'store', 'update']);

            Route::apiResource('schedules', MaintenanceScheduleController::class)->only(['index', 'store']);
            Route::post('schedules/generate-due', [MaintenanceScheduleController::class, 'generateDue']);

            Route::apiResource('work-orders', MaintenanceWorkOrderController::class)->only(['index', 'store']);
            Route::post('work-orders/{maintenance_work_order}/parts', [MaintenanceWorkOrderController::class, 'addPart']);
            Route::post('work-orders/{maintenance_work_order}/start', [MaintenanceWorkOrderController::class, 'start']);
            Route::post('work-orders/{maintenance_work_order}/complete', [MaintenanceWorkOrderController::class, 'complete']);
            Route::post('work-orders/{maintenance_work_order}/cancel', [MaintenanceWorkOrderController::class, 'cancel']);

            Route::get('reports/reliability', [MaintenanceReportController::class, 'reliability']);
        });
    });
});
