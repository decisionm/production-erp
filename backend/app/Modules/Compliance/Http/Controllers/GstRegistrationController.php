<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Http\Requests\StoreGstRegistrationRequest;
use App\Modules\Compliance\Http\Requests\UpdateGstRegistrationRequest;
use App\Modules\Compliance\Http\Resources\GstRegistrationResource;
use App\Modules\Compliance\Models\GstRegistration;
use App\Modules\Compliance\Services\GstRegistrationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GstRegistrationController extends Controller
{
    public function __construct(private readonly GstRegistrationService $registrations) {}

    public function index(): AnonymousResourceCollection
    {
        return GstRegistrationResource::collection($this->registrations->paginate());
    }

    public function store(StoreGstRegistrationRequest $request): GstRegistrationResource
    {
        return GstRegistrationResource::make($this->registrations->create($request->validated()));
    }

    public function update(UpdateGstRegistrationRequest $request, GstRegistration $gstRegistration): GstRegistrationResource
    {
        return GstRegistrationResource::make($this->registrations->update($gstRegistration, $request->validated()));
    }
}
