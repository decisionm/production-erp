<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ListGoodsReceiptsRequest;
use App\Modules\Procurement\Http\Requests\StoreGoodsReceiptRequest;
use App\Modules\Procurement\Http\Resources\GoodsReceiptNoteResource;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\ProcurementDocumentQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsReceiptService $receipts,
        private readonly ProcurementDocumentQuery $query,
    ) {}

    /**
     * The list, filtered by ListGoodsReceiptsRequest (Phase 4.5); an empty
     * query string is the same unfiltered, newest-first list as before.
     * `per_page` up to 1000 so a link that points at one older receipt can
     * actually find it — the default first page of 20 would hide it.
     */
    public function index(ListGoodsReceiptsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return GoodsReceiptNoteResource::collection($this->receipts->paginate($this->query->perPage($filters), $filters))
            // WHETHER THIS DEPLOYMENT CAPTURES LOTS AND BAGS, served to the
            // screen that captures them. The receiving form used to read this
            // from /production/settings, which sits behind the production
            // module — so a storekeeper holding procurement and not production
            // got a 403, the page read that as "traceability off", and every
            // receipt they booked landed with no bags and therefore no
            // incoming-QC hold. The same screen, the same deployment, a
            // different answer depending on who was looking at it.
            //
            // It is deployment config and names nothing about the reader, so
            // serving it here reveals nothing. Added as a TOP-LEVEL key, not
            // into `meta`, which belongs to the paginator: overwriting that
            // would take the register's page count with it.
            ->additional(['traceability_enabled' => (bool) config('production.traceability_enabled', true)]);
    }

    /** One receipt with its lines, lots, bags and Receipt Note link (Phase 6, P6-02). */
    public function show(GoodsReceiptNote $goodsReceipt): GoodsReceiptNoteResource
    {
        return GoodsReceiptNoteResource::make($this->receipts->show($goodsReceipt));
    }

    public function store(StoreGoodsReceiptRequest $request): GoodsReceiptNoteResource
    {
        $grn = $this->receipts->create($request->validated(), $request->user()?->id);

        return GoodsReceiptNoteResource::make($grn);
    }
}
