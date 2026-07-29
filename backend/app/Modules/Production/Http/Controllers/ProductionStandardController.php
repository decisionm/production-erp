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
     * Standards coverage: which products the factory master actually
     * configures, and under which product name it configures them.
     *
     * The thinnest possible projection of the same data `index()` returns —
     * two scalar columns, no eager loads, no pagination — because its only
     * consumer is a picker that has to sort ~80 items into "set up" and "not
     * set up" before the supervisor's first keystroke. Fetching the real
     * standards (90 rows, each with its item and its packagings) to answer a
     * yes/no question would be several hundred kilobytes for one bit each.
     *
     * `source_product_name` rides along because the picker needs one more
     * thing: when a supervisor lands on an unconfigured legacy item, it can
     * offer the configured product of the SAME name as a replacement. That
     * offer is only ever made on an exact match after normalisation —
     * suggesting a near-miss would put the wrong product on the machine.
     *
     * No status filter, deliberately: an "unresolved" variant (two candidate
     * cycle times pending a factory answer) is still a configured product,
     * and Start Batch already shows those variants and lets the supervisor
     * choose. Filtering them out here would tell the picker a product is
     * unconfigured while the very next screen offers its standards.
     */
    public function coverage(): JsonResponse
    {
        // One query, two columns. Matched standards only — an unmatched one
        // names a product that has no item to pick in the first place.
        $rows = ProductionStandard::query()
            ->whereNotNull('item_id')
            ->orderBy('item_id')
            ->get(['item_id', 'source_product_name'])
            ->map(fn (ProductionStandard $standard) => [
                'item_id' => $standard->item_id,
                'source_product_name' => $standard->source_product_name,
            ])
            // Sibling variants of one product repeat the pair; the picker
            // only ever asks "does this exist", so send it once.
            ->unique(fn (array $row) => $row['item_id'].'|'.$row['source_product_name'])
            ->values();

        return response()->json(['data' => $rows]);
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
