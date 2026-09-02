<?php

namespace App\Modules\Procurement\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Procurement\Exceptions\NotTheRequesterException;
use App\Modules\Procurement\Exceptions\SelfDecisionException;
use App\Modules\Procurement\Models\Enums\PurchaseRequisitionStatus;
use App\Modules\Procurement\Models\PurchaseRequisition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PurchaseRequisitionService
{
    /** Loaded on every requisition the list hands back, so the resource never lazy-loads. */
    /**
     * `purchaseOrders.lines` is loaded for the coverage arithmetic (what has
     * already been ordered against each line): without it
     * RequisitionCoverageService falls back to a query PER REQUISITION, and
     * this list is paginated at 20 but served up to the module's ceiling.
     * `purchaseOrders.lines.item` is NOT loaded — the arithmetic groups by
     * item_id and needs no item row; only the requisition's OWN lines print
     * an item.
     */
    private const WITH = ['lines.item', 'requestedBy', 'approvedBy', 'rejectedBy', 'withdrawnBy', 'purchaseOrders.lines'];

    public function __construct(
        private readonly ProcurementDocumentQuery $query,
        private readonly RequisitionCoverageService $coverage,
    ) {}

    /**
     * Stamp each requisition's lines with their coverage — the ONE place a
     * requisition becomes readable, so the list, the create response and
     * both decisions can never disagree about how much of a line is ordered.
     * The PurchaseOrderService::decorateMany() pattern, and like it, it runs
     * on every row this service hands back.
     *
     * @param  iterable<int, PurchaseRequisition>  $requisitions
     */
    private function decorateMany(iterable $requisitions): void
    {
        foreach ($requisitions as $requisition) {
            $byLine = $this->coverage->byLine($requisition);

            foreach ($requisition->lines as $line) {
                $line->coverage = $byLine[$line->id] ?? null;
            }
        }
    }

    /** One requisition, loaded and decorated as the list serves them. */
    private function decorate(PurchaseRequisition $requisition): PurchaseRequisition
    {
        $requisition->load(self::WITH);
        $this->decorateMany([$requisition]);

        return $requisition;
    }

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

        $page = $query->paginate($perPage)->withQueryString();
        $this->decorateMany($page->getCollection());

        return $page;
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

            // Decorated like every other row this service returns — a fresh
            // requisition has no orders yet, so every line reads Not Ordered
            // with its full quantity still to order. That is a computed
            // answer, not an assumed one.
            return $this->decorate($requisition);
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
        return $this->decide($requisition, PurchaseRequisitionStatus::Approved, [
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ], $approvedBy, 'approved');
    }

    public function reject(PurchaseRequisition $requisition, ?int $rejectedBy = null): PurchaseRequisition
    {
        return $this->decide($requisition, PurchaseRequisitionStatus::Rejected, [
            'rejected_by' => $rejectedBy,
            'rejected_at' => now(),
        ], $rejectedBy, 'rejected');
    }

    /**
     * ONE decision per requisition (Codex on 073a8c2): Approve and Reject
     * racing on the same draft both passed the route-model guard, and the
     * loser's stamps landed beside the winner's — a row claiming both
     * decisions. The guard now runs on a row lock, so the second decision
     * is refused as the status transition it actually is.
     *
     * @param  array<string, mixed>  $stamps
     */
    private function decide(PurchaseRequisition $requisition, PurchaseRequisitionStatus $target, array $stamps, ?int $decidedBy, string $verb): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition, $target, $stamps, $decidedBy, $verb) {
            $locked = PurchaseRequisition::query()->lockForUpdate()->findOrFail($requisition->id);
            $this->guardStatus($locked, PurchaseRequisitionStatus::Draft, $target);

            // DEC-20260902-025: no Administrator exemption, so this is a plain
            // id comparison and nothing consults roles.
            if ($decidedBy !== null && $locked->requested_by !== null && (int) $locked->requested_by === (int) $decidedBy) {
                throw SelfDecisionException::forRequisition($locked->id, $verb);
            }

            $locked->forceFill(['status' => $target, ...$stamps])->save();

            return $this->decorate($locked);
        });
    }

    /**
     * DEC-20260902-025: the requester's own exit. Not a decision, so it is not
     * `decide()`: no approver stamps, and the comparison runs the other way.
     */
    public function withdraw(PurchaseRequisition $requisition, int $userId): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition, $userId) {
            $locked = PurchaseRequisition::query()->lockForUpdate()->findOrFail($requisition->id);
            $this->guardStatus($locked, PurchaseRequisitionStatus::Draft, PurchaseRequisitionStatus::Withdrawn);

            if ((int) $locked->requested_by !== $userId) {
                throw new NotTheRequesterException('Only the person who raised a requisition can withdraw it.');
            }

            $locked->forceFill([
                'status' => PurchaseRequisitionStatus::Withdrawn,
                'withdrawn_by' => $userId,
                'withdrawn_at' => now(),
            ])->save();

            return $this->decorate($locked);
        });
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
