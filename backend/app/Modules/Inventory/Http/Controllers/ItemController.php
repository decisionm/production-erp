<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StoreItemRequest;
use App\Modules\Inventory\Http\Requests\UpdateItemRequest;
use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ItemService;
use App\Support\Configuration\Concerns\ServesConfigurationLifecycle;
use App\Support\Configuration\Http\ConfigurationReasonRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The SKU master — materials and finished products alike — on the shared
 * configuration lifecycle. See WarehouseController for the reference shape
 * these six actions follow.
 */
class ItemController extends Controller
{
    use ServesConfigurationLifecycle;

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
    public function show(Request $request, Item $item): ItemResource
    {
        return ItemResource::make($this->withAbilities($item, $this->items, $request));
    }

    public function store(StoreItemRequest $request): ItemResource
    {
        return ItemResource::make($this->items->create($request->validated()));
    }

    public function update(UpdateItemRequest $request, Item $item): ItemResource
    {
        return ItemResource::make($this->items->update($item, $request->validated()));
    }

    /**
     * Hard delete — 422 with counts when anything references the item, 403
     * for anyone below the hard-delete tier. Both come out of the Service.
     */
    public function destroy(Request $request, Item $item): JsonResponse
    {
        $this->items->delete($item, $request->user());

        return response()->json(null, 204);
    }

    /** Take out of service. Reversible, deletes nothing, no Tally mutation. */
    public function archive(ConfigurationReasonRequest $request, Item $item): ItemResource
    {
        $archived = $this->runLifecycleAction(
            fn () => $this->items->archive($item, $request->reason()),
        );

        return ItemResource::make($this->withAbilities($archived, $this->items, $request));
    }

    /** Put an archived item back in service. */
    public function activate(ConfigurationReasonRequest $request, Item $item): ItemResource
    {
        $activated = $this->runLifecycleAction(
            fn () => $this->items->activate($item, $request->reason()),
        );

        return ItemResource::make($this->withAbilities($activated, $this->items, $request));
    }
}
