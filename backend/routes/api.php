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
use App\Modules\Inventory\Http\Controllers\FulfilmentController;
use App\Modules\Inventory\Http\Controllers\ItemController;
use App\Modules\Inventory\Http\Controllers\ItemIdentityController;
use App\Modules\Inventory\Http\Controllers\MaterialBagController;
use App\Modules\Inventory\Http\Controllers\MaterialLotController;
use App\Modules\Inventory\Http\Controllers\MaterialLotCostVersionController;
use App\Modules\Inventory\Http\Controllers\MaterialRequestController;
use App\Modules\Inventory\Http\Controllers\ProductionReturnController;
use App\Modules\Inventory\Http\Controllers\SerialNumberController;
use App\Modules\Inventory\Http\Controllers\StockBalanceController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use App\Modules\Inventory\Http\Controllers\StockReservationController;
use App\Modules\Inventory\Http\Controllers\StoreIssueController;
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
use App\Modules\Procurement\Http\Controllers\SupplierBillController;
use App\Modules\Procurement\Http\Controllers\VendorController;
use App\Modules\Production\Http\Controllers\BatchPreviewController;
use App\Modules\Production\Http\Controllers\BinBayController;
use App\Modules\Production\Http\Controllers\BomController;
use App\Modules\Production\Http\Controllers\CapacityPlanController;
use App\Modules\Production\Http\Controllers\CecReportController;
use App\Modules\Production\Http\Controllers\ConfigurationReviewController;
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
use App\Modules\Production\Http\Controllers\ProductionQueueController;
use App\Modules\Production\Http\Controllers\ProductionReportController;
use App\Modules\Production\Http\Controllers\ProductionRequestController;
use App\Modules\Production\Http\Controllers\ProductionSettingsController;
use App\Modules\Production\Http\Controllers\ProductionStandardController;
use App\Modules\Production\Http\Controllers\ProductionStandardPackagingController;
use App\Modules\Production\Http\Controllers\ProductVariantController;
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
use App\Modules\Sales\Http\Controllers\DispatchQualityApprovalController;
use App\Modules\Sales\Http\Controllers\FulfilmentControlController;
use App\Modules\Sales\Http\Controllers\InvoiceController;
use App\Modules\Sales\Http\Controllers\SalesAvailabilityController;
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
            /*
             * THE CONFIGURATION LIFECYCLE ROUTE PATTERN (DEC-20260817-002) —
             * established here on Item and Warehouse and copied verbatim by
             * every other configuration master. The rules, and why this shape
             * rather than another, are in
             * docs/engineering/CONFIGURATION-LIFECYCLE-WIRING.md.
             *
             *   GET    <resource>                 index  — can.delete is null (ask)
             *   GET    <resource>/{id}            show   — can.delete authoritative
             *   POST   <resource>                 store
             *   PUT    <resource>/{id}            update
             *   DELETE <resource>/{id}            hard delete, never a cascade
             *   POST   <resource>/{id}/archive    reversible, reason optional
             *   POST   <resource>/{id}/activate   reversible, reason optional
             *
             * EnsureModulePermission treats DELETE and POST identically (both
             * need `inventory.manage`), so the hard-delete TIER is not
             * expressed here: it is the lifecycle's canHardDelete seam, read
             * from `configuration-delete.manage`. Deliberate — putting it in
             * this group's middleware would 403 archive and activate with it,
             * and those are ordinary module RBAC.
             *
             * The two POST actions are registered BEFORE the apiResource so
             * neither "archive" nor "activate" can be read as an id.
             *
             * `show` exists so an item's own page can load that one item by id
             * instead of hunting for it in the first page of the index list —
             * and now so the delete confirm dialog has an authoritative answer
             * to fetch before it offers the button.
             */
            Route::post('items/{item}/archive', [ItemController::class, 'archive']);
            Route::post('items/{item}/activate', [ItemController::class, 'activate']);
            Route::apiResource('items', ItemController::class)->only(['index', 'store', 'update', 'show', 'destroy']);

            /*
             * THE ITEM MASTER'S IDENTITY REVIEW — read-only, both of them.
             *
             * Under `identity/` rather than as `items/health` and
             * `items/warnings` so neither word can ever be read as an item
             * id, the same reason `fulfilment/queue` sits under a segment.
             *
             * Inside this group, so a GET needs `inventory.view` and nothing
             * new: these endpoints report on the catalogue anybody who can
             * see the catalogue is already looking at, and they change
             * nothing — the warnings they carry are surfaces for OPEN owner
             * questions (Q43, Q59, Q60), never rules.
             */
            Route::get('identity/health', [ItemIdentityController::class, 'health']);
            Route::get('identity/items', [ItemIdentityController::class, 'items']);

            Route::post('warehouses/{warehouse}/archive', [WarehouseController::class, 'archive']);
            Route::post('warehouses/{warehouse}/activate', [WarehouseController::class, 'activate']);
            Route::apiResource('warehouses', WarehouseController::class)->only(['index', 'store', 'update', 'show', 'destroy']);

            Route::get('stock-balances', [StockBalanceController::class, 'index']);

            Route::get('stock-movements', [StockMovementController::class, 'index']);
            Route::post('stock-movements/receipts', [StockMovementController::class, 'receipt']);
            Route::post('stock-movements/issues', [StockMovementController::class, 'issue']);
            Route::post('stock-movements/transfers', [StockMovementController::class, 'transfer']);

            Route::apiResource('batches', BatchController::class)->only(['index', 'store']);
            Route::get('batches/{batch}/ledger', [BatchController::class, 'ledger']);

            Route::apiResource('serial-numbers', SerialNumberController::class)->only(['index', 'store']);
            Route::get('serial-numbers/{serial_number}/history', [SerialNumberController::class, 'history']);

            /*
             * THE STORE ISSUE (Phase 7.5): the handover of material to
             * production, and the three states it keeps apart —
             * Store Stock -> Issued to Production -> Consumed, with unused
             * material returning. A store issue is NOT a consumption, and
             * nothing here deducts stock as one.
             *
             * Append-only, like every other lifecycle on this API: every
             * change of state is a POST. There is no PUT and no DELETE — a
             * wrong handover is CANCELLED (which reverses the stock, with a
             * reason), never edited away.
             *
             * `outstanding` and `trace` are registered BEFORE the
             * {store_issue} route so neither word is ever read as an id.
             */
            Route::get('store-issues/outstanding', [StoreIssueController::class, 'outstanding']);
            Route::get('store-issues/trace', [StoreIssueController::class, 'trace']);
            Route::get('store-issues', [StoreIssueController::class, 'index']);
            Route::get('store-issues/{store_issue}', [StoreIssueController::class, 'show']);
            Route::post('store-issues', [StoreIssueController::class, 'store']);
            Route::post('store-issues/{store_issue}/returns', [StoreIssueController::class, 'returnUnused']);
            Route::post('store-issues/{store_issue}/complete', [StoreIssueController::class, 'complete']);
            Route::post('store-issues/{store_issue}/cancel', [StoreIssueController::class, 'cancel']);

            /*
             * THE WAY HOME FROM THE PRODUCTION AREA — the daily return.
             *
             * `returnable` is the read: what is standing in production, split
             * into the part open store issues are holding and the part that
             * answers no document. The POST takes both kinds in ONE call —
             * lines carry an OPTIONAL store_issue_line_id — because a
             * storekeeper handing material back should not have to know which
             * endpoint their kilograms belong to. See ProductionReturnService.
             */
            Route::get('production-returns/returnable', [ProductionReturnController::class, 'returnable']);
            Route::post('production-returns', [ProductionReturnController::class, 'store']);

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

                // The bag scan AT THE HANDOVER — inside this gate for the
                // same reason the floor's scan is: a barcode only resolves
                // to a MaterialBag with traceability on.
                Route::post('store-issues/{store_issue}/bag-scans', [StoreIssueController::class, 'scanBag']);
            });
        });

        // A material lot's COST history lives at the lot's own URL but is
        // gated on finance, not inventory — purchase rates are Owner and
        // Accounts territory, not the store's. Hence a sibling group rather
        // than a nested one: route-group middleware accumulates, so putting
        // these inside the inventory group above would demand BOTH
        // permissions and lock Accounts out of their own data.
        // Append-only, and this cost-version ledger is the strict case: no PUT,
        // no PATCH, no DELETE — a recorded cost is history, and history is added
        // to, never edited (MaterialLotCostVersionTest pins the 405s).
        //
        // The rule scoped honestly, because the old wording said "anywhere" and
        // that was never true of this file: TRANSACTIONS AND LEDGERS are
        // append-only. CONFIGURATION MASTERS carry a lifecycle — create, edit,
        // archive/activate, and a hard delete permitted ONLY where the backend
        // has proved the record was never used and nothing depends on it
        // (DEC-20260817-002; the guard is App\Support\Configuration\
        // ConfigurationLifecycle, and it refuses with counts rather than
        // cascading).
        Route::prefix('inventory')->middleware('module:finance')->group(function () {
            Route::get('material-lots/{lot}/cost-versions', [MaterialLotCostVersionController::class, 'index']);
            Route::post('material-lots/{lot}/cost-versions', [MaterialLotCostVersionController::class, 'store']);
        });

        /*
         * THE PRODUCTION MATERIAL REQUEST and THE STORE'S QUEUE (Phase 7.5).
         *
         * A document with TWO SIDES, so a sibling group again rather than a
         * nested one — but this time gated on EITHER module: the floor
         * raises the request and the store works it, and neither holds the
         * other's permission. `module:production,inventory` means "any of
         * these", never "both" (EnsureModulePermission).
         *
         * A material request is NOT a purchase requisition (that one faces
         * a vendor and carries money — /procurement/purchase-requisitions
         * below) and it is NOT a consumption: the store handing material
         * over moves it into Production/WIP (DEC-20260817-001), and what a
         * batch used is calculated later, somewhere else.
         *
         * Append-only: every lifecycle step is a POST, there is no PUT and
         * no DELETE. A cancelled request keeps its row and its reason.
         */
        // `own-draft` FIRST, and on the GROUP rather than the routes. Group
        // middleware run before route middleware, and this OR-group demands
        // `.manage` for a POST — so a read-only store login was refused at the
        // group and never reached the gate, getting 403 for a draft that exists
        // and 404 for one that does not. The gate has to be the first thing
        // that can answer. It is a no-op on the routes here that carry no
        // {material_request}.
        Route::prefix('inventory')->middleware(['own-draft', 'module:production,inventory'])->group(function () {
            // The queue itself, and one request in full. Read by the store
            // (inventory.view) and by the floor that raised it
            // (production.view).
            Route::get('material-requests', [MaterialRequestController::class, 'index']);
            // `own-draft` runs BEFORE the FormRequest, deliberately: a gate in
            // the controller runs after validation, so a 422 from the cancel
            // reason answered "this row exists" for a draft the store may not
            // know about. See EnsureDraftIsProductionsOwn.
            Route::get('material-requests/{material_request}', [MaterialRequestController::class, 'show']);
            // Either side may withdraw one, with a reason: the floor when
            // the run is pulled, the store when it cannot fulfil.
            Route::post('material-requests/{material_request}/cancel', [MaterialRequestController::class, 'cancel']);

            // THE REQUESTABLE MATERIALS. Deliberately here and NOT on
            // /inventory/items: that resource is gated `module:inventory`
            // alone, while raising a request is `module:production`, so a
            // production-only login reading the picker from there gets a 403
            // and an EMPTY dropdown. This group is the one both desks can
            // read, which is exactly the audience the picker has.
            //
            // It also replaces a `GET /inventory/items?per_page=1000` — the
            // WHOLE item master, finished goods included, capped at 1000 rows
            // and therefore silently truncating once the master outgrows it.
            Route::get('requestable-materials', [MaterialRequestController::class, 'requestableMaterials']);

            // WHAT IS ALREADY ON THE FLOOR — Production/WIP balances, so the
            // floor can see what it holds before asking for more. Same group
            // as the queue: both desks read it.
            Route::get('production-floor-stock', [MaterialRequestController::class, 'productionFloorStock']);
        });

        // Raising and submitting are the FLOOR's act — production.manage.
        // The store fulfils requests; it does not write them for the floor.
        Route::prefix('inventory')->middleware('module:production')->group(function () {
            Route::post('material-requests', [MaterialRequestController::class, 'store']);
        });

        // SUBMIT IS THE THIRD ROUTE THAT REACHES AN UNSUBMITTED REQUEST, and
        // leaving it out of the gate left the whole gate defeated: Laravel runs
        // SubstituteBindings ahead of the unprioritised `module` alias, so
        // binding answered 404 for an id that does not exist while the
        // permission check answered 403 for one that does. One ordinary POST
        // per id and a store login had enumerated production's drafts — the
        // exact disclosure EnsureDraftIsProductionsOwn exists to deny.
        //
        // It sits in the group BOTH desks may enter, with `own-draft` first and
        // the production-only check after, because the obvious fix does not
        // work: appending `own-draft` to a route inside `module:production`
        // never runs, the group aborts first. The permission answer is now
        // reached only for a request the caller can already see.
        Route::prefix('inventory')->middleware(['own-draft', 'module:production,inventory'])->group(function () {
            Route::post('material-requests/{material_request}/submit', [MaterialRequestController::class, 'submit'])
                ->middleware('module:production');
        });

        /*
         * SALES ORDER FULFILMENT — the STORE's half.
         *
         * The chain: the sales desk raises an order, its confirmed lines
         * appear on the store's fulfilment queue, the store either HOLDS
         * finished goods that already exist or asks the FLOOR for what does
         * not, and the floor's half of the same chain is the production
         * request group directly below.
         *
         * NONE OF IT MOVES STOCK (invariant 1). A hold changes who stock is
         * spoken for and nothing else; the balance is read under a lock and
         * put back exactly as it was found. Dispatch is still the Delivery
         * flow, unchanged and ungated (Q27 untouched) — it simply SPENDS the
         * hold it was made against on the way past.
         *
         * A SIBLING GROUP rather than lines inside the big inventory group
         * above, for the same reason the store-issue and material-request
         * blocks are siblings: one story, one comment, and the store's
         * existing surface is not disturbed to add it. Same gate as that
         * group (`module:inventory`) — holding stock back from a customer is
         * the STORE's act, and EnsureModulePermission already settles that a
         * GET needs .view while a POST needs .manage.
         *
         * Append-only: every action is a POST. A hold is never edited and
         * never deleted — it is given up, with a reason, and keeps its row.
         *
         * `queue` and `planning` are registered under the `fulfilment/`
         * segment, so neither word can ever be read as a line id.
         */
        Route::prefix('inventory')->middleware('module:inventory')->group(function () {
            // The queue itself: every line of every live order, over-reserved
            // rows FIRST, fully allocated ones hidden unless asked for by
            // name (S8, S16).
            Route::get('fulfilment/queue', [FulfilmentController::class, 'queue']);
            // WHEN THE FACTORY COULD HAVE IT. Computed on read and never
            // stored (S11) — there is no ETA column anywhere in this build,
            // because a saved date is already wrong the moment somebody
            // reorders the queue. A bare object, not a row.
            Route::get('fulfilment/planning', [FulfilmentController::class, 'planning']);

            // The two LINE-scoped acts: hold what is there, ask for what is
            // not. Both refuse inside a transaction on figures recomputed
            // under the balance lock, never on what this screen last saw.
            Route::post('fulfilment/lines/{sales_order_line}/reserve', [FulfilmentController::class, 'reserve']);
            Route::post('fulfilment/lines/{sales_order_line}/send-to-production', [FulfilmentController::class, 'sendToProduction']);

            // The two HOLD-scoped acts, on a reservation by its own id.
            // Re-pointing is release + reserve in ONE transaction under ONE
            // balance lock (S4), so the pieces in flight are never invisible
            // to the target line's own free-stock check.
            Route::post('reservations/{reservation}/release', [StockReservationController::class, 'release']);
            Route::post('reservations/{reservation}/repoint', [StockReservationController::class, 'repoint']);
        });

        /*
         * THE PRODUCTION REQUEST — the FLOOR's half of the same chain, and
         * the second two-sided document on this API.
         *
         * Gated exactly like the material request above and for the same
         * reason (P3): the STORE raises it and the FLOOR runs it, and neither
         * desk can be asked to hold the other's permission to read the one
         * piece of paper they share. `module:production,inventory` means "any
         * of these", never "both" (EnsureModulePermission).
         *
         * TWO SIBLING GROUPS, not one group with route-level middleware. The
         * material-request block only reaches for route-level middleware
         * because `own-draft` has to run BEFORE the permission check there;
         * nothing here has that constraint, so the plain shape is the honest
         * one: what BOTH desks may do in the OR group, what only the floor
         * may do in its own.
         *
         * NOTHING HERE TOUCHES A BATCH (invariant 2). `start` records that a
         * person picked the job up — people start batches, and this API
         * creates, starts and cancels none.
         *
         * Append-only: every step is a POST, there is no PUT and no DELETE.
         * A cancelled request keeps its row and its reason. `reorder` is
         * registered before the {production_request} routes so the word can
         * never be read as an id.
         */
        Route::prefix('production')->middleware('module:production,inventory')->group(function () {
            // The queue, in priority order. Read by the floor that runs it
            // (production.view) and by the store that raised it
            // (inventory.view) — deliberately unpaginated: reorder renumbers
            // the WHOLE queue, and nobody should reorder a list they can only
            // see one page of.
            Route::get('requests', [ProductionRequestController::class, 'index']);
            // Either side may withdraw one, with a reason: the store when the
            // customer no longer wants it, the floor when it cannot run it.
            Route::post('requests/{production_request}/cancel', [ProductionRequestController::class, 'cancel']);

            /*
             * THE SAME QUEUE WITH THE DEMAND AND THE DATE JOINED ON — what
             * the floor's Production Queue screen reads. Read-only, and the
             * rows are the rows above: this adds no request and hides none.
             *
             * Registered here rather than composed in the browser so the one
             * document the two desks share is ONE read, and so the queue's
             * order and its dates cannot be fetched a moment apart and shown
             * disagreeing.
             *
             * THE OR GATE SAYS WHO MAY OPEN THIS QUEUE. IT DOES NOT WIDEN
             * WHAT THE ROW MAY CARRY. The figures this read joins on belong
             * to other modules and are refused elsewhere — the planning block
             * to `module:inventory` (fulfilment/planning), the order's
             * expected date to `module:sales` — so ProductionQueueResource
             * gates each block by the module that owns it, the way
             * DashboardService::summary() gates its own composed blocks. A
             * caller sees here only what they could already read elsewhere,
             * and no 403 that stood yesterday stops standing because this
             * route exists. Whether the FLOOR should be given more than that
             * is the owner's call and is asked in PENDING-OWNER-QUESTIONS.
             *
             * A sibling SEGMENT of `requests/`, never a word under it, so
             * `queue` can never be read as a {production_request} id.
             */
            Route::get('queue', [ProductionQueueController::class, 'index']);
        });

        // The queue's ORDER and picking a job up are the FLOOR's alone —
        // production.manage. The store says WHAT is owed; it does not tell
        // the factory what to run first, and it does not press Start for
        // somebody standing at a machine.
        Route::prefix('production')->middleware('module:production')->group(function () {
            Route::post('requests/reorder', [ProductionRequestController::class, 'reorder']);
            Route::post('requests/{production_request}/start', [ProductionRequestController::class, 'start']);
        });

        Route::prefix('procurement')->middleware('module:procurement')->group(function () {
            // Vendor on the Configuration Lifecycle Contract (DEC-20260817-002).
            // archive/activate are registered BEFORE the apiResource so neither
            // word can be read as a {vendor} id.
            Route::post('vendors/{vendor}/archive', [VendorController::class, 'archive']);
            Route::post('vendors/{vendor}/activate', [VendorController::class, 'activate']);
            Route::apiResource('vendors', VendorController::class)->only(['index', 'store', 'update', 'show', 'destroy']);

            Route::apiResource('purchase-requisitions', PurchaseRequisitionController::class)->only(['index', 'store']);
            Route::post('purchase-requisitions/{purchase_requisition}/approve', [PurchaseRequisitionController::class, 'approve']);
            Route::post('purchase-requisitions/{purchase_requisition}/reject', [PurchaseRequisitionController::class, 'reject']);

            // Phase 6: show carries lines, schedules, revisions, the receipts
            // summary and the Tally link; trace is the chain behind the order
            // (PO → GRNs → lots → bags → loads → consuming batches). The
            // lifecycle is append-only POST actions — never PUT/DELETE:
            //   send    Draft → Sent (announces PurchaseOrderSent after commit)
            //   amend   Draft only — replaces lines, keeps the prior ones as a revision
            //   close   Sent | PartiallyReceived → Closed, with a reason
            //   cancel  Draft | Sent with zero receipts → Cancelled, with a reason
            // A Tally-originated mirror refuses amend/close/cancel (422).
            Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'store', 'show']);
            Route::get('purchase-orders/{purchase_order}/trace', [PurchaseOrderController::class, 'trace']);
            Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'send']);
            Route::post('purchase-orders/{purchase_order}/amend', [PurchaseOrderController::class, 'amend']);
            Route::post('purchase-orders/{purchase_order}/close', [PurchaseOrderController::class, 'close']);
            Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel']);

            Route::apiResource('goods-receipts', GoodsReceiptController::class)->only(['index', 'store', 'show']);
        });

        /*
         * SUPPLIER BILLS — the vendor's invoice recorded (28-Aug audit
         * finding 10). Under the procurement path but gated module:FINANCE,
         * not procurement: every figure on a bill is a purchase rate, and
         * FC-06 puts those with Owner/Accounts only. A procurement login
         * without finance access must not read a bill at all, so the gate
         * is the group's, not a per-field omission.
         *
         * No Tally route exists here on purpose: Purchase Invoice posting
         * is withheld while Q39 (per-rate purchase ledger), Q41 (where GST
         * is filed) and Q28 (does the factory want AP at all) are open.
         * Lifecycle is append-only POSTs; a bill is cancelled with a
         * reason, never deleted. `ledger-options` is registered before the
         * apiResource so the word cannot be read as a {supplier_bill} id.
         */
        Route::prefix('procurement')->middleware('module:finance')->group(function () {
            Route::get('supplier-bills/ledger-options', [SupplierBillController::class, 'ledgerOptions']);
            Route::get('supplier-bills/item-options', [SupplierBillController::class, 'itemOptions']);
            Route::get('supplier-bills/vendor-options', [SupplierBillController::class, 'vendorOptions']);
            Route::get('supplier-bills/order-options', [SupplierBillController::class, 'orderOptions']);
            Route::get('supplier-bills/receipt-line-options', [SupplierBillController::class, 'receiptLineOptions']);
            Route::apiResource('supplier-bills', SupplierBillController::class)->only(['index', 'store', 'show', 'update']);
            Route::post('supplier-bills/{supplier_bill}/record', [SupplierBillController::class, 'record']);
            Route::post('supplier-bills/{supplier_bill}/cancel', [SupplierBillController::class, 'cancel']);
            Route::post('supplier-bills/{supplier_bill}/attachment', [SupplierBillController::class, 'attach']);
            Route::get('supplier-bills/{supplier_bill}/attachment', [SupplierBillController::class, 'download']);
        });

        Route::prefix('sales')->middleware('module:sales')->group(function () {
            // Customer on the Configuration Lifecycle Contract (DEC-20260817-002).
            // Registered before the apiResource so neither verb reads as an id.
            Route::post('customers/{customer}/archive', [CustomerController::class, 'archive']);
            Route::post('customers/{customer}/activate', [CustomerController::class, 'activate']);
            Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'update', 'show', 'destroy']);

            // WHAT THE DESK MAY PROMISE, per item, as the order is typed:
            // on_hand / reserved / free / over_reserved out of the
            // finished-goods store. On the SALES surface although every
            // figure is Inventory's, deliberately — the desk holds no
            // inventory permission and must not need one to answer a
            // customer asking "do you have it?". Read-only, and seeing free
            // stock holds nothing: a hold is the store's act, on the
            // fulfilment queue. FC-06: four quantity keys, no cost.
            Route::get('availability', [SalesAvailabilityController::class, 'index']);

            // Phase 3.5 — the honesty statement the Sales pages render: Tally-
            // side Sales / Sales Order vouchers are NOT mirrored here
            // (DEC-20260809-003). Read-only; a bare object, not a row.
            Route::get('tally-mirror', [SalesTallyMirrorController::class, 'show']);

        });

        // THE SALES FULFILMENT CONTROL VIEW — one row per sales order line,
        // and the ONE surface Sales, Store, Production, Quality and Accounts
        // all read the same state from. Deliberately outside the
        // `module:sales` group above: it is read by four teams, and OR
        // semantics (EnsureModulePermission, Phase 7.5) mean holding ANY of
        // the four modules' permission is enough. Nobody has to hold another
        // team's permission to see the piece of paper they share.
        //
        // READ ONLY, and it stays that way: every act — hold, release,
        // re-point, ask the floor, start, dispatch, invoice — stays on the
        // screen that already owns and audits it.
        Route::prefix('sales')->middleware('module:sales,inventory,production,quality')->group(function () {
            Route::get('fulfilment-control', [FulfilmentControlController::class, 'index']);
        });

        Route::prefix('sales')->middleware('module:sales')->group(function () {

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

            // QUALITY'S DISPATCH SIGN-OFF (DEC-20260831-006). The record lives
            // on a Sales order line; the ACT is Quality's, so it is gated here
            // — Sales must not be able to approve its own dispatch. The
            // dispatch itself then refuses in DeliveryService on the quantity
            // stamped by these routes.
            Route::post('dispatch-approvals/lines/{sales_order_line}/approve', [DispatchQualityApprovalController::class, 'approve']);
            Route::post('dispatch-approvals/lines/{sales_order_line}/revoke', [DispatchQualityApprovalController::class, 'revoke']);

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
                // "Sync Now" (DEC-20260825-002) — the queue-wide form of
                // the release above: ask what is already queued to go out
                // on the agent's next poll. Registered ahead of nothing
                // parameterised and named with a literal segment that no
                // entry id can be read as. Owner/Accounts only, enforced
                // INSIDE the controller (SyncNowAuthority) on top of the
                // tally-sync.manage this group already requires for a POST.
                // It talks to Tally in no way at all: no post, no ack, no
                // masters pull.
                Route::post('sync-now', [TallySyncController::class, 'syncNow']);

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
            // The Configuration Lifecycle Contract (DEC-20260817-002) on the
            // employee master. `show` before the lifecycle POSTs so no literal
            // segment is ever read as an id; DELETE is the hard delete and is
            // additionally gated on the Owner-level configuration-delete
            // permission inside EmployeeService — module:hrms alone is not
            // enough for it, while archive/activate need only the module.
            Route::get('employees/{employee}', [EmployeeController::class, 'show']);
            Route::post('employees/{employee}/archive', [EmployeeController::class, 'archive']);
            Route::post('employees/{employee}/activate', [EmployeeController::class, 'activate']);
            Route::delete('employees/{employee}', [EmployeeController::class, 'destroy']);

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
            Route::apiResource('work-centers', WorkCenterController::class)->only(['index', 'show']);

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

            /*
             * SCRAP REASONS — the Configuration Lifecycle Contract's full
             * row (DEC-20260817-002): create, view, edit, archive/activate,
             * and a hard delete that only a Super Admin / Owner may reach
             * and only for a reason proven never used. Archive is POST
             * because it is reversible and carries a reason; the delete is
             * DELETE because it is neither (audit 17-Aug-2026 §2).
             */
            Route::apiResource('scrap-reasons', ScrapReasonController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
            Route::post('scrap-reasons/{scrap_reason}/archive', [ScrapReasonController::class, 'archive']);
            Route::post('scrap-reasons/{scrap_reason}/activate', [ScrapReasonController::class, 'activate']);

            // SHIFTS — same lifecycle row. Archiving a shift is what the
            // rename-era Morning/Afternoon/Night rows on live are, and it
            // touches no Tally voucher (DEC-20260817-002 §4); the hard
            // delete is refused while any batch, summary, log or Tally
            // Stock Journal still names it.
            Route::apiResource('shifts', ShiftController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
            Route::post('shifts/{shift}/archive', [ShiftController::class, 'archive']);
            Route::post('shifts/{shift}/activate', [ShiftController::class, 'activate']);

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
            // The Configuration Lifecycle Contract on the machine-product
            // configuration. `archive` is the same act the existing
            // `deactivate` above performs — one service method behind both —
            // and `activate` re-runs the approval gates before a withdrawn
            // configuration may govern production again. Declared after
            // configurations/import so the literal segment is never shadowed.
            Route::get('configurations/{production_configuration}', [ProductionConfigurationController::class, 'show']);
            Route::post('configurations/{production_configuration}/archive', [ProductionConfigurationController::class, 'archive']);
            Route::post('configurations/{production_configuration}/activate', [ProductionConfigurationController::class, 'activate']);
            Route::delete('configurations/{production_configuration}', [ProductionConfigurationController::class, 'destroy']);

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
            // Since DEC-20260821-001 an identity naming a DIFFERENT item from
            // the standard's product is refused here and on the PATCH below —
            // that packing belongs under its own product. Existing rows are
            // untouched and still editable for their counts.
            Route::post('standards/{standard}/packagings', [ProductionStandardPackagingController::class, 'store']);
            Route::put('standards/{standard}/packagings/{packaging}', [ProductionStandardPackagingController::class, 'update']);
            // Identity ONLY — item_id and its provenance, never a count. The
            // review panel's Link uses this so that linking a Tally item can
            // never re-derive a box count the importer deliberately left as
            // the sheet stated it (Phase 5 fix P1-a). production.manage via
            // the group, like the PUT above.
            Route::patch('standards/{standard}/packagings/{packaging}/identity', [ProductionStandardPackagingController::class, 'identity']);
            // The Configuration Lifecycle Contract on the packing variant.
            // Every one of these resolves the variant THROUGH its standard
            // (ProductionStandardPackagingService::resolveFor), so a guessed
            // id can never reach a sibling product's variant.
            Route::get('standards/{standard}/packagings/{packaging}', [ProductionStandardPackagingController::class, 'show']);
            Route::post('standards/{standard}/packagings/{packaging}/archive', [ProductionStandardPackagingController::class, 'archive']);
            Route::post('standards/{standard}/packagings/{packaging}/activate', [ProductionStandardPackagingController::class, 'activate']);
            Route::delete('standards/{standard}/packagings/{packaging}', [ProductionStandardPackagingController::class, 'destroy']);
            // ...and on the standard itself. `standards/{standard}` is
            // declared AFTER every literal sibling above (coverage, import)
            // so none of those words is ever bound as an id.
            Route::get('standards/{standard}', [ProductionStandardController::class, 'show']);
            Route::post('standards/{standard}/archive', [ProductionStandardController::class, 'archive']);
            Route::post('standards/{standard}/activate', [ProductionStandardController::class, 'activate']);
            Route::delete('standards/{standard}', [ProductionStandardController::class, 'destroy']);
            // One product's variant tree — item → standards → packagings,
            // each packaging with its Tally identity (sku · name · guid) and
            // a configuration_status whose `missing` words the screens
            // repeat rather than re-derive (Phase 5, P5-02 / P5-06). Read
            // only; the group's guard gives it production.view.
            Route::get('products/{item}/variants', ProductVariantController::class);
            // The configuration review: packagings/standards without a Tally
            // identity, packagings whose Tally name is shared by more than
            // one item, items still on their seeded SKU — each with the
            // existing Tally items a person could LINK (P5-03). Read only;
            // every fix goes through the packaging / attach-item / item
            // endpoints that already exist. `configuration` (singular) is a
            // sibling of the `configurations` machine-exception routes
            // above, not a member of them.
            Route::get('configuration/review', ConfigurationReviewController::class);

            Route::get('downtime-reasons', [DowntimeReasonController::class, 'index']);
            Route::get('downtime-reasons/{downtime_reason}', [DowntimeReasonController::class, 'show']);
            Route::post('downtime-reasons', [DowntimeReasonController::class, 'store']);
            Route::put('downtime-reasons/{downtime_reason}', [DowntimeReasonController::class, 'update']);
            // Archive/activate/delete — the lifecycle row. `downtime_reasons`
            // has an is_active flag and no deleted_at, which is the right
            // shape: archive() takes the active-flag branch and never the
            // soft-delete one.
            Route::post('downtime-reasons/{downtime_reason}/archive', [DowntimeReasonController::class, 'archive']);
            Route::post('downtime-reasons/{downtime_reason}/activate', [DowntimeReasonController::class, 'activate']);
            Route::delete('downtime-reasons/{downtime_reason}', [DowntimeReasonController::class, 'destroy']);

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

            // Phase 5.7 — the CEC's DATA: the Shift Summary + the completed
            // entries of a date, composed per shift/machine (no new
            // arithmetic). The FORMAT stays BLOCKED — SOURCE DOCUMENT
            // REQUIRED (the response says so; the export kind answers 409).
            Route::get('cec', CecReportController::class);

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

            // MOULDS — the lifecycle row on a three-case status enum:
            // archive writes `retired`, activate writes `active`, and a
            // mould `under_repair` is offered BOTH because it is neither.
            Route::apiResource('molds', MoldController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
            Route::post('molds/{mold}/archive', [MoldController::class, 'archive']);
            Route::post('molds/{mold}/activate', [MoldController::class, 'activate']);

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
                // READ ONLY. The three machine-stamped WRITE doors —
                // day-bin/load, day-bin/return and day-bin/count — are gone
                // (Phase 7.5, WS-C). None of them had a single UI caller:
                // day-bin/load was the machine-stamped load path DEC-20260807-006
                // retired, and DEC-20260807-007 records that the bin is never
                // weighed, so no count will ever be taken through day-bin/count.
                // DEC-20260817-001 then removed the Day Bin from the factory's
                // logical locations altogether (Raw Material Store ->
                // Production/WIP -> Finished Goods Store).
                //
                // NOTHING WAS DELETED BELOW THE DOORS: day_bin_movements, every
                // historical machine-stamped row in it, DayBinLedgerService and
                // the writers TraceabilityService::loadBagToDayBin /
                // returnFromDayBin / recordCount all remain — the reads here and
                // the historical readers listed in
                // docs/engineering/AUDIT-MATERIAL-FLOW-2026-08-17.md §3 still
                // serve that history. Closing-count writes are unaffected: they
                // go through ShiftProductionEntryService::recordClosingDayBin,
                // which calls the ledger directly and never touched these routes.
                Route::get('day-bin/movements', [DayBinController::class, 'movements']);
                Route::get('day-bin/consumption', [DayBinController::class, 'consumption']);

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
            Route::apiResource('work-centers', WorkCenterController::class)->only(['store', 'update', 'destroy']);
            // Taking a machine out of service and putting it back are
            // WRITES, so they belong on this side of the split with store
            // and update — not beside the index, which every supervisor
            // reads. The hard delete needs machine-master.manage to reach
            // the route AND the Super Admin / Owner tier to get past
            // ConfigurationLifecycle (DEC-20260817-002 §3).
            Route::post('work-centers/{work_center}/archive', [WorkCenterController::class, 'archive']);
            Route::post('work-centers/{work_center}/activate', [WorkCenterController::class, 'activate']);
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
