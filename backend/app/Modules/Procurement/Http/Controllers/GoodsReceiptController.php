<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\StoreGoodsReceiptRequest;
use App\Modules\Procurement\Http\Resources\GoodsReceiptNoteResource;
use App\Modules\Procurement\Services\GoodsReceiptService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GoodsReceiptController extends Controller
{
    public function __construct(private readonly GoodsReceiptService $receipts) {}

    /**
     * `per_page` so a link that points at one older receipt can actually find
     * it — the default first page of 20 would hide it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return GoodsReceiptNoteResource::collection($this->receipts->paginate($this->perPage($request)));
    }

    public function store(StoreGoodsReceiptRequest $request): GoodsReceiptNoteResource
    {
        $grn = $this->receipts->create($request->validated(), $request->user()?->id);

        return GoodsReceiptNoteResource::make($grn);
    }
}
