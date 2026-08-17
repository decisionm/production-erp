<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Controllers\Concerns\ManagesConfigurationRecords;
use App\Modules\Production\Http\Requests\ImportProductionConfigurationsRequest;
use App\Modules\Production\Http\Requests\StoreProductionConfigurationRequest;
use App\Modules\Production\Http\Resources\ProductionConfigurationResource;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Services\ConfigurationImportService;
use App\Modules\Production\Services\ProductionConfigurationService;
use App\Support\Configuration\Http\ConfigurationReasonRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductionConfigurationController extends Controller
{
    use ManagesConfigurationRecords;

    /** Configuration writes sit in the production group, not the machine master's. */
    protected function configurationWritePermission(): string
    {
        return 'production.manage';
    }

    protected function configurationNoun(): string
    {
        return 'production configuration';
    }

    public function __construct(
        private readonly ProductionConfigurationService $configurations,
        private readonly ConfigurationImportService $import,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        // per_page honoured up to a cap: 46 seeded configurations silently
        // truncated to the default page was indistinguishable from "the rest
        // of the data is missing" — which is exactly how it was reported.
        return ProductionConfigurationResource::collection(
            $this->configurations->paginate(
                $request->only(['work_center_id', 'item_id', 'status', 'search']),
                min((int) $request->query('per_page', 25), 200),
            ),
        );
    }

    public function store(StoreProductionConfigurationRequest $request): ProductionConfigurationResource
    {
        return ProductionConfigurationResource::make(
            $this->configurations->create($request->validated(), $request->user()?->id),
        );
    }

    public function update(StoreProductionConfigurationRequest $request, ProductionConfiguration $productionConfiguration): ProductionConfigurationResource
    {
        return ProductionConfigurationResource::make(
            $this->configurations->update($productionConfiguration, $request->validated()),
        );
    }

    /**
     * Approval is the moment a draft starts governing production, so it is
     * its own endpoint rather than a status field on update — it is an act,
     * with an actor, not an attribute.
     */
    public function approve(Request $request, ProductionConfiguration $productionConfiguration): ProductionConfigurationResource
    {
        return ProductionConfigurationResource::make(
            $this->configurations->approve($productionConfiguration, $request->user()?->id),
        );
    }

    /**
     * The screen's existing Deactivate. Unchanged on the wire; underneath it
     * is now the shared contract's Archive (ProductionConfigurationService::
     * archive), so this endpoint and the contract's own /archive below can
     * never drift into two answers.
     */
    public function deactivate(ProductionConfiguration $productionConfiguration): ProductionConfigurationResource
    {
        return ProductionConfigurationResource::make(
            $this->stamped($this->configurations->deactivate($productionConfiguration)),
        );
    }

    /** One configuration, archived rows included, with the authoritative `can`. */
    public function show(int $productionConfiguration): ProductionConfigurationResource
    {
        return ProductionConfigurationResource::make($this->stamped($this->resolve($productionConfiguration)));
    }

    /**
     * The Configuration Lifecycle Contract's Archive. Same act as
     * deactivate() and the same one write; it exists under the contract's
     * own verb so every master screen calls one shape.
     */
    public function archive(ConfigurationReasonRequest $request, int $productionConfiguration): ProductionConfigurationResource
    {
        $this->archiveRecord($this->configurations, $this->resolve($productionConfiguration), $request->reason());

        return ProductionConfigurationResource::make($this->stamped($this->resolve($productionConfiguration)));
    }

    /**
     * Put a withdrawn configuration back in service — re-running the three
     * approval gates, because a reactivation joins the set Start Batch
     * resolves from. A DRAFT is refused here and sent to approve().
     */
    public function activate(ConfigurationReasonRequest $request, int $productionConfiguration): ProductionConfigurationResource
    {
        $this->activateRecord($this->configurations, $this->resolve($productionConfiguration), $request->reason());

        return ProductionConfigurationResource::make($this->stamped($this->resolve($productionConfiguration)));
    }

    /**
     * Hard delete — Super Admin / Owner only, only for a configuration no
     * shift has ever run to. The refusal (422, with counts and an Archive
     * offer) and the authorisation failure (403) are both the service's.
     */
    public function destroy(Request $request, int $productionConfiguration): Response
    {
        $this->configurations->delete($this->resolve($productionConfiguration), $request->user());

        return response()->noContent();
    }

    public function copy(Request $request, ProductionConfiguration $productionConfiguration): ProductionConfigurationResource
    {
        return ProductionConfigurationResource::make(
            $this->configurations->copy($productionConfiguration, $request->user()?->id),
        );
    }

    /** Approved configurations a machine may run — the Start Batch filter. */
    public function forMachine(int $workCenter): AnonymousResourceCollection
    {
        return ProductionConfigurationResource::collection(
            $this->configurations->approvedForMachine($workCenter),
        );
    }

    private function resolve(int $id): ProductionConfiguration
    {
        return ProductionConfiguration::withTrashed()
            ->with(['workCenter', 'item', 'mold', 'bom', 'approvedBy'])
            ->find($id) ?? abort(404);
    }

    /**
     * The authoritative `can` — delete resolved — on a single-record answer,
     * intersected with the module permission the write routes actually need
     * (ManagesConfigurationRecords), so a view-only user is never handed
     * buttons that would 403.
     */
    private function stamped(ProductionConfiguration $configuration): ProductionConfiguration
    {
        /** @var ProductionConfiguration */
        return $this->withAbilities(request(), $this->configurations, $configuration);
    }

    public function importRows(ImportProductionConfigurationsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->import->import(
                $data['rows'],
                // Default TRUE — writing is opt-in.
                $data['dry_run'] ?? true,
                $request->user()?->id,
            ),
        ]);
    }
}
