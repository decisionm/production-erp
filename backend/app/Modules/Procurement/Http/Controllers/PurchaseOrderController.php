<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\AmendPurchaseOrderRequest;
use App\Modules\Procurement\Http\Requests\CancelPurchaseOrderRequest;
use App\Modules\Procurement\Http\Requests\ClosePurchaseOrderRequest;
use App\Modules\Procurement\Http\Requests\ListPurchaseOrdersRequest;
use App\Modules\Procurement\Http\Requests\StorePurchaseOrderRequest;
use App\Modules\Procurement\Http\Resources\PurchaseOrderResource;
use App\Modules\Procurement\Http\Resources\PurchaseOrderTraceResource;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\ProcurementDocumentQuery;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Procurement\Services\PurchaseOrderTraceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Thin, as CLAUDE.md asks: each method injects a Service, calls one method,
 * returns a Resource. The purchase order's lifecycle (Phase 6) is
 * append-only POST actions — send, amend, close, cancel — never a PUT or a
 * DELETE; the state machine that admits or refuses each lives in
 * PurchaseOrderService (a refusal is a 422 with a stable `code`).
 * Permissions: the procurement route group's module gate — reads need
 * procurement.view, every POST needs procurement.manage (the same gate
 * send() has always had).
 */
class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly PurchaseOrderTraceService $trace,
        private readonly ProcurementDocumentQuery $query,
    ) {}

    /**
     * The list, filtered by ListPurchaseOrdersRequest (Phase 4.5); an empty
     * query string is the same unfiltered, newest-first list as before.
     * `per_page` up to 1000 so a link that points at one older order can
     * actually find it — the default first page of 20 would hide it.
     */
    public function index(ListPurchaseOrdersRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return PurchaseOrderResource::collection($this->orders->paginate($this->query->perPage($filters), $filters));
    }

    /** One order with its lines, schedules, revisions, receipts summary and Tally link (Phase 6, P6-02). */
    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make($this->orders->show($purchaseOrder));
    }

    /**
     * The chain behind one order — PO → GRNs → lots → bags → loads →
     * consuming batches, with each stock movement's purpose (Phase 6,
     * P6-02). Shaped for THIS reader: a rate rides only for finance eyes.
     */
    public function trace(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderTraceResource
    {
        return new PurchaseOrderTraceResource($this->trace->orderTrace($purchaseOrder, $request->user()));
    }

    public function store(StorePurchaseOrderRequest $request): PurchaseOrderResource
    {
        $order = $this->orders->create($request->validated(), $request->user()?->id);

        return PurchaseOrderResource::make($order);
    }

    public function send(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make($this->orders->send($purchaseOrder));
    }

    /** Draft only: replace the lines, keep the prior ones as a revision (Phase 6, P6-01). */
    public function amend(AmendPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make($this->orders->amend($purchaseOrder, $request->validated(), $request->user()?->id));
    }

    /** Sent | PartiallyReceived → Closed with a reason (Phase 6, P6-01). */
    public function close(ClosePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make($this->orders->close($purchaseOrder, $request->validated('reason'), $request->user()?->id));
    }

    /** Draft | Sent with zero receipts → Cancelled with a reason (Phase 6, P6-01). */
    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make($this->orders->cancel($purchaseOrder, $request->validated('reason'), $request->user()?->id));
    }
}
