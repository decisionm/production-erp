<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Controllers\Concerns\ManagesConfigurationRecords;
use App\Modules\Production\Http\Requests\ArchiveConfigurationRequest;
use App\Modules\Production\Http\Requests\StoreShiftRequest;
use App\Modules\Production\Http\Requests\UpdateShiftRequest;
use App\Modules\Production\Http\Resources\ShiftResource;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Services\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ShiftController extends Controller
{
    use ManagesConfigurationRecords;

    public function __construct(private readonly ShiftService $shifts) {}

    protected function configurationWritePermission(): string
    {
        return 'production.manage';
    }

    protected function configurationNoun(): string
    {
        return 'shift';
    }

    /**
     * `?active=1` restricts to shifts the factory currently runs — the
     * operational contract every picker and the dashboard rail consume.
     * Without the param the full set answers, retired rows included: the
     * admin screen and anything resolving history need those. Same shape
     * as WorkCenterController::index, deliberately.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $active = $request->has('active') ? $request->boolean('active') : null;

        $page = $this->shifts->paginate(activeOnly: $active);

        $this->withAbilitiesForEach($request, $this->shifts, $page->getCollection());

        return ShiftResource::collection($page);
    }

    public function show(Request $request, Shift $shift): ShiftResource
    {
        return ShiftResource::make($this->withAbilities($request, $this->shifts, $shift));
    }

    public function store(StoreShiftRequest $request): ShiftResource
    {
        return ShiftResource::make(
            $this->withAbilities($request, $this->shifts, $this->shifts->create($request->validated())),
        );
    }

    public function update(UpdateShiftRequest $request, Shift $shift): ShiftResource
    {
        return ShiftResource::make(
            $this->withAbilities($request, $this->shifts, $this->shifts->update($shift, $request->validated())),
        );
    }

    /**
     * Take a shift out of service. Reversible, and — DEC-20260817-002 §4 —
     * it touches NO Tally field: a Stock Journal already posted for this
     * shift keeps its identity and its mapping exactly as posted.
     */
    public function archive(ArchiveConfigurationRequest $request, Shift $shift): ShiftResource
    {
        return ShiftResource::make($this->withAbilities(
            $request,
            $this->shifts,
            $this->archiveRecord($this->shifts, $shift, $request->validated()['reason'] ?? null),
        ));
    }

    public function activate(ArchiveConfigurationRequest $request, Shift $shift): ShiftResource
    {
        return ShiftResource::make($this->withAbilities(
            $request,
            $this->shifts,
            $this->activateRecord($this->shifts, $shift, $request->validated()['reason'] ?? null),
        ));
    }

    public function destroy(Request $request, Shift $shift): Response
    {
        $this->shifts->delete($shift, $request->user());

        return response()->noContent();
    }
}
