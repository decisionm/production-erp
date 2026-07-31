<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StoreItemRequest;
use App\Modules\Inventory\Http\Requests\UpdateItemRequest;
use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ItemService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    public function __construct(private readonly ItemService $items) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ItemResource::collection($this->items->paginate($this->perPage($request)));
    }

    /**
     * One item by id — what the item detail page loads. Route-model binding
     * resolves it (a soft-deleted item 404s, matching the index list), the
     * same shape MaterialLotController/BatchController already use.
     */
    public function show(Item $item): ItemResource
    {
        return ItemResource::make($item);
    }

    public function store(StoreItemRequest $request): ItemResource
    {
        return ItemResource::make($this->items->create($request->validated()));
    }

    public function update(UpdateItemRequest $request, Item $item): ItemResource
    {
        return ItemResource::make($this->items->update($item, $request->validated()));
    }
}
