<?php

namespace App\Modules\TallySync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TallySync\Models\TallyStockSnapshot;
use App\Modules\TallySync\Services\TallyOpeningStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading, and then deciding about, what Tally said its stock was.
 *
 * Two verbs, deliberately apart: LOOK, then APPLY. The snapshot arrives from
 * the agent and sits doing nothing; a person reads it; a second, explicit call
 * turns it into the ERP's opening balances. Nothing here is on a timer and
 * nothing happens as a side effect of the read.
 */
class TallyStockSnapshotController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $snapshots = TallyStockSnapshot::query()
            ->with(['appliedBy', 'createdBy'])
            ->latest('id')
            ->limit(20)
            ->get()
            // The line list can run to hundreds of rows; a list screen wants
            // the shape of each snapshot, not its contents.
            ->map(fn (TallyStockSnapshot $s) => [
                'id' => $s->id,
                'company' => $s->company,
                'as_of' => $s->as_of->toDateString(),
                'status' => $s->status,
                'totals' => $s->totals,
                'created_at' => $s->created_at?->toIso8601String(),
                'applied_at' => $s->applied_at?->toIso8601String(),
                'applied_by' => $s->appliedBy?->name,
                'applied_line_count' => $s->applied_line_count,
                'reference_it_would_write' => $s->movementReference(),
            ]);

        return response()->json(['data' => $snapshots]);
    }

    public function show(TallyStockSnapshot $tallyStockSnapshot): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $tallyStockSnapshot->id,
            'company' => $tallyStockSnapshot->company,
            'as_of' => $tallyStockSnapshot->as_of->toDateString(),
            'status' => $tallyStockSnapshot->status,
            'totals' => $tallyStockSnapshot->totals,
            'lines' => $tallyStockSnapshot->lines,
            'reference_it_would_write' => $tallyStockSnapshot->movementReference(),
        ]]);
    }

    /**
     * Turn this snapshot into opening balances. Refused if it has run before —
     * see TallyOpeningStockService for why that matters more than usual.
     */
    public function apply(Request $request, TallyStockSnapshot $tallyStockSnapshot, TallyOpeningStockService $opening): JsonResponse
    {
        $result = $opening->apply($tallyStockSnapshot, $request->user()?->id);

        return response()->json(['data' => [
            'snapshot_id' => $tallyStockSnapshot->id,
            'applied_lines' => $result['applied'],
            'skipped' => $result['skipped'],
            'movement_reference' => $result['reference'],
        ]]);
    }
}
