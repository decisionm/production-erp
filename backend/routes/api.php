<?php

use App\Modules\Compliance\Http\Controllers\GstComputationController;
use App\Modules\Compliance\Http\Controllers\GstRateController;
use App\Modules\Compliance\Http\Controllers\GstRegistrationController;
use App\Modules\Compliance\Http\Controllers\GstReportController;
use App\Modules\Core\Http\Controllers\AuthController;
use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\ExportController;
use App\Modules\Core\Http\Controllers\PermissionController;
use App\Modules\Core\Http\Controllers\RoleController;
use App\Modules\Core\Http\Controllers\UserController;
use App\Modules\CRM\Http\Controllers\LeadActivityController;
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
use App\Modules\Inventory\Http\Controllers\MaterialBagController;
use App\Modules\Inventory\Http\Controllers\MaterialLotController;
use App\Modules\Inventory\Http\Controllers\MaterialLotCostVersionController;
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
use App\Modules\Production\Http\Controllers\BatchPreviewController;
use App\Modules\Production\Http\Controllers\BinBayController;
use App\Modules\Production\Http\Controllers\BomController;
use App\Modules\Production\Http\Controllers\CapacityPlanController;
use App\Modules\Production\Http\Controllers\DayBinController;
use App\Modules\Production\Http\Controllers\DowntimeReasonController;
use App\Modules\Production\Http\Controllers\FactoryDayBinController;
use App\Modules\Production\Http\Controllers\FactorySettingController;
use App\Modules\Production\Http\Controllers\FinishedCartonController;
use App\Modules\Production\Http\Controllers\MachineDowntimeLogController;
use App\Modules\Production\Http\Controllers\MasterbatchDosingController;
use App\Modules\Production\Http\Controllers\MoldChangeLogController;
use App\Modules\Production\Http\Controllers\MoldController;
use App\Modules\Production\Http\Controllers\MrpController;
use App\Modules\Production\Http\Controllers\PackingMaterialMappingController;
use App\Modules\Production\Http\Controllers\PowerInterruptionLogController;
use App\Modules\Production\Http\Controllers\ProductionConfigurationController;
use App\Modules\Production\Http\Controllers\ProductionReportController;
use App\Modules\Production\Http\Controllers\ProductionSettingsController;
use App\Modules\Production\Http\Controllers\ProductionStandardController;
use App\Modules\Production\Http\Controllers\ProductionStandardPackagingController;
use App\Modules\Production\Http\Controllers\ReworkOrderController;
use App\Modules\Production\Http\Controllers\RoutingController;
use App\Modules\Production\Http\Controllers\ScrapReasonController;
use App\Modules\Production\Http\Controllers\ShiftController;
use App\Modules\Production\Http\Controllers\ShiftProductionEntryController;
use App\Modules\Production\Http\Controllers\ShiftStockCountController;
use App\Modules\Production\Http\Controllers\ShiftSummaryController;
use App\Modules\Production\Http\Controllers\SubcontractOrderController;
use App\Modules\Production\Http\Controllers\VoucherPreviewController;
use App\Modules\Production\Http\Controllers\WorkCenterController;
use App\Modules\Production\Http\Controllers\WorkOrderController;
use App\Modules\Quality\Http\Controllers\BatchQualityCheckController;
use App\Modules\Quality\Http\Controllers\BatchReturnToProductionController;
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
use App\Modules\Sales\Http\Controllers\SalesCostInsightController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\Sales\Http\Controllers\SalesTallyMirrorController;
use App\Modules\TallySync\Http\Controllers\TallySettingsController;
use App\Modules\TallySync\Http\Controllers\TallyStockSnapshotController;
use App\Modules\TallySync\Http\Controllers\TallySyncAgentController;
use App\Modules\TallySync\Http\Controllers\TallySyncAgentTokenController;
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
| Every module route group below is wrapped in `module:<key>` (see
| App\Http\Middleware\EnsureModulePermission) — GET requests need either
| "<key>.view" or "<key>.manage", everything else needs "<key>.manage".
| Adding a route to an existing group inherits that group's check
| automatically; adding a brand new module means adding it to
| PermissionService::MODULES first (that's what PermissionSeeder and the
| Roles UI's checkbox picker both read from).
|
*/

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

        Route::get('dashboard/summary', [DashboardController::class, 'summary']);

        // The Download / Export Center (Phase 4.5). No module: middleware —
        // any authenticated user may ask; each KIND carries its own
        // permissionAny(), judged by the catalogue and by ExportRequest.
        // `runs` is registered ahead of `{kind}` so the literal segment can
        // never be read as a kind.
        Route::get('exports', [ExportController::class, 'catalogue']);
        Route::get('exports/runs', [ExportController::class, 'runs']);
        Route::post('exports/{kind}', [ExportController::class, 'run']);

        Route::middleware('module:users')->group(function () {
            Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
        });

        Route::middleware('module:roles')->group(function () {
            Route::apiResource('roles', RoleController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::get('permissions', [PermissionController::class, 'index']);
        });

        Route::prefix('inventory')->middleware('module:inventory')->group(function () {
            // `show` exists so an item's own page can load that one item by id
            // instead of hunting for it in the first page of the index list.
            Route::apiResource('items', ItemController::class)->only(['index', 'store', 'update', 'show']);
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

            // Phase 6 traceability (store side): supplier lots + bags with
            // unique barcodes, and the FIFO pick list. The whole surface
            // 404s until production.traceability_enabled is on — with the
            // flag off the feature does not exist.
            Route::middleware('traceability')->group(function () {
                Route::get('material-lots', [MaterialLotController::class, 'index']);
                Route::post('material-lots', [MaterialLotController::class, 'store']);
                Route::get('material-lots/{material_lot}', [MaterialLotController::class, 'show']);

                Route::get('material-bags', [MaterialBagController::class, 'index']);
                Route::get('material-bags/pick-list', [MaterialBagController::class, 'pickList']);
            });
        });

        // A material lot's COST history lives at the lot's own URL but is
        // gated on finance, not inventory — purchase rates are Owner and
        // Accounts territory, not the store's. Hence a sibling group rather
        // than a nested one: route-group middleware accumulates, so putting
        // these inside the inventory group above would demand BOTH
        // permissions and lock Accounts out of their own data.
        // Append-only: there is no PUT and no DELETE here or anywhere.
        Route::prefix('inventory')->middleware('module:finance')->group(function () {
            Route::get('material-lots/{lot}/cost-versions', [MaterialLotCostVersionController::class, 'index']);
            Route::post('material-lots/{lot}/cost-versions', [MaterialLotCostVersionController::class, 'store']);
        });

        Route::prefix('procurement')->middleware('module:procurement')->group(function () {
            Route::apiResource('vendors', VendorController::class)->only(['index', 'store', 'update']);

            Route::apiResource('purchase-requisitions', PurchaseRequisitionController::class)->only(['index', 'store']);
            Route::post('purchase-requisitions/{purchase_requisition}/approve', [PurchaseRequisitionController::class, 'approve']);
            Route::post('purchase-requisitions/{purchase_requisition}/reject', [PurchaseRequisitionController::class, 'reject']);

            Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'store']);
            Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'send']);

            Route::apiResource('goods-receipts', GoodsReceiptController::class)->only(['index', 'store']);
        });

        Route::prefix('sales')->middleware('module:sales')->group(function () {
            Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'update']);

            // Phase 3.5 — the honesty statement the Sales pages render: Tally-
            // side Sales / Sales Order vouchers are NOT mirrored here
            // (DEC-20260809-003). Read-only; a bare object, not a row.
            Route::get('tally-mirror', [SalesTallyMirrorController::class, 'show']);

            // index takes the ListSalesOrdersRequest filters; show carries the
            // document's trace (deliveries with cartons, invoices, Tally links).
            Route::apiResource('sales-orders', SalesOrderController::class)->only(['index', 'store', 'show']);
            Route::post('sales-orders/{sales_order}/confirm', [SalesOrderController::class, 'confirm']);
            // Cancel: draft/confirmed, nothing delivered, nothing invoiced —
            // else 422. Touches no stock, queues nothing for Tally.
            Route::post('sales-orders/{sales_order}/cancel', [SalesOrderController::class, 'cancel']);
            // Honest cost visibility: an estimate for every line, an actual
            // only where an approved batch really stands behind it. Read-only.
            Route::get('sales-orders/{sales_order}/cost-insight', [SalesCostInsightController::class, 'show']);

            Route::apiResource('deliveries', DeliveryController::class)->only(['index', 'store', 'show']);

            Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'show']);
            Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
        });

        Route::prefix('finance')->middleware('module:finance')->group(function () {
            Route::apiResource('gl-accounts', GLAccountController::class)->only(['index', 'store', 'update']);

            Route::apiResource('journal-entries', JournalEntryController::class)->only(['index', 'store']);
            Route::post('journal-entries/{journal_entry}/post', [JournalEntryController::class, 'post']);

            Route::get('reports/trial-balance', [FinancialReportController::class, 'trialBalance']);
            Route::get('reports/profit-and-loss', [FinancialReportController::class, 'profitAndLoss']);
            Route::get('reports/balance-sheet', [FinancialReportController::class, 'balanceSheet']);
            Route::get('reports/receivables', [FinancialReportController::class, 'receivables']);
        });

        Route::prefix('crm')->middleware('module:crm')->group(function () {
            Route::apiResource('leads', LeadController::class)->only(['index', 'store', 'update']);
            Route::post('leads/{lead}/convert', [LeadController::class, 'convert']);
            Route::get('leads/{lead}/activities', [LeadActivityController::class, 'index']);
            Route::post('leads/{lead}/activities', [LeadActivityController::class, 'store']);

            Route::apiResource('opportunities', OpportunityController::class)->only(['index', 'store', 'update']);

            Route::apiResource('quotations', QuotationController::class)->only(['index', 'store']);
            Route::post('quotations/{quotation}/send', [QuotationController::class, 'send']);
            Route::post('quotations/{quotation}/accept', [QuotationController::class, 'accept']);
            Route::post('quotations/{quotation}/reject', [QuotationController::class, 'reject']);
            Route::get('quotations/{quotation}/pdf', [QuotationController::class, 'pdf']);
        });

        Route::prefix('quality')->middleware('module:quality')->group(function () {
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

        Route::prefix('compliance')->middleware('module:compliance')->group(function () {
            Route::apiResource('gst-rates', GstRateController::class)->only(['index', 'store', 'update']);
            Route::apiResource('gst-registrations', GstRegistrationController::class)->only(['index', 'store', 'update']);

            Route::get('invoices/{invoice}/gst-breakdown', [GstComputationController::class, 'invoiceBreakdown']);
            Route::get('reports/gstr1', [GstReportController::class, 'gstr1']);
        });

        Route::prefix('tally-sync')->group(function () {
            Route::middleware('module:tally-sync')->group(function () {
                Route::get('entries', [TallySyncController::class, 'index']);
                // The Control Center's header counts. Registered ahead of the
                // parameterised entry routes so no literal segment can ever be
                // read as an entry id.
                Route::get('summary', [TallySyncController::class, 'summary']);
                // One voucher with its full history (TALLY-SYNC-CHAIN.md §3).
                Route::get('entries/{tally_sync_entry}', [TallySyncController::class, 'show']);
                Route::post('entries/{tally_sync_entry}/retry', [TallySyncController::class, 'retry']);
                Route::post('entries/{tally_sync_entry}/dismiss', [TallySyncController::class, 'dismiss']);
                Route::post('entries/{tally_sync_entry}/release', [TallySyncController::class, 'release']);

                Route::get('agent-tokens', [TallySyncAgentTokenController::class, 'index']);
                Route::post('agent-tokens', [TallySyncAgentTokenController::class, 'store']);
                Route::delete('agent-tokens/{tokenId}', [TallySyncAgentTokenController::class, 'destroy']);

                // Tally configuration (company selection + ledger-role mappings).
                // Tally's own closing stock, read and then — separately, and
                // only ever by an explicit call — turned into opening balances.
                Route::get('stock-snapshots', [TallyStockSnapshotController::class, 'index']);
                Route::get('stock-snapshots/{tally_stock_snapshot}', [TallyStockSnapshotController::class, 'show']);
                Route::post('stock-snapshots/{tally_stock_snapshot}/apply', [TallyStockSnapshotController::class, 'apply']);

                Route::get('settings', [TallySettingsController::class, 'show']);
                Route::put('settings/company', [TallySettingsController::class, 'updateCompany']);
                Route::put('settings/ledger-mappings', [TallySettingsController::class, 'updateLedgerMappings']);
            });

            // Local agent endpoints — see TECHNICAL-DOCS.md §6. Gated inside
            // the controller on a REAL agent token (AgentIdentity::isAgent —
            // a personal access token carrying the poll/report abilities),
            // not by module:tally-sync and not by tokenCan() alone: a
            // browser session's TransientToken answers tokenCan() true, and
            // until Phase 4 that let any logged-in staff member collect, ack
            // or fail a voucher from the browser regardless of role. Only the
            // agent may say what Tally took; people act through the
            // module:tally-sync routes above (retry, dismiss, release).
            Route::get('pending', [TallySyncAgentController::class, 'pending']);
            Route::post('entries/{tally_sync_entry}/ack', [TallySyncAgentController::class, 'acknowledge']);
            Route::post('entries/{tally_sync_entry}/fail', [TallySyncAgentController::class, 'fail']);
            // The post-Tally snapshot — what the agent sent, what Tally
            // answered — uploaded after each post, fire-and-forget (Phase 4).
            // Same ability as ack/fail; touches nothing on the entry.
            Route::post('entries/{tally_sync_entry}/snapshot', [TallySyncAgentController::class, 'snapshot']);

            // Inbound masters pull (agent → cloud): `masters` takes the full
            // pull (item groups, godowns, ledgers, items) — the only inbound
            // path the shipped agent has ever called. Gated by token abilities
            // in the controller.
            Route::post('masters', [TallySyncAgentController::class, 'masters']);
            Route::post('companies', [TallySyncAgentController::class, 'companies']);

            // READ-ONLY godown-wise stock summary, reported and discarded. The
            // route says `preview` because there is no sibling that writes: an
            // opening-stock import is a separate, explicitly-approved act, not
            // something a sync loop can reach.
            Route::post('stock-summary/preview', [TallySyncAgentController::class, 'stockSummaryPreview']);
        });

        Route::prefix('hrms')->middleware('module:hrms')->group(function () {
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

        Route::prefix('payroll')->middleware('module:payroll')->group(function () {
            Route::apiResource('salary-components', SalaryComponentController::class)->only(['index', 'store']);

            Route::apiResource('salary-structures', SalaryStructureController::class)->only(['index', 'store']);

            Route::apiResource('runs', PayrollRunController::class)->only(['index', 'store']);
            Route::post('runs/{payroll_run}/process', [PayrollRunController::class, 'process']);
            Route::post('runs/{payroll_run}/mark-paid', [PayrollRunController::class, 'markPaid']);

            Route::apiResource('payslips', PayslipController::class)->only(['index', 'show']);
        });

        /*
         * THE QUALITY GATE on a production batch — deliberately OUTSIDE the
         * production group below, though it sits at a production path.
         *
         * The path belongs with the batch's other lifecycle actions
         * (complete, pm-approve, accountant-approve) because that is what it
         * is: the stage between the first two. The PERMISSION belongs to
         * quality, because the quality desk is who performs it, and a QC
         * checker has no reason to hold production.manage — which is what
         * this route would silently require if it were nested in the group.
         * Registering it here is how it carries module:quality (POST ⇒
         * quality.manage) and only that.
         */
        Route::post(
            'production/shift-production-entries/{shift_production_entry}/quality-check',
            BatchQualityCheckController::class,
        )->middleware('module:quality');

        /*
         * THE OTHER HALF OF THAT GATE: quality sending a batch back to the
         * floor instead of certifying it. Registered here, beside the check
         * and outside the production group, for exactly the reasons above —
         * the quality desk performs it, so it carries module:quality and
         * only that. Production's own correction of the same batch lives at
         * .../amend inside the production group, and a user holding one
         * module's permission cannot reach the other's route.
         */
        Route::post(
            'production/shift-production-entries/{shift_production_entry}/return-to-production',
            BatchReturnToProductionController::class,
        )->middleware('module:quality');

        Route::prefix('production')->middleware('module:production')->group(function () {
            Route::get('settings', [ProductionSettingsController::class, 'show']);
            // Which warehouse IS the factory day bin (app_settings). A PUT
            // needs production.manage, the GET above needs only .view — the
            // module guard settles that, same as Tally's settings pair.
            Route::put('settings/day-bin-warehouse', [ProductionSettingsController::class, 'updateDayBinWarehouse']);
            // The finished-goods destination and raw-material source, for the
            // same reason and by the same mechanism. The floor is never asked
            // either question (owner, 30-Jul: "there is no need to select any
            // store in any place"), so this is where a factory whose books
            // carry more than one godown states the answer once — and it is
            // the fix the resolver's 422 names.
            Route::put('settings/factory-warehouses', [ProductionSettingsController::class, 'updateFactoryWarehouses']);
            // The central factory day bin: the warehouse and its current
            // per-material balances, readable without picking a machine.
            // NOT inside the traceability group below on purpose — the plain
            // central path exists whether or not the bag/barcode detail does.
            Route::get('factory-day-bin', [FactoryDayBinController::class, 'show']);
            // The Day Bin page's raw-material picker: active kg-uom items
            // with their current store kg. Plain masters+balances read —
            // like the bin read above, never traceability-gated.
            Route::get('factory-day-bin/raw-materials', [FactoryDayBinController::class, 'rawMaterials']);
            // ESTIMATED RESIN REMAINING IN THE COMMON INPUT, factory-wide:
            // every load of a material minus its calculated consumption
            // across ALL machines, one row per material. There is NO
            // per-machine answer — the owner's correction (2-Aug) removed
            // the machine dimension, and ?work_center_id= is accepted and
            // deliberately ignored, kept only so an old floor tablet's read
            // keeps working (see MachineResinQueryRequest — delete once the
            // floor has reloaded). The PATH keeps its legacy per-machine
            // name for the same tablets; the ANSWER is factory-wide.
            //
            // It REPLACED factory-day-bin/reconciliation, which compared a
            // derived expected closing against a physical bin weight — the
            // owner has since ruled that this factory takes no such weight
            // (31-Jul), so the read asked a question nobody answers. Plain
            // ledger+consumption read, so outside the traceability gate like
            // its two neighbours above.
            Route::get('machine-resin', [FactoryDayBinController::class, 'commonResinEstimate']);

            // FINISHED CARTON IDENTITIES: generated once per completed batch
            // (idempotent), reprinted as a plain read, and looked up by scan
            // for dispatch and traceability. carton_no carries dashes and
            // digits only — no constraint needed beyond the route segment.
            Route::post('shift-production-entries/{shift_production_entry}/cartons', [FinishedCartonController::class, 'generate']);
            Route::get('shift-production-entries/{shift_production_entry}/cartons', [FinishedCartonController::class, 'index']);
            Route::get('cartons/{cartonNo}', [FinishedCartonController::class, 'lookup']);
            // READING the machine list stays here, under module:production.
            // It is not an admin screen's private data — the Start Batch
            // picker, the configuration forms, the downtime log and the day
            // bin all resolve a machine through it, so a supervisor who
            // cannot read it cannot start a shift. WRITING a machine lives in
            // its own module:machine-master group below.
            Route::apiResource('work-centers', WorkCenterController::class)->only(['index']);

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

            Route::apiResource('shifts', ShiftController::class)->only(['index', 'store']);

            // "store" starts a batch (machine + item, quantities unknown
            // yet); "complete" fills in the finished numbers once the batch
            // is done running — see PRODUCTION-SUPERVISOR-UX-PLAN.md §1.
            // ---- Production configuration (the master data an authorized
            // user maintains without a deploy). Grouped under the existing
            // production module permission.
            Route::get('configurations', [ProductionConfigurationController::class, 'index']);
            Route::post('configurations', [ProductionConfigurationController::class, 'store']);
            Route::put('configurations/{production_configuration}', [ProductionConfigurationController::class, 'update']);
            Route::post('configurations/{production_configuration}/approve', [ProductionConfigurationController::class, 'approve']);
            Route::post('configurations/{production_configuration}/deactivate', [ProductionConfigurationController::class, 'deactivate']);
            Route::post('configurations/{production_configuration}/copy', [ProductionConfigurationController::class, 'copy']);
            Route::get('work-centers/{work_center}/configurations', [ProductionConfigurationController::class, 'forMachine']);
            Route::post('configurations/import', [ProductionConfigurationController::class, 'importRows']);

            // Factory product-level standards (ERPPRO master import).
            Route::get('standards', [ProductionStandardController::class, 'index']);
            // The slim projection of the same read: which products the
            // standards cover. Declared before nothing wildcard-shaped, so
            // ordering is irrelevant — it is a sibling, not an override.
            Route::get('standards/coverage', [ProductionStandardController::class, 'coverage']);
            Route::post('standards/import', [ProductionStandardController::class, 'import']);
            // Finishing the import's job one row at a time, from the page:
            // which Tally item does this factory product name mean, and add a
            // product the workbook never carried. Both are inside the
            // module:production group, so the GET needs .view and the POSTs
            // need .manage without either route saying so.
            //
            // Declared after standards/coverage and standards/import so the
            // literal segments are never shadowed by the {standard} binding.
            Route::get('standards/{standard}/item-candidates', [ProductionStandardController::class, 'itemCandidates']);
            // The workspace's expanded row: this product's machine exceptions.
            // A read over the same configurations the exceptions endpoints
            // above serve — every write still goes through them.
            Route::get('standards/{standard}/machine-exceptions', [ProductionStandardController::class, 'machineExceptions']);
            Route::post('standards/{standard}/attach-item', [ProductionStandardController::class, 'attachItem']);
            Route::post('standards', [ProductionStandardController::class, 'store']);
            // Packaging variants of one standard, Tally identity included
            // (DEC-20260810-003): add the packing the workbook never carried
            // (the 490/box tray), set or correct each variant's own identity.
            // Same group, so the POST and PUT need production.manage.
            Route::post('standards/{standard}/packagings', [ProductionStandardPackagingController::class, 'store']);
            Route::put('standards/{standard}/packagings/{packaging}', [ProductionStandardPackagingController::class, 'update']);

            Route::get('downtime-reasons', [DowntimeReasonController::class, 'index']);
            Route::post('downtime-reasons', [DowntimeReasonController::class, 'store']);
            Route::put('downtime-reasons/{downtime_reason}', [DowntimeReasonController::class, 'update']);

            // Typed settings a factory changes without a deploy. One of them
            // is load-bearing for the completion screen and easy to miss, so
            // it is named here: `masterbatch_colour_map` (data_type json) is
            // the colour → masterbatch item id map the pre-selected
            // masterbatch is resolved from —
            // {"Amber": 121, "Milk White": 120}. Unseeded on purpose;
            // RunMaterialSuggestionService says why a name scan is not
            // allowed to stand in for it.
            Route::get('factory-settings', [FactorySettingController::class, 'index']);
            Route::post('factory-settings', [FactorySettingController::class, 'upsert']);

            // Masterbatch dosing — grams per bottle, master data. The factory
            // gave amber (0.25 g/bottle) on 31-Jul; white and red/brown are
            // still unset, and unset means the floor prefills nothing. The
            // POST exists so the next figure is entered by a person in the
            // app instead of shipped in a deploy. The GET answers "what
            // applies to this product, and what kg is that for these
            // bottles" — the group's module:production guard gives it .view
            // and holds the writes to .manage, same as factory-settings.
            Route::get('masterbatch-dosings', [MasterbatchDosingController::class, 'index']);
            Route::post('masterbatch-dosings', [MasterbatchDosingController::class, 'store']);
            Route::delete('masterbatch-dosings/{masterbatch_dosing}', [MasterbatchDosingController::class, 'destroy']);

            // Packing materials — which Tally item each workbook spec string
            // means ("170ML" -> "170 Ml Master Box"), plus the per-piece film
            // weight and the metres of tape a box seals with. The deploy-time
            // seed resolves what this instance's own catalogue can prove and
            // leaves the rest empty on purpose; the POST is how the factory
            // answers the rest — which cartons take Green tape, which of two
            // identically-named trays a shift consumes. Same guard as
            // masterbatch-dosings: .view reads, .manage writes.
            // The dropdown lists — every item that could be a carton, tray,
            // film or tape. Read before the mappings themselves, because a
            // screen needs the choices whether a mapping exists or not.
            Route::get('packing-material-options', [PackingMaterialMappingController::class, 'options']);
            Route::get('packing-material-mappings', [PackingMaterialMappingController::class, 'index']);
            Route::post('packing-material-mappings', [PackingMaterialMappingController::class, 'store']);
            Route::delete('packing-material-mappings/{packing_material_mapping}', [PackingMaterialMappingController::class, 'destroy']);

            Route::get('shift-production-entries/active', [ShiftProductionEntryController::class, 'active']);
            // Readiness + estimation for an intended run, before it starts.
            // GET and side-effect free: the SPA calls it on every product or
            // machine change while the supervisor fills the form.
            Route::get('shift-production-entries/preview', BatchPreviewController::class);
            // A WHOLE PAGE of the factory's production report, entered at once
            // — ten to twelve machine rows instead of two dialogs each. Declared
            // before the apiResource so the literal segment is matched ahead of
            // anything the resource might route.
            Route::post('shift-production-entries/page', [ShiftProductionEntryController::class, 'ingestPage']);
            Route::apiResource('shift-production-entries', ShiftProductionEntryController::class)->only(['index', 'store']);
            Route::post('shift-production-entries/{shift_production_entry}/complete', [ShiftProductionEntryController::class, 'complete']);
            // Correcting a completed batch that quality has not touched yet —
            // the floor's own fix, refused the moment the batch stops being
            // theirs (a quality check, any approval, a Tally voucher, a shift
            // that handed over from it). After a check it is quality who hands
            // it back, at .../return-to-production above.
            Route::post('shift-production-entries/{shift_production_entry}/amend', [ShiftProductionEntryController::class, 'amend']);
            // The approval chain (Supervisor submits at completeBatch):
            // PM verifies → Accountant reconciles → Tally. The accountant is
            // FINAL and is the posting gate; there is no MD stage.
            Route::post('shift-production-entries/{shift_production_entry}/pm-approve', [ShiftProductionEntryController::class, 'pmApprove']);
            Route::post('shift-production-entries/{shift_production_entry}/accountant-approve', [ShiftProductionEntryController::class, 'accountantApprove']);
            // What Tally will receive, resolved against real masters —
            // inspected before the approval that posts it.
            Route::get('shift-production-entries/{shift_production_entry}/voucher-preview', VoucherPreviewController::class);
            Route::post('shift-production-entries/{shift_production_entry}/reject', [ShiftProductionEntryController::class, 'reject']);
            // Withdraw a batch started by mistake, so its machine is not held
            // by a demo run. Refuses any batch that has produced anything.
            Route::post('shift-production-entries/{shift_production_entry}/cancel', [ShiftProductionEntryController::class, 'cancel']);

            Route::post('shift-summaries', [ShiftSummaryController::class, 'store']);
            Route::get('shift-summaries/report', [ShiftSummaryController::class, 'report']);

            // Reports wave — read-only aggregation over completed entries;
            // production.view suffices (GET). The traceability report lives
            // in the flag-gated group further down.
            Route::get('reports/production', [ProductionReportController::class, 'production']);
            Route::get('reports/reconciliation', [ProductionReportController::class, 'reconciliation']);

            // Phase 2b — Idle Time Report. Downtime/mold-change are
            // "stopwatch" logs (open then close); power interruption and
            // stock counts are single-shot, logged after the fact.
            Route::get('machine-downtime-logs', [MachineDowntimeLogController::class, 'index']);
            Route::post('machine-downtime-logs', [MachineDowntimeLogController::class, 'open']);
            Route::post('machine-downtime-logs/{machine_downtime_log}/close', [MachineDowntimeLogController::class, 'close']);

            Route::apiResource('molds', MoldController::class)->only(['index', 'store', 'update']);

            Route::get('mold-change-logs', [MoldChangeLogController::class, 'index']);
            Route::post('mold-change-logs', [MoldChangeLogController::class, 'open']);
            Route::post('mold-change-logs/{mold_change_log}/close', [MoldChangeLogController::class, 'close']);

            Route::apiResource('power-interruption-logs', PowerInterruptionLogController::class)->only(['index', 'store']);
            Route::apiResource('shift-stock-counts', ShiftStockCountController::class)->only(['index', 'store']);

            Route::apiResource('rework-orders', ReworkOrderController::class)->only(['index', 'store']);
            Route::post('rework-orders/{rework_order}/release', [ReworkOrderController::class, 'release']);
            Route::post('rework-orders/{rework_order}/complete', [ReworkOrderController::class, 'complete']);

            // Phase 6 traceability (machine side): the day-bin ledger and
            // shift handover. 404 until production.traceability_enabled —
            // same invisibility rule as the inventory half above.
            Route::middleware('traceability')->group(function () {
                Route::get('day-bin/movements', [DayBinController::class, 'movements']);
                Route::get('day-bin/consumption', [DayBinController::class, 'consumption']);
                Route::post('day-bin/load', [DayBinController::class, 'load']);
                Route::post('day-bin/return', [DayBinController::class, 'returnMaterial']);
                Route::post('day-bin/count', [DayBinController::class, 'count']);

                // The CENTRALIZED bag scan into the FACTORY day bin (the
                // warehouse, all machines at once — no work center picked).
                // Moves the bag's kg via the existing store → bin stock
                // transfer; inside this gate because a barcode only resolves
                // to a MaterialBag with traceability on.
                Route::post('day-bin/load-bag', [FactoryDayBinController::class, 'loadBag']);

                // Aggregate reads the SPA consumes: live machine state and
                // the per-segment consumption summary.
                Route::get('work-centers/{work_center}/day-bin', [DayBinController::class, 'workCenterState']);
                Route::get('shift-production-entries/{shift_production_entry}/day-bin', [DayBinController::class, 'entryState']);

                Route::post('shift-production-entries/{shift_production_entry}/handover', [ShiftProductionEntryController::class, 'handover']);

                // The Start Batch dialog's requirement read: what the
                // machine-scoped ledger holds of a material (with source-lot
                // layers) and the run's recipe priced against it. READ ONLY:
                // the Bin Bay page and its machine-stamped bin-bay/load and
                // bin-bay/history went with DEC-20260807-006 — the floor's
                // one load flow is the common input's day-bin/load-bag scan.
                Route::get('bin-bay/availability', [BinBayController::class, 'availability']);

                // Lot → bag → machine/segment report — only meaningful (and
                // only visible) with traceability on.
                Route::get('reports/traceability', [ProductionReportController::class, 'traceability']);
            });
        });

        /*
         * THE MACHINE MASTER — adding a machine and changing what one IS
         * (its code, its name, its cavity and cycle-time capabilities,
         * whether it is still in service).
         *
         * Same URL prefix as the production group above and the same
         * controller; a DIFFERENT permission. `module:machine-master` makes
         * the POST/PUT require machine-master.manage, while the GET beside it
         * still needs only production.view — which is precisely the split the
         * factory asked for: every supervisor reads the machine list, the
         * office changes it. Separated as a route group rather than an
         * authorize() check inside the FormRequest so the rule is visible
         * where the URL is declared and cannot be forgotten by the next
         * write endpoint added here.
         *
         * The resource parameter is still {work_center}, so
         * UpdateWorkCenterRequest's route('work_center') lookup and its
         * unique-code-ignoring rule are untouched by the move.
         */
        Route::prefix('production')->middleware('module:machine-master')->group(function () {
            Route::apiResource('work-centers', WorkCenterController::class)->only(['store', 'update']);
        });

        /*
         * THE INTERNAL CARTON TRACE TIER (DEC-20260810-001). The same scan,
         * a deeper answer: completion datetime, shift, day-bin lot
         * attribution (GRN reference, inward date, rate) and the batch's
         * costing rate — Owner, Plant Manager and Accounts logins only,
         * never Supervisor, never the public lookup above.
         *
         * A SIBLING of the production group, same URL prefix, different
         * permission — the machine-master pattern exactly, and for the same
         * reason group middleware accumulates: nested inside
         * module:production this route would demand production.view AND
         * carton-trace.view, quietly coupling the internal tier to a module
         * grant the decision never mentions. carton-trace.view alone is the
         * gate, and PermissionSeeder hands it to exactly the named roles.
         */
        Route::prefix('production')->middleware('module:carton-trace')->group(function () {
            Route::get('cartons/{cartonNo}/trace', [FinishedCartonController::class, 'trace']);
        });

        Route::prefix('maintenance')->middleware('module:maintenance')->group(function () {
            Route::apiResource('assets', AssetController::class)->only(['index', 'store', 'update']);

            Route::apiResource('schedules', MaintenanceScheduleController::class)->only(['index', 'store']);
            Route::post('schedules/generate-due', [MaintenanceScheduleController::class, 'generateDue']);

            // Distinct route-name prefix: Production also exposes a `work-orders`
            // resource (see WorkOrderController above), so without an explicit name
            // both generate `work-orders.index`/`.store` and route:cache fails to
            // serialize with a duplicate-name error.
            Route::apiResource('work-orders', MaintenanceWorkOrderController::class)
                ->only(['index', 'store'])
                ->names('maintenance.work-orders');
            Route::post('work-orders/{maintenance_work_order}/parts', [MaintenanceWorkOrderController::class, 'addPart']);
            Route::post('work-orders/{maintenance_work_order}/start', [MaintenanceWorkOrderController::class, 'start']);
            Route::post('work-orders/{maintenance_work_order}/complete', [MaintenanceWorkOrderController::class, 'complete']);
            Route::post('work-orders/{maintenance_work_order}/cancel', [MaintenanceWorkOrderController::class, 'cancel']);

            Route::get('reports/reliability', [MaintenanceReportController::class, 'reliability']);
        });
    });
});
