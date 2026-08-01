<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Resources\SalesCostInsightResource;
use App\Modules\Sales\Models\SalesOrder;

/**
 * READ-ONLY, and that is a guarantee rather than a description: this
 * controller has one GET, it calls one service that writes nothing, and a
 * test asserts no insert, update or delete statement fires across the call.
 * Sales orders have never touched stock and this endpoint does not begin.
 */
class SalesCostInsightController extends Controller
{
    public function show(SalesOrder $salesOrder): SalesCostInsightResource
    {
        return SalesCostInsightResource::make(
            $salesOrder->load(['customer', 'lines.item' => fn ($query) => $query->withTrashed()]),
        );
    }
}
