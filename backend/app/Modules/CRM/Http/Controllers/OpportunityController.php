<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Http\Requests\ListOpportunitiesRequest;
use App\Modules\CRM\Http\Requests\StoreOpportunityRequest;
use App\Modules\CRM\Http\Requests\UpdateOpportunityRequest;
use App\Modules\CRM\Http\Resources\OpportunityResource;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\CRM\Services\OpportunityService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OpportunityController extends Controller
{
    public function __construct(private readonly OpportunityService $opportunities) {}

    public function index(ListOpportunitiesRequest $request): AnonymousResourceCollection
    {
        return OpportunityResource::collection($this->opportunities->paginate(
            (int) ($request->validated('per_page') ?? 20),
            $request->validated('sort'),
        ));
    }

    public function store(StoreOpportunityRequest $request): OpportunityResource
    {
        return OpportunityResource::make($this->opportunities->create($request->validated()));
    }

    public function update(UpdateOpportunityRequest $request, Opportunity $opportunity): OpportunityResource
    {
        return OpportunityResource::make($this->opportunities->update($opportunity, $request->validated()));
    }
}
