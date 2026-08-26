<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ListItemWarningsRequest;
use App\Modules\Inventory\Http\Resources\ItemIdentityHealthResource;
use App\Modules\Inventory\Http\Resources\ItemIdentityResource;
use App\Modules\Inventory\Services\ItemIdentityService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * THE ITEM MASTER'S IDENTITY REVIEW — two READS, and nothing else.
 *
 * There is no write action on this controller and there must not be one:
 * every answer it gives is a WARNING about an open question (Q43, Q59, Q60),
 * and an endpoint that could act on one would be this repo deciding a
 * factory question for the owner. Reclassifying an item is what
 * `PUT /inventory/items/{item}` is for, with a person choosing.
 */
class ItemIdentityController extends Controller
{
    public function __construct(private readonly ItemIdentityService $identity) {}

    /** Counts per warning class — the item list's badge row. */
    public function health(): ItemIdentityHealthResource
    {
        return ItemIdentityHealthResource::make($this->identity->health());
    }

    /** The items behind one badge, or behind all of them when no class is named. */
    public function items(ListItemWarningsRequest $request): AnonymousResourceCollection
    {
        return ItemIdentityResource::collection($this->identity->itemsWithWarnings(
            $request->validated('warning'),
            (int) ($request->validated('per_page') ?? ItemIdentityService::PER_PAGE_DEFAULT),
        ));
    }
}
