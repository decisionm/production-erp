<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Controllers\Concerns\ManagesConfigurationRecords;
use App\Modules\Production\Http\Requests\ArchiveConfigurationRequest;
use App\Modules\Production\Http\Requests\StoreDowntimeReasonRequest;
use App\Modules\Production\Http\Resources\DowntimeReasonResource;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Services\DowntimeReasonService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DowntimeReasonController extends Controller
{
    use ManagesConfigurationRecords;

    public function __construct(private readonly DowntimeReasonService $downtimeReasons) {}

    protected function configurationWritePermission(): string
    {
        return 'production.manage';
    }

    protected function configurationNoun(): string
    {
        return 'downtime reason';
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $reasons = $this->downtimeReasons->list($request->boolean('selectable_at_start'));

        $this->withAbilitiesForEach($request, $this->downtimeReasons, $reasons);

        return DowntimeReasonResource::collection($reasons);
    }

    public function show(Request $request, DowntimeReason $downtimeReason): DowntimeReasonResource
    {
        return DowntimeReasonResource::make(
            $this->withAbilities($request, $this->downtimeReasons, $downtimeReason),
        );
    }

    public function store(StoreDowntimeReasonRequest $request): DowntimeReasonResource
    {
        return DowntimeReasonResource::make($this->withAbilities(
            $request,
            $this->downtimeReasons,
            $this->downtimeReasons->create($request->validated()),
        ));
    }

    public function update(StoreDowntimeReasonRequest $request, DowntimeReason $downtimeReason): DowntimeReasonResource
    {
        return DowntimeReasonResource::make($this->withAbilities(
            $request,
            $this->downtimeReasons,
            $this->downtimeReasons->update($downtimeReason, $request->validated()),
        ));
    }

    /**
     * Withdraw a downtime reason. `downtime_reasons` carries no
     * `deleted_at` and does not need one: it has an `is_active` flag, and
     * ConfigurationLifecycle::archive() takes the active-flag branch first
     * and returns — the soft-delete branch is only ever reached by a master
     * that has NO flag at all. See DowntimeReasonService.
     */
    public function archive(ArchiveConfigurationRequest $request, DowntimeReason $downtimeReason): DowntimeReasonResource
    {
        return DowntimeReasonResource::make($this->withAbilities(
            $request,
            $this->downtimeReasons,
            $this->archiveRecord($this->downtimeReasons, $downtimeReason, $request->validated()['reason'] ?? null),
        ));
    }

    public function activate(ArchiveConfigurationRequest $request, DowntimeReason $downtimeReason): DowntimeReasonResource
    {
        return DowntimeReasonResource::make($this->withAbilities(
            $request,
            $this->downtimeReasons,
            $this->activateRecord($this->downtimeReasons, $downtimeReason, $request->validated()['reason'] ?? null),
        ));
    }

    public function destroy(Request $request, DowntimeReason $downtimeReason): Response
    {
        $this->downtimeReasons->delete($downtimeReason, $request->user());

        return response()->noContent();
    }
}
