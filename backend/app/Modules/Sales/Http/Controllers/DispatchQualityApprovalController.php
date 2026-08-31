<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Services\DispatchQualityApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QUALITY'S SIGN-OFF ON A SALES ORDER LINE — DEC-20260831-003.
 *
 * Routed under the QUALITY module although the record lives on a Sales model,
 * because the act is Quality's: the same two-sided shape the material request
 * and the production request already use. Sales cannot approve its own
 * dispatch, which is the whole point of a gate.
 */
class DispatchQualityApprovalController extends Controller
{
    public function __construct(private readonly DispatchQualityApprovalService $approvals) {}

    public function approve(Request $request, SalesOrderLine $sales_order_line): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $line = $this->approvals->approve($sales_order_line, $validated['note'] ?? null, $request->user()?->id);

        return response()->json(['data' => $this->payload($line)]);
    }

    public function revoke(Request $request, SalesOrderLine $sales_order_line): JsonResponse
    {
        $line = $this->approvals->revoke($sales_order_line, $request->user()?->id);

        return response()->json(['data' => $this->payload($line)]);
    }

    /**
     * FC-06: the approval says who, when and how much — never a rate, never a
     * cost. The line carries a unit_price and it is deliberately not read.
     *
     * @return array<string, mixed>
     */
    private function payload(SalesOrderLine $line): array
    {
        $line->loadMissing('qualityApprovedBy:id,name');

        return [
            'line_id' => (int) $line->id,
            'sales_order_id' => (int) $line->sales_order_id,
            'quality_approved' => $line->isQualityApproved(),
            'quality_approved_at' => $line->quality_approved_at?->toIso8601String(),
            'quality_approved_by' => $line->qualityApprovedBy?->name,
            'quality_approved_quantity' => $line->isQualityApproved() ? $line->qualityApprovedQuantity() : null,
            'quality_approval_note' => $line->quality_approval_note,
            'dispatchable' => $this->approvals->dispatchableQuantity($line),
        ];
    }
}
