<?php

namespace App\Modules\Procurement\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Procurement\Models\Enums\PurchaseRequisitionStatus;
use App\Modules\Procurement\Models\PurchaseRequisition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseRequisitionService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return PurchaseRequisition::query()
            ->with(['lines.item', 'requestedBy'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function pendingApprovalCount(): int
    {
        return PurchaseRequisition::query()
            ->where('status', PurchaseRequisitionStatus::Draft)
            ->count();
    }

    /**
     * @param  array{needed_by_date?: string, notes?: string, lines: array<int, array{item_id: int, quantity: string, notes?: string}>}  $data
     */
    public function create(array $data, ?int $requestedBy): PurchaseRequisition
    {
        return DB::transaction(function () use ($data, $requestedBy) {
            $requisition = PurchaseRequisition::create([
                'status' => PurchaseRequisitionStatus::Draft,
                'requested_by' => $requestedBy,
                'needed_by_date' => $data['needed_by_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $requisition->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $requisition->load(['lines.item', 'requestedBy']);
        });
    }

    public function approve(PurchaseRequisition $requisition): PurchaseRequisition
    {
        $this->guardStatus($requisition, PurchaseRequisitionStatus::Draft, PurchaseRequisitionStatus::Approved);
        $requisition->update(['status' => PurchaseRequisitionStatus::Approved]);

        return $requisition;
    }

    public function reject(PurchaseRequisition $requisition): PurchaseRequisition
    {
        $this->guardStatus($requisition, PurchaseRequisitionStatus::Draft, PurchaseRequisitionStatus::Rejected);
        $requisition->update(['status' => PurchaseRequisitionStatus::Rejected]);

        return $requisition;
    }

    private function guardStatus(
        PurchaseRequisition $requisition,
        PurchaseRequisitionStatus $requiredCurrent,
        PurchaseRequisitionStatus $target,
    ): void {
        if ($requisition->status !== $requiredCurrent) {
            throw InvalidStatusTransitionException::make(
                'purchase requisition',
                $requisition->status->value,
                $target->value,
            );
        }
    }
}
