<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StoreDowntimeReasonRequest;
use App\Modules\Production\Http\Resources\DowntimeReasonResource;
use App\Modules\Production\Models\DowntimeReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DowntimeReasonController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return DowntimeReasonResource::collection(
            DowntimeReason::query()
                ->when($request->boolean('selectable_at_start'), fn ($q) => $q->where('selectable_at_start', true)->where('is_active', true))
                ->orderBy('planning_type')
                ->orderBy('code')
                ->get(),
        );
    }

    public function store(StoreDowntimeReasonRequest $request): DowntimeReasonResource
    {
        return DowntimeReasonResource::make(DowntimeReason::create($request->validated()));
    }

    public function update(StoreDowntimeReasonRequest $request, DowntimeReason $downtimeReason): DowntimeReasonResource
    {
        $downtimeReason->update($request->validated());

        return DowntimeReasonResource::make($downtimeReason->fresh());
    }
}
