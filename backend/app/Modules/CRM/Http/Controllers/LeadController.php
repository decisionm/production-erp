<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Http\Requests\ConvertLeadRequest;
use App\Modules\CRM\Http\Requests\ListLeadsRequest;
use App\Modules\CRM\Http\Requests\StoreLeadRequest;
use App\Modules\CRM\Http\Requests\UpdateLeadRequest;
use App\Modules\CRM\Http\Resources\LeadResource;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeadController extends Controller
{
    public function __construct(private readonly LeadService $leads) {}

    public function index(ListLeadsRequest $request): AnonymousResourceCollection
    {
        return LeadResource::collection($this->leads->paginate(
            (int) ($request->validated('per_page') ?? 20),
            $request->validated('sort'),
        ));
    }

    public function store(StoreLeadRequest $request): LeadResource
    {
        return LeadResource::make($this->leads->create($request->validated()));
    }

    public function update(UpdateLeadRequest $request, Lead $lead): LeadResource
    {
        return LeadResource::make($this->leads->update($lead, $request->validated()));
    }

    public function convert(ConvertLeadRequest $request, Lead $lead): LeadResource
    {
        return LeadResource::make($this->leads->convert($lead, $request->validated()['code']));
    }
}
