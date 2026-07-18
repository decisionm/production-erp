<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\StoreVendorRequest;
use App\Modules\Procurement\Http\Requests\UpdateVendorRequest;
use App\Modules\Procurement\Http\Resources\VendorResource;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\VendorService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VendorController extends Controller
{
    public function __construct(private readonly VendorService $vendors) {}

    public function index(): AnonymousResourceCollection
    {
        return VendorResource::collection($this->vendors->paginate());
    }

    public function store(StoreVendorRequest $request): VendorResource
    {
        return VendorResource::make($this->vendors->create($request->validated()));
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): VendorResource
    {
        return VendorResource::make($this->vendors->update($vendor, $request->validated()));
    }
}
