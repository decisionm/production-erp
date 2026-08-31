<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\TallySync\Services\AgentIdentity;
use App\Modules\TallySync\Services\PurchaseRateLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WHAT TALLY SAYS THIS VENDOR LAST CHARGED FOR THIS ITEM — the purchase-order
 * form's lookup.
 *
 * A read and only a read. It quotes vouchers the factory already has; it
 * creates nothing, posts nothing, and the existing approved workflows go on
 * handling voucher posting untouched.
 *
 * FC-06 IS THE WHOLE ACCESS STORY. A purchase rate is Owner/Accounts only, and
 * so is the supplier identity this answer carries with it, so the route sits
 * behind `module:finance` (the gate supplier bills already use, for the same
 * reason) and the controller re-asks the module's single FC-06 predicate on
 * top. A floor or sales login is refused the whole answer rather than served a
 * thinned one — a rate lookup with the rates removed is not a lesser view of
 * this, it is nothing.
 */
class VendorItemRateController extends Controller
{
    public function __construct(private readonly PurchaseRateLookup $rates) {}

    public function show(Request $request, PurchaseRateLookup $lookup): JsonResponse
    {
        abort_unless(
            AgentIdentity::mayReadPurchaseDetails($request->user()),
            403,
            'Purchase rates are Owner/Accounts only (FC-06).',
        );

        $validated = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
        ]);

        $vendor = Vendor::findOrFail($validated['vendor_id']);
        $item = Item::findOrFail($validated['item_id']);

        return response()->json(['data' => $this->rates->forVendorAndItem($vendor, $item)]);
    }
}
