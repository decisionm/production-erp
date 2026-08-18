<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Controllers\Concerns\ManagesConfigurationRecords;
use App\Modules\Production\Http\Requests\ArchiveConfigurationRequest;
use App\Modules\Production\Http\Requests\StoreWorkCenterRequest;
use App\Modules\Production\Http\Requests\UpdateWorkCenterRequest;
use App\Modules\Production\Http\Resources\WorkCenterResource;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\WorkCenterService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class WorkCenterController extends Controller
{
    use ManagesConfigurationRecords;

    public function __construct(private readonly WorkCenterService $workCenters) {}

    /**
     * THE SPLIT, NAMED WHERE THE `can` IS BUILT. Reading the machine list is
     * `production.view`; changing what a machine IS — including archiving
     * it — is `machine-master.manage`, its own catalog module, because the
     * two audiences differ (routes/api.php). So a supervisor's list comes
     * back with every action false instead of with buttons that 403.
     */
    protected function configurationWritePermission(): string
    {
        return 'machine-master.manage';
    }

    protected function configurationNoun(): string
    {
        return 'machine';
    }

    /**
     * `?active=1` restricts to machines currently in service — what every
     * production selector must send, so a retired machine cannot be picked.
     * `?active=0` lists the retired ones for the admin screen's filter.
     * Omitted returns both.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $active = $request->has('active') ? $request->boolean('active') : null;

        $page = $this->workCenters->paginate(perPage: 100, activeOnly: $active);

        $this->withAbilitiesForEach($request, $this->workCenters, $page->getCollection());

        return WorkCenterResource::collection($page);
    }

    /**
     * One machine, with the AUTHORITATIVE `can` — the dependency sweep is
     * paid here and only here, which is what lets index() serve
     * `delete: null` and the confirm dialog ask this endpoint first.
     */
    public function show(Request $request, WorkCenter $workCenter): WorkCenterResource
    {
        return WorkCenterResource::make(
            $this->withAbilities($request, $this->workCenters, $workCenter),
        );
    }

    public function store(StoreWorkCenterRequest $request): WorkCenterResource
    {
        return WorkCenterResource::make(
            $this->withAbilities($request, $this->workCenters, $this->workCenters->create($request->validated())),
        );
    }

    public function update(UpdateWorkCenterRequest $request, WorkCenter $workCenter): WorkCenterResource
    {
        return WorkCenterResource::make(
            $this->withAbilities($request, $this->workCenters, $this->workCenters->update($workCenter, $request->validated())),
        );
    }

    /** Take a machine out of service. Reversible; deletes nothing. */
    public function archive(ArchiveConfigurationRequest $request, WorkCenter $workCenter): WorkCenterResource
    {
        return WorkCenterResource::make($this->withAbilities(
            $request,
            $this->workCenters,
            $this->archiveRecord($this->workCenters, $workCenter, $request->validated()['reason'] ?? null),
        ));
    }

    /** Put an archived machine back in service. */
    public function activate(ArchiveConfigurationRequest $request, WorkCenter $workCenter): WorkCenterResource
    {
        return WorkCenterResource::make($this->withAbilities(
            $request,
            $this->workCenters,
            $this->activateRecord($this->workCenters, $workCenter, $request->validated()['reason'] ?? null),
        ));
    }

    /**
     * Hard delete — Super Admin / Owner only, and only for a machine proven
     * never used. The service refuses everything else: 403 without the
     * tier, 422 with counts when anything references it.
     */
    public function destroy(Request $request, WorkCenter $workCenter): Response
    {
        $this->workCenters->delete($workCenter, $request->user());

        return response()->noContent();
    }
}
