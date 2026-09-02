<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ListStockBalancesRequest;
use App\Modules\Inventory\Http\Resources\StockBalanceResource;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockStateReader;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockBalanceController extends Controller
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly StockStateReader $state,
    ) {}

    /**
     * Took NO parameters at all until now: twenty rows, always, out of every
     * item×warehouse balance the factory holds, with nothing on screen saying
     * a row was missing. All three are optional, so the bare URL answers
     * exactly as it did.
     *
     * `item_id` is what an item's own detail page reads. It used to fetch this
     * list unfiltered and pick its rows out of the first twenty client-side,
     * so past twenty balances an item's own page stopped showing its stock.
     *
     * The query string is ListStockBalancesRequest's: `q` (and its older
     * spelling `search`) is the needle, beside the warehouse filter and the
     * sort. `item_id` and `per_page` keep the base-controller readers every
     * inventory list shares, so their tolerances are unchanged.
     */
    public function index(ListStockBalancesRequest $request): AnonymousResourceCollection
    {
        $balances = $this->stock->paginateBalances(
            perPage: $this->perPage($request),
            search: $request->needle(),
            itemId: $this->filterId($request, 'item_id'),
            warehouseId: $request->warehouseId(),
            sort: $request->sort(),
            direction: $request->direction(),
        );

        // The four figures, for THIS PAGE only and in two queries — not one
        // pair at a time. The reader takes no locks: the authority for a WRITE
        // stays inside the writer's own transaction.
        $state = $this->state->forRows(
            $balances->getCollection()
                ->map(fn ($row) => [
                    'item_id' => (int) $row->item_id,
                    'warehouse_id' => (int) $row->warehouse_id,
                    'quantity' => (string) $row->quantity,
                ])
                ->all()
        );

        $balances->getCollection()->transform(function ($row) use ($state) {
            $row->stock_state = $state[((int) $row->item_id).'|'.((int) $row->warehouse_id)] ?? null;

            return $row;
        });

        return StockBalanceResource::collection($balances);
    }
}
