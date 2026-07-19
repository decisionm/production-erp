<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Http\Requests\StoreLeadActivityRequest;
use App\Modules\CRM\Http\Resources\LeadActivityResource;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadActivityService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeadActivityController extends Controller
{
    public function __construct(private readonly LeadActivityService $activities) {}

    public function index(Lead $lead): AnonymousResourceCollection
    {
        return LeadActivityResource::collection($this->activities->listForLead($lead));
    }

    public function store(StoreLeadActivityRequest $request, Lead $lead): LeadActivityResource
    {
        return LeadActivityResource::make(
            $this->activities->create($lead, $request->validated(), $request->user()?->id)
        );
    }
}
