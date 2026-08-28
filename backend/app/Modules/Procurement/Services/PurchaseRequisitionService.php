<?php

namespace App\Modules\Procurement\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Procurement\Models\Enums\PurchaseRequisitionStatus;
use App\Modules\Procurement\Models\PurchaseRequisition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PurchaseRequisitionService
{
    /** Loaded on every requisition the list hands back, so the resource never lazy-loads. */
    private const WITH = ['lines.item', 'requestedBy', 'approvedBy', 'rejectedBy', 'purchaseOrders'];

    public function __construct(private readonly ProcurementDocumentQuery $query) {}

    /**
     * The list, filtered (28-Aug audit finding 8 — the queue had no way in).
     * $filters is the validated ListPurchaseRequisitionsRequest input; an
     * empty array is the unfiltered list every earlier caller still gets —
     * newest first, same page size.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = PurchaseRequisition::query()->with(self::WITH);
        $this->applyFilters($query, $filters);
        $this->query->applySort($query, $filters['sort'] ?? null, ['needed_by_date', 'created_at']);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Every filter of ListPurchaseRequisitionsRequest. `q` matches the
     * requisition number in any spelling ("PR-12", "pr 12", "12"), the
     * requester's name, or a line's item by name or SKU — never notes. The
     * date range is on needed_by_date, a plain date.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $statuses = is_array($filters['status'])
                ? array_values(array_filter($filters['status'], fn ($status) => $status !== null && $status !== ''))
                : [$filters['status']];
            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }

        if (! empty($filters['item_id'])) {
            $query->whereHas('lines', fn (Builder $lines) => $lines->where('item_id', (int) $filters['item_id']));
        }

        $this->query->applyDateRange($query, 'needed_by_date', $filters['from'] ?? null, $filters['to'] ?? null);

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $term = trim((string) $filters['q']);
            $id = $this->query->documentId($term, 'PR');

            $query->where(function (Builder $any) use ($term, $id) {
                if ($id !== null) {
                    $any->orWhere('purchase_requisitions.id', $id);
                }
                $any->orWhereHas('requestedBy', fn (Builder $user) => $this->query->whereLike($user, 'name', $term));
                $any->orWhereHas('lines.item', function (Builder $item) use ($term) {
                    $item->where(function (Builder $either) use ($term) {
                        $this->query->whereLike($either, 'name', $term);
                        $either->orWhere(fn (Builder $sku) => $this->query->whereLike($sku, 'sku', $term));
                    });
                });
            });
        }
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

    /**
     * The decision stamps (who/when) ride the same UPDATE as the status —
     * forceFill because they are deliberately not Fillable: nothing but the
     * moment of decision may write them (28-Aug audit finding 8: "approved"
     * with no by-whom or when is a paper trail that stops at the word).
     */
    public function approve(PurchaseRequisition $requisition, ?int $approvedBy = null): PurchaseRequisition
    {
        $this->guardStatus($requisition, PurchaseRequisitionStatus::Draft, PurchaseRequisitionStatus::Approved);
        $requisition->forceFill([
            'status' => PurchaseRequisitionStatus::Approved,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ])->save();

        return $requisition->load(['lines.item', 'requestedBy', 'approvedBy', 'rejectedBy', 'purchaseOrders']);
    }

    public function reject(PurchaseRequisition $requisition, ?int $rejectedBy = null): PurchaseRequisition
    {
        $this->guardStatus($requisition, PurchaseRequisitionStatus::Draft, PurchaseRequisitionStatus::Rejected);
        $requisition->forceFill([
            'status' => PurchaseRequisitionStatus::Rejected,
            'rejected_by' => $rejectedBy,
            'rejected_at' => now(),
        ])->save();

        return $requisition->load(['lines.item', 'requestedBy', 'approvedBy', 'rejectedBy', 'purchaseOrders']);
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
