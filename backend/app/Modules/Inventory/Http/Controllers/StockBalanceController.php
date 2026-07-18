<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Resources\StockBalanceResource;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockBalanceController extends Controller
{
    public function __construct(private readonly StockMovementService $stock) {}

    public function index(): AnonymousResourceCollection
    {
        return StockBalanceResource::collection($this->stock->paginateBalances());
    }
}
