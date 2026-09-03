<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Controllers\Concerns\ManagesConfigurationRecords;
use App\Modules\Production\Http\Requests\ArchiveConfigurationRequest;
use App\Modules\Production\Http\Requests\ListMoldsRequest;
use App\Modules\Production\Http\Requests\StoreMoldRequest;
use App\Modules\Production\Http\Requests\UpdateMoldRequest;
use App\Modules\Production\Http\Resources\MoldResource;
use App\Modules\Production\Models\Mold;
use App\Modules\Production\Services\MoldService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MoldController extends Controller
{
    use ManagesConfigurationRecords;

    public function __construct(private readonly MoldService $molds) {}

    protected function configurationWritePermission(): string
    {
        return 'production.manage';
    }

    protected function configurationNoun(): string
    {
        return 'mould';
    }

    /**
     * `?active=1` restricts to moulds in service. `?active=0` is everything
     * NOT in service — retired AND under repair, which are two different
     * states of a three-case status and not each other's complement (see
     * MoldService::configurationActiveColumn). Omitted returns the master.
     */
    public function index(ListMoldsRequest $request): AnonymousResourceCollection
    {
        $active = $request->has('active') ? $request->boolean('active') : null;

        $page = $this->molds->paginate($request->perPage(), $active, $request->sort());

        $this->withAbilitiesForEach($request, $this->molds, $page->getCollection());

        return MoldResource::collection($page);
    }

    public function show(Request $request, Mold $mold): MoldResource
    {
        return MoldResource::make($this->withAbilities($request, $this->molds, $mold));
    }

    public function store(StoreMoldRequest $request): MoldResource
    {
        return MoldResource::make(
            $this->withAbilities($request, $this->molds, $this->molds->create($request->validated())),
        );
    }

    public function update(UpdateMoldRequest $request, Mold $mold): MoldResource
    {
        return MoldResource::make(
            $this->withAbilities($request, $this->molds, $this->molds->update($mold, $request->validated())),
        );
    }

    /** Retire a mould — writes the RETIRED case of its status enum, never `false`. */
    public function archive(ArchiveConfigurationRequest $request, Mold $mold): MoldResource
    {
        return MoldResource::make($this->withAbilities(
            $request,
            $this->molds,
            $this->archiveRecord($this->molds, $mold, $request->validated()['reason'] ?? null),
        ));
    }

    /**
     * Put a mould back in service. Offered for a RETIRED mould and for one
     * UNDER REPAIR alike: under repair is not active, so activating it is a
     * real transition, and it is not retired either, so Archive stays
     * offered beside it.
     */
    public function activate(ArchiveConfigurationRequest $request, Mold $mold): MoldResource
    {
        return MoldResource::make($this->withAbilities(
            $request,
            $this->molds,
            $this->activateRecord($this->molds, $mold, $request->validated()['reason'] ?? null),
        ));
    }

    public function destroy(Request $request, Mold $mold): Response
    {
        $this->molds->delete($mold, $request->user());

        return response()->noContent();
    }
}
