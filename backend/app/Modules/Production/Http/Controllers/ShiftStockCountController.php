<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StoreShiftStockCountRequest;
use App\Modules\Production\Http\Resources\ShiftStockCountResource;
use App\Modules\Production\Services\ShiftStockCountService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShiftStockCountController extends Controller
{
    public function __construct(private readonly ShiftStockCountService $stockCounts) {}

    public function index(): AnonymousResourceCollection
    {
        return ShiftStockCountResource::collection($this->stockCounts->paginate());
    }

    public function store(StoreShiftStockCountRequest $request): ShiftStockCountResource
    {
        return ShiftStockCountResource::make(
            $this->stockCounts->create($request->validated(), $request->user()?->id),
        );
    }
}
