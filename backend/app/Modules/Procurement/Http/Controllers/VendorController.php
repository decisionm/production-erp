<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ListVendorsRequest;
use App\Modules\Procurement\Http\Requests\StoreVendorRequest;
use App\Modules\Procurement\Http\Requests\UpdateVendorRequest;
use App\Modules\Procurement\Http\Resources\VendorResource;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\VendorService;
use App\Support\Configuration\Concerns\ServesConfigurationLifecycle;
use App\Support\Configuration\Http\ConfigurationReasonRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The vendor master, on the Configuration Lifecycle Contract
 * (DEC-20260817-002; docs/engineering/CONFIGURATION-LIFECYCLE-WIRING.md).
 * Shape copied verbatim from WarehouseController, the named reference.
 *
 * Until now `is_active` existed on the table but the ONLY way to flip it was
 * a plain `update` carrying the whole record — no reason captured, no audit
 * entry naming what changed, and no dependency guard, so a vendor with live
 * purchase orders could be switched off with nothing recorded but the row's
 * new value. Vendor and Customer were the last two masters in that state.
 */
class VendorController extends Controller
{
    use ServesConfigurationLifecycle;

    public function __construct(private readonly VendorService $vendors) {}

    /**
     * The vendor list. `q` narrows it by name or code — 628 vendors arrived
     * from the Tally ledger import in one run, and a list that size without a
     * search is thirteen screens and no way in. `classification[]` narrows to
     * vendors holding at least one of the given values (DEC-20260902-026);
     * `unclassified=1` widens that to also include vendors with none, or —
     * with no `classification[]` — narrows to only those.
     */
    public function index(ListVendorsRequest $request): AnonymousResourceCollection
    {
        $search = $request->query('q');
        $classifications = $request->query('classification');
        $classifications = is_array($classifications) ? array_values(array_filter($classifications, 'is_string')) : null;

        return VendorResource::collection(
            $this->vendors->paginate(
                $this->perPage($request),
                is_string($search) ? $search : null,
                $classifications,
                $request->boolean('unclassified'),
                $request->sort(),
            ),
        );
    }

    /**
     * One vendor by id — the only read where `can.delete` is answered for
     * real. The confirm dialog fetches this before offering Delete.
     */
    public function show(Request $request, Vendor $vendor): VendorResource
    {
        return VendorResource::make($this->withAbilities($vendor->load('classifications'), $this->vendors, $request));
    }

    public function store(StoreVendorRequest $request): VendorResource
    {
        return VendorResource::make($this->vendors->create($request->validated())->load('classifications'));
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): VendorResource
    {
        return VendorResource::make($this->vendors->update($vendor, $request->validated())->load('classifications'));
    }

    /**
     * Hard delete — 422 with counts when a purchase order or subcontract
     * order references the vendor, 403 for anyone below the hard-delete tier.
     * Both refusals come out of the Service; nothing is decided here.
     */
    public function destroy(Request $request, Vendor $vendor): JsonResponse
    {
        $this->vendors->delete($vendor, $request->user());

        return response()->json(null, 204);
    }

    /**
     * Take out of service. Reversible, deletes nothing, and mutates NOTHING
     * in Tally — a vendor's tally_ledger_name is a name Accounts typed in,
     * not an identity the ERP resolves (DEC-20260817-002 rule 4).
     */
    public function archive(ConfigurationReasonRequest $request, Vendor $vendor): VendorResource
    {
        $archived = $this->runLifecycleAction(
            fn () => $this->vendors->archive($vendor, $request->reason()),
        );

        return VendorResource::make($this->withAbilities($archived->load('classifications'), $this->vendors, $request));
    }

    /** Put an archived vendor back in service. */
    public function activate(ConfigurationReasonRequest $request, Vendor $vendor): VendorResource
    {
        $activated = $this->runLifecycleAction(
            fn () => $this->vendors->activate($vendor, $request->reason()),
        );

        return VendorResource::make($this->withAbilities($activated->load('classifications'), $this->vendors, $request));
    }
}
