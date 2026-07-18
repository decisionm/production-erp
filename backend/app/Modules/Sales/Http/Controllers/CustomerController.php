<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\StoreCustomerRequest;
use App\Modules\Sales\Http\Requests\UpdateCustomerRequest;
use App\Modules\Sales\Http\Resources\CustomerResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(): AnonymousResourceCollection
    {
        return CustomerResource::collection($this->customers->paginate());
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
