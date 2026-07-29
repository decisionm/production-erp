<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\ImportProductionStandardsRequest;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Services\ProductionStandardImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionStandardController extends Controller
{
    public function __construct(private readonly ProductionStandardImportService $import) {}

    public function index(Request $request): JsonResponse
    {
        $standards = ProductionStandard::query()
            ->with(['item', 'packagings'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('item_id'), fn ($q, $v) => $q->where('item_id', $v))
            ->when($request->boolean('matched_only'), fn ($q) => $q->whereNotNull('item_id'))
            ->orderBy('source_product_name')
            ->orderBy('cavities')
            ->paginate((int) $request->query('per_page', 50));

        return response()->json($standards);
    }

    /**
     * Import factory product standards. Dry run unless `dry_run=false`.
     */
    public function import(ImportProductionStandardsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->import->import(
                $data['rows'],
                $data['dry_run'] ?? true,
                $request->user()?->id,
                $data['exact_only'] ?? true,
            ),
        ]);
    }
}
