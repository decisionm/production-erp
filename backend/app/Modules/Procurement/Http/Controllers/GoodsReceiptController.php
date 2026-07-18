<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\StoreGoodsReceiptRequest;
use App\Modules\Procurement\Http\Resources\GoodsReceiptNoteResource;
use App\Modules\Procurement\Services\GoodsReceiptService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GoodsReceiptController extends Controller
{
    public function __construct(private readonly GoodsReceiptService $receipts) {}

    public function index(): AnonymousResourceCollection
    {
        return GoodsReceiptNoteResource::collection($this->receipts->paginate());
    }

    public function store(StoreGoodsReceiptRequest $request): GoodsReceiptNoteResource
    {
        $grn = $this->receipts->create($request->validated(), $request->user()?->id);

        return GoodsReceiptNoteResource::make($grn);
    }
}
