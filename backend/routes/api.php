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
use App\Modules\Inventory\Http\Controllers\ItemController;
use App\Modules\Inventory\Http\Controllers\StockBalanceController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use App\Modules\Inventory\Http\Controllers\WarehouseController;
use App\Modules\Procurement\Http\Controllers\GoodsReceiptController;
use App\Modules\Procurement\Http\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Http\Controllers\PurchaseRequisitionController;
use App\Modules\Procurement\Http\Controllers\VendorController;
use App\Modules\Quality\Http\Controllers\IncomingInspectionController;
use App\Modules\Quality\Http\Controllers\NonConformanceReportController;
use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\DeliveryController;
use App\Modules\Sales\Http\Controllers\InvoiceController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
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
        });

        Route::prefix('compliance')->group(function () {
            Route::apiResource('gst-rates', GstRateController::class)->only(['index', 'store', 'update']);
            Route::apiResource('gst-registrations', GstRegistrationController::class)->only(['index', 'store', 'update']);

            Route::get('invoices/{invoice}/gst-breakdown', [GstComputationController::class, 'invoiceBreakdown']);
            Route::get('reports/gstr1', [GstReportController::class, 'gstr1']);
        });
    });
});
