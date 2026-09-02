<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\CancelStoreIssueRequest;
use App\Modules\Inventory\Http\Requests\ListStoreIssuesRequest;
use App\Modules\Inventory\Http\Requests\StoreStoreIssueBagScanRequest;
use App\Modules\Inventory\Http\Requests\StoreStoreIssueRequest;
use App\Modules\Inventory\Http\Requests\StoreStoreIssueReturnRequest;
use App\Modules\Inventory\Http\Requests\TraceStoreIssuesRequest;
use App\Modules\Inventory\Http\Resources\StoreIssueBagScanResource;
use App\Modules\Inventory\Http\Resources\StoreIssueResource;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Services\StoreIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * THE STORE'S SIDE OF THE HANDOVER (Phase 7.5, WS-B).
 *
 * Append-only by design, like every other lifecycle in this codebase: every
 * change of state is a POST action. There is no PUT and no DELETE — a
 * handover that was wrong is cancelled (which reverses stock, with a
 * reason), never edited away.
 */
class StoreIssueController extends Controller
{
    public function __construct(private readonly StoreIssueService $issues) {}

    public function index(ListStoreIssuesRequest $request): AnonymousResourceCollection
    {
        return StoreIssueResource::collection($this->issues->paginate(
            status: $request->string('status')->toString() ?: null,
            materialRequestId: $request->integer('material_request_id') ?: null,
            itemId: $request->integer('item_id') ?: null,
            issuedFrom: $request->string('issued_from')->toString() ?: null,
            issuedTo: $request->string('issued_to')->toString() ?: null,
            q: $request->string('q')->toString() ?: null,
            perPage: $request->integer('per_page') ?: 20,
        ));
    }

    /**
     * What is standing with production right now, per material — the issue
     * side and the ledger side together, never one dressed as the other.
     */
    public function outstanding(): JsonResponse
    {
        $reconciliation = $this->issues->outstanding();

        return response()->json([
            'data' => $reconciliation['rows'],
            'basis' => $reconciliation['basis'],
        ]);
    }

    /**
     * Which store issues put a material into Production/WIP — the trace
     * behind a batch's consumption. It stops at the issue; see the service.
     */
    public function trace(TraceStoreIssuesRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->issues->traceForItem(
            itemId: $request->integer('item_id'),
            asOf: $request->string('as_of')->toString() ?: null,
        )]);
    }

    public function show(StoreIssue $storeIssue): StoreIssueResource
    {
        return StoreIssueResource::make($storeIssue->load([
            'lines.item', 'bagScans.bag', 'bagScans.lot', 'issuedBy', 'receivedBy',
        ]));
    }

    public function store(StoreStoreIssueRequest $request): JsonResponse
    {
        $issue = $this->issues->issue($request->validated(), (int) $request->user()->id);

        return StoreIssueResource::make($issue->load(['lines.item', 'issuedBy', 'receivedBy']))
            ->response()
            ->setStatusCode(201);
    }

    public function scanBag(StoreStoreIssueBagScanRequest $request, StoreIssue $storeIssue): JsonResponse
    {
        $data = $request->validated();

        $scan = $this->issues->scanBag(
            issue: $storeIssue,
            barcode: $data['barcode'],
            quantityKg: isset($data['quantity_kg']) ? (string) $data['quantity_kg'] : null,
            issuedBy: (int) $request->user()->id,
            receivedBy: $data['received_by'] ?? null,
            materialRequestLineId: $data['material_request_line_id'] ?? null,
            notes: $data['notes'] ?? null,
        );

        return StoreIssueBagScanResource::make($scan->load(['bag', 'lot', 'issuedBy', 'receivedBy']))
            ->response()
            ->setStatusCode(201);
    }

    public function returnUnused(StoreStoreIssueReturnRequest $request, StoreIssue $storeIssue): StoreIssueResource
    {
        $data = $request->validated();

        $issue = $this->issues->returnUnused(
            issue: $storeIssue,
            lines: array_map(
                fn (array $line) => [
                    'store_issue_line_id' => (int) $line['store_issue_line_id'],
                    'quantity' => (string) $line['quantity'],
                    // Left as the raw value, null included: the service reads
                    // a missing state as `good` in ONE place, so this must not
                    // decide it a second time and risk the two disagreeing.
                    'quality_state' => $line['quality_state'] ?? null,
                ],
                $data['lines'],
            ),
            recordedBy: (int) $request->user()->id,
            notes: $data['notes'] ?? null,
        );

        return StoreIssueResource::make($issue->load(['lines.item', 'issuedBy', 'receivedBy']));
    }

    public function complete(Request $request, StoreIssue $storeIssue): StoreIssueResource
    {
        return StoreIssueResource::make(
            $this->issues->complete($storeIssue, (int) $request->user()->id)
                ->load(['lines.item', 'issuedBy', 'receivedBy']),
        );
    }

    public function cancel(CancelStoreIssueRequest $request, StoreIssue $storeIssue): StoreIssueResource
    {
        return StoreIssueResource::make(
            $this->issues->cancel($storeIssue, $request->validated()['reason'], (int) $request->user()->id)
                ->load(['lines.item', 'issuedBy', 'receivedBy']),
        );
    }
}
