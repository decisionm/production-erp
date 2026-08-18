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

        return GoodsReceiptNoteResource::collection($this->receipts->paginate($this->query->perPage($filters), $filters));
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
