<?php

use App\Modules\HRMS\Exports\AttendanceMonthSheetExport;
use App\Modules\Procurement\Exports\GoodsReceiptLinesExport;
use App\Modules\Procurement\Exports\GoodsReceiptsExport;
use App\Modules\Procurement\Exports\PurchaseOrderLinesExport;
use App\Modules\Procurement\Exports\PurchaseOrdersExport;
use App\Modules\Production\Exports\CecExport;
use App\Modules\Production\Exports\ProductionReportExport;
use App\Modules\Production\Exports\ReconciliationReportExport;
use App\Modules\Production\Exports\ShiftSummaryExport;
use App\Modules\Production\Exports\TraceabilityReportExport;
use App\Modules\Sales\Exports\DeliveriesExport;
use App\Modules\Sales\Exports\InvoicesExport;
use App\Modules\Sales\Exports\SalesOrdersExport;
use App\Modules\TallySync\Exports\TallySyncEntriesExport;
use App\Modules\TallySync\Exports\TallySyncHistoryExport;

/*
 * The Download / Export Center (MASTER-PLAN Phase 4.5).
 *
 * Every export is a SERVER-SIDE read of the same query the list or report
 * endpoint runs, with the same filters, gated for the same reader — never
 * the rows a browser happens to have rendered. Core owns the Center
 * (App\Modules\Core\Exports); each module owns its kinds and lists them
 * here, which is the ONE place a kind is registered.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Row cap
    |--------------------------------------------------------------------------
    |
    | Exports are SYNCHRONOUS streamed CSV: the host runs no queue worker
    | and no scheduler (TECHNICAL-DOCS.md §8), so there is nothing to hand
    | a long export to. Over the cap the server REFUSES with a sentence
    | naming the count and the cap ("N rows match; the cap is 5,000 —
    | narrow the range") — never a silent truncation. A kind may set its
    | own cap (ExportKind::rowCap()); this is the default.
    |
    */

    'row_cap' => (int) env('EXPORT_ROW_CAP', 5000),

    /*
    |--------------------------------------------------------------------------
    | Recent downloads shown
    |--------------------------------------------------------------------------
    |
    | How many of the CALLER's own runs GET /exports/runs returns, newest
    | first. Every POST writes a run — refusals included — so the audit
    | says who tried what.
    |
    */

    'runs_shown' => 50,

    /*
    |--------------------------------------------------------------------------
    | Registered kinds
    |--------------------------------------------------------------------------
    |
    | Every ExportKind the Center offers, by class name, in catalogue order.
    | Adding a kind = adding its class here (ExportServiceProvider registers
    | them with the ExportRegistry). Each class lives in ITS module's
    | Exports/ folder and reaches its data only through that module's
    | Service + JsonResource, so the file inherits the screen's gating.
    |
    */

    'kinds' => [
        ShiftSummaryExport::class,
        ProductionReportExport::class,
        ReconciliationReportExport::class,
        TraceabilityReportExport::class,
        CecExport::class,
        TallySyncEntriesExport::class,
        TallySyncHistoryExport::class,
        SalesOrdersExport::class,
        DeliveriesExport::class,
        InvoicesExport::class,
        PurchaseOrdersExport::class,
        PurchaseOrderLinesExport::class,
        GoodsReceiptsExport::class,
        GoodsReceiptLinesExport::class,
        AttendanceMonthSheetExport::class,
    ],

];
