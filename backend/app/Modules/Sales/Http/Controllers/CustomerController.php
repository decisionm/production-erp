<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\StoreCustomerRequest;
use App\Modules\Sales\Http\Requests\UpdateCustomerRequest;
use App\Modules\Sales\Http\Resources\CustomerResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    /**
     * Honours per_page, clamped. It previously ignored the request entirely
     * and always returned 20 — fine for a handful of demo rows, misleading
     * once the ledger import puts the factory's real customer master behind
     * it. Clamped at 200 so a client cannot ask the server to build the whole
     * list in one response.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // is_numeric first: (int) 'abc' is 0, which the clamp turned into ONE
        // row per page rather than falling back to the documented default.
        $raw = $request->query('per_page');
        $perPage = is_numeric($raw) ? max(1, min(200, (int) $raw)) : 20;

        return CustomerResource::collection($this->customers->paginate($perPage));
    }

    public function store(StoreCustomerRequest $request): CustomerResource
    {
        return CustomerResource::make($this->customers->create($request->validated()));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        return CustomerResource::make($this->customers->update($customer, $request->validated()));
    }
}
