<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Http\Resources\ProductVariantsResource;
use App\Modules\Production\Services\ProductVariantService;

/**
 * GET production/products/{item}/variants — one product's variant tree with
 * each packaging's Tally identity and configuration status (P5-02).
 *
 * Read-only; production.view suffices (the group's module guard). A
 * soft-deleted item 404s through route-model binding, as the item show
 * endpoint does.
 */
class ProductVariantController extends Controller
{
    public function __construct(private readonly ProductVariantService $variants) {}

    public function __invoke(Item $item): ProductVariantsResource
    {
        return ProductVariantsResource::make($this->variants->tree($item));
    }
}
