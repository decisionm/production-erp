<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\ImportProductionConfigurationsRequest;
use App\Modules\Production\Http\Requests\StoreProductionConfigurationRequest;
use App\Modules\Production\Http\Resources\ProductionConfigurationResource;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Services\ConfigurationImportService;
use App\Modules\Production\Services\ProductionConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductionConfigurationController extends Controller
{
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

    public function deactivate(ProductionConfiguration $productionConfiguration): ProductionConfigurationResource
    {
        return ProductionConfigurationResource::make($this->configurations->deactivate($productionConfiguration));
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
