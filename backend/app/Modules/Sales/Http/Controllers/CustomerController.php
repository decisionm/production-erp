<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\ListCustomersRequest;
use App\Modules\Sales\Http\Requests\StoreCustomerRequest;
use App\Modules\Sales\Http\Requests\UpdateCustomerRequest;
use App\Modules\Sales\Http\Resources\CustomerResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerService;
use App\Support\Configuration\Concerns\ServesConfigurationLifecycle;
use App\Support\Configuration\Http\ConfigurationReasonRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    use ServesConfigurationLifecycle;

    public function __construct(private readonly CustomerService $customers) {}

    /**
     * Honours per_page, clamped. It previously ignored the request entirely
     * and always returned 20 — fine for a handful of demo rows, misleading
     * once the ledger import puts the factory's real customer master behind
     * it. Clamped at 200 so a client cannot ask the server to build the whole
     * list in one response.
     */
    public function index(ListCustomersRequest $request): AnonymousResourceCollection
    {
        // is_numeric first: (int) 'abc' is 0, which the clamp turned into ONE
        // row per page rather than falling back to the documented default.
        $raw = $request->query('per_page');
        $perPage = is_numeric($raw) ? max(1, min(200, (int) $raw)) : 20;

        return CustomerResource::collection($this->customers->paginate($perPage, $request->sort()));
    }

    public function store(StoreCustomerRequest $request): CustomerResource
    {
        return CustomerResource::make($this->customers->create($request->validated()));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        return CustomerResource::make($this->customers->update($customer, $request->validated()));
    }

    /**
     * One customer by id — the only read where `can.delete` is answered for
     * real. The confirm dialog fetches this before offering Delete.
     */
    public function show(Request $request, Customer $customer): CustomerResource
    {
        return CustomerResource::make($this->withAbilities($customer, $this->customers, $request));
    }

    /**
     * Hard delete — 422 with counts when an order, invoice, opportunity,
     * quotation or converted lead references the customer, 403 below the
     * hard-delete tier. Both refusals come out of the Service.
     */
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->customers->delete($customer, $request->user());

        return response()->json(null, 204);
    }

    /** Take out of service. Reversible, deletes nothing, no Tally mutation. */
    public function archive(ConfigurationReasonRequest $request, Customer $customer): CustomerResource
    {
        $archived = $this->runLifecycleAction(
            fn () => $this->customers->archive($customer, $request->reason()),
        );

        return CustomerResource::make($this->withAbilities($archived, $this->customers, $request));
    }

    /** Put an archived customer back in service. */
    public function activate(ConfigurationReasonRequest $request, Customer $customer): CustomerResource
    {
        $activated = $this->runLifecycleAction(
            fn () => $this->customers->activate($customer, $request->reason()),
        );

        return CustomerResource::make($this->withAbilities($activated, $this->customers, $request));
    }
}
