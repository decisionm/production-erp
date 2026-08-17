<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Controllers\Concerns\ManagesConfigurationRecords;
use App\Modules\Production\Http\Requests\ArchiveConfigurationRequest;
use App\Modules\Production\Http\Requests\StoreScrapReasonRequest;
use App\Modules\Production\Http\Requests\UpdateScrapReasonRequest;
use App\Modules\Production\Http\Resources\ScrapReasonResource;
use App\Modules\Production\Models\ScrapReason;
use App\Modules\Production\Services\ScrapReasonService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ScrapReasonController extends Controller
{
    use ManagesConfigurationRecords;

    public function __construct(private readonly ScrapReasonService $scrapReasons) {}

    protected function configurationWritePermission(): string
    {
        return 'production.manage';
    }

    protected function configurationNoun(): string
    {
        return 'scrap reason';
    }

    /** `?active=1` is the completion screen's contract; omitted is the master. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $active = $request->has('active') ? $request->boolean('active') : null;

        $page = $this->scrapReasons->paginate($this->perPage($request), $active);

        $this->withAbilitiesForEach($request, $this->scrapReasons, $page->getCollection());

        return ScrapReasonResource::collection($page);
    }

    public function show(Request $request, ScrapReason $scrapReason): ScrapReasonResource
    {
        return ScrapReasonResource::make($this->withAbilities($request, $this->scrapReasons, $scrapReason));
    }

    public function store(StoreScrapReasonRequest $request): ScrapReasonResource
    {
        return ScrapReasonResource::make(
            $this->withAbilities($request, $this->scrapReasons, $this->scrapReasons->create($request->validated())),
        );
    }

    public function update(UpdateScrapReasonRequest $request, ScrapReason $scrapReason): ScrapReasonResource
    {
        return ScrapReasonResource::make($this->withAbilities(
            $request,
            $this->scrapReasons,
            $this->scrapReasons->update($scrapReason, $request->validated()),
        ));
    }

    public function archive(ArchiveConfigurationRequest $request, ScrapReason $scrapReason): ScrapReasonResource
    {
        return ScrapReasonResource::make($this->withAbilities(
            $request,
            $this->scrapReasons,
            $this->archiveRecord($this->scrapReasons, $scrapReason, $request->validated()['reason'] ?? null),
        ));
    }

    public function activate(ArchiveConfigurationRequest $request, ScrapReason $scrapReason): ScrapReasonResource
    {
        return ScrapReasonResource::make($this->withAbilities(
            $request,
            $this->scrapReasons,
            $this->activateRecord($this->scrapReasons, $scrapReason, $request->validated()['reason'] ?? null),
        ));
    }

    public function destroy(Request $request, ScrapReason $scrapReason): Response
    {
        $this->scrapReasons->delete($scrapReason, $request->user());

        return response()->noContent();
    }
}
