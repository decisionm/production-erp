<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Exceptions\CommonInputNamesNoMachineException;
use App\Modules\Inventory\Exceptions\MaterialRequestLifecycleException;
use App\Modules\Inventory\Models\Enums\MaterialRequestStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE PRODUCTION MATERIAL REQUEST, and the STORE'S QUEUE built on it.
 *
 * Everything this class does is paperwork. It writes NO stock movement, it
 * posts NO Tally voucher and it consumes NOTHING — a request is the floor
 * asking, and even a fully issued request only means the material has moved
 * from the Raw Material Store into Production/WIP (DEC-20260817-001).
 * The three states — store stock, issued to production, consumed — stay
 * distinct, and this document only ever speaks about the first two.
 *
 * THE ONE FACTORY RULE ENFORCED HERE is FC-01 / DEC-20260807-006: a
 * common-input request names no machine and no area. See
 * guardCommonInputNamesNoMachine.
 *
 * FC-06: nothing on this surface carries a rate, an amount or a vendor, so
 * nothing here needs finance standing and nothing here grants it.
 *
 * THE STATE MACHINE lives in one place — abilities(). The resource prints
 * it as `can` and every action re-asks it before writing, so no screen
 * re-derives what is allowed.
 */
class MaterialRequestService
{
    /**
     * Same ceiling as the two Procurement lists: the store's queue has to
     * be able to reach an older request in one page for a deep link.
     */
    public const PER_PAGE_MAX = 1000;

    public const PER_PAGE_DEFAULT = 20;

    /**
     * THE STORE'S QUEUE. Every filter is applied in SQL — the storekeeper's
     * narrowing must survive paging, and a `meta.total` computed off a
     * page of rows would be a different (wrong) number.
     *
     * @param  array{status?: string|list<string>|null, shift_id?: int|null, work_center_id?: int|null, item_id?: int|null, from?: string|null, to?: string|null, q?: string|null, sort?: string|null, per_page?: int|null}  $filters
     */
    public function queue(array $filters = []): LengthAwarePaginator
    {
        $query = MaterialRequest::query()->with([
            'lines.item:id,sku,name,uom',
            'requestedBy:id,name',
            'cancelledBy:id,name',
            'shift:id,name',
            'workCenter:id,code,name',
        ]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'] ?? null);

        $perPage = (int) ($filters['per_page'] ?? self::PER_PAGE_DEFAULT);

        $page = $query->paginate($perPage);

        foreach ($page->items() as $request) {
            $this->decorate($request);
        }

        return $page;
    }

    /** One request, loaded and decorated exactly as the queue's rows are. */
    public function show(MaterialRequest $request): MaterialRequest
    {
        $request->load([
            'lines.item:id,sku,name,uom',
            'requestedBy:id,name',
            'cancelledBy:id,name',
            'shift:id,name',
            'workCenter:id,code,name',
        ]);

        return $this->decorate($request);
    }

    /**
     * Raise a request, as a DRAFT. Nothing reaches the store's queue until
     * it is submitted.
     *
     * @param  array{shift_id?: int|null, work_center_id?: int|null, notes?: string|null, lines: list<array{item_id: int, quantity: string, notes?: string|null}>}  $data
     */
    /**
     * The materials the floor may ask the store for.
     *
     * ELIGIBILITY IS A COLUMN (`items.is_production_input`), not a heuristic.
     * Before this existed the picker was fed the WHOLE item master, so a
     * finished good was offered as a requestable input — the owner reported
     * exactly that, naming a 1 Litre PET Bottle. Excluded here by construction:
     * finished goods and saleable products, archived and inactive items,
     * services, and any Tally item the factory has not configured as an input.
     *
     * `is_active` is applied HERE and not in the scope, because this list
     * offers NEW work. A screen rendering an old request still resolves its
     * material by id through the relation, so a material archived later keeps
     * showing on the requests that already name it.
     *
     * @return Collection<int, Item>
     */
    public function requestableMaterials()
    {
        return Item::query()
            ->productionInput()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Is this item one the floor may request at all?
     *
     * The single predicate both the FormRequest and the service refuse on, so
     * the API cannot be talked into accepting by hand what the picker does not
     * offer. Filtering the dropdown alone would not have fixed the defect.
     */
    public function isRequestable(int $itemId): bool
    {
        return Item::query()->whereKey($itemId)->productionInput()->where('is_active', true)->exists();
    }

    public function create(array $data, ?int $requestedBy): MaterialRequest
    {
        $workCenterId = $data['work_center_id'] ?? null;

        /** @var array<int, Item> $items */
        $items = Item::query()
            ->whereIn('id', array_column($data['lines'], 'item_id'))
            ->get()
            ->keyBy('id')
            ->all();

        // Refuse BEFORE the transaction opens: a refusal must leave no
        // half-saved request behind.
        if ($workCenterId !== null) {
            foreach ($data['lines'] as $line) {
                $item = $items[$line['item_id']] ?? null;
                if ($item !== null) {
                    $this->guardCommonInputNamesNoMachine($item);
                }
            }
        }

        return DB::transaction(function () use ($data, $requestedBy, $workCenterId, $items) {
            $request = MaterialRequest::create([
                'status' => MaterialRequestStatus::Draft,
                'requested_by' => $requestedBy,
                'requested_at' => now(),
                'shift_id' => $data['shift_id'] ?? null,
                'work_center_id' => $workCenterId,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $request->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    // The ITEM's unit, never the caller's (FC-03).
                    'uom' => ($items[$line['item_id']] ?? null)?->uom,
                    'issued_quantity' => '0',
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $this->show($request);
        });
    }

    /** Draft → submitted: the request enters the store's queue. */
    public function submit(MaterialRequest $request): MaterialRequest
    {
        if (! $this->abilities($request)['submit']) {
            throw $request->status === MaterialRequestStatus::Draft
                ? MaterialRequestLifecycleException::nothingToSubmit($request)
                : MaterialRequestLifecycleException::cannotSubmit($request);
        }

        $request->update([
            'status' => MaterialRequestStatus::Submitted,
            'submitted_at' => now(),
        ]);

        return $this->show($request);
    }

    /**
     * Withdraw what has not been handed over yet, with a reason and an
     * author. Cancelling NEVER reverses an issue: material already standing
     * in production comes back through a store return, which is a stock
     * movement, not a change of paperwork.
     */
    public function cancel(MaterialRequest $request, string $reason, ?int $cancelledBy): MaterialRequest
    {
        if (! $this->abilities($request)['cancel']) {
            throw MaterialRequestLifecycleException::cannotCancel($request);
        }

        $request->update([
            'status' => MaterialRequestStatus::Cancelled,
            'cancelled_by' => $cancelledBy,
            'cancelled_at' => now(),
            'cancelled_reason' => $reason,
        ]);

        return $this->show($request);
    }

    /**
     * THE ONE WRITER of `issued_quantity` and of the two fulfilment
     * statuses — called by the store-issue flow when material is actually
     * handed over. Deltas are ADDED to what a line already carries, so a
     * second part-issue against the same line accumulates.
     *
     * NOT A CONSUMPTION. This records that the store gave the material to
     * production; where the stock now stands (Production/WIP) is the
     * issue's own signed transfer pair, and what a batch used is calculated
     * later from output, rejection and lumps.
     *
     * OVER-ISSUE IS ALLOWED, deliberately. A bag is not divisible: a 20 kg
     * request handed a 25 kg bag is the ordinary case on this floor, and
     * refusing it would stop the shift over arithmetic. The overage stays
     * visible in `issued_quantity`; `remaining_quantity` floors at zero.
     *
     * @param  array<int, string>  $deltasByLineId  line id → quantity handed over now
     */
    public function applyIssuedQuantities(MaterialRequest $request, array $deltasByLineId): MaterialRequest
    {
        if (! $request->status->isOpenToTheStore()) {
            throw MaterialRequestLifecycleException::notOpenToTheStore($request);
        }

        return $this->writeIssuedQuantities($request, $deltasByLineId);
    }

    /**
     * THE SAME WRITER, RUNNING BACKWARDS — for a store issue that is
     * CANCELLED (StoreIssueService::cancel), where the handover is reversed
     * in full and the request is owed its remainder again.
     *
     * Why this exists at all: `quantity_remaining_on_request` on the ISSUE
     * side already excludes cancelled issues, so without this the request's
     * own `remaining_quantity` and the issue's would disagree from the
     * moment the first handover was cancelled — the store's queue would go
     * on showing a request as fulfilled by material that went straight back
     * on the shelf.
     *
     * NO STATUS GATE, deliberately, and it is the mirror of the gate on
     * applyIssuedQuantities. Cancelling a handover is arithmetic on a number
     * that is already wrong; refusing it because the request has since moved
     * to `issued` (which the handover itself caused) would leave the wrong
     * number standing. A withdrawn or draft request keeps its status — a
     * reversal corrects a figure, it never resurrects a document.
     *
     * It is NOT called for a RETURN. A return is a later, separate fact —
     * the store did hand the material over and some of it came back — and
     * the issue side records it the same way, by leaving `quantity_issued`
     * alone. Reversing the request there would claim the handover never
     * happened.
     *
     * @param  array<int, string>  $deltasByLineId  line id → quantity being taken back (negative)
     */
    public function reverseIssuedQuantities(MaterialRequest $request, array $deltasByLineId): MaterialRequest
    {
        return $this->writeIssuedQuantities($request, $deltasByLineId);
    }

    /**
     * @param  array<int, string>  $deltasByLineId
     */
    private function writeIssuedQuantities(MaterialRequest $request, array $deltasByLineId): MaterialRequest
    {
        return DB::transaction(function () use ($request, $deltasByLineId) {
            $lines = $request->lines()->lockForUpdate()->get()->keyBy('id');

            foreach ($deltasByLineId as $lineId => $delta) {
                $line = $lines->get((int) $lineId);

                if ($line === null) {
                    throw MaterialRequestLifecycleException::lineNotOnThisRequest($request, (int) $lineId);
                }

                $line->update([
                    'issued_quantity' => bcadd((string) $line->issued_quantity, (string) $delta, 4),
                ]);
            }

            $request->update(['status' => $this->fulfilmentStatus($request->fresh())]);

            return $this->show($request->fresh());
        });
    }

    /**
     * {submit, cancel, issue} — the SAME predicate every action enforces.
     *
     *   submit  a draft that actually has lines to ask for
     *   cancel  anything not yet finished (a partially issued request can
     *           still have its REMAINDER withdrawn)
     *   issue   the store may fulfil it: submitted or partially issued
     *
     * @return array{submit: bool, cancel: bool, issue: bool}
     */
    public function abilities(MaterialRequest $request): array
    {
        $status = $request->status;
        $hasLines = $request->relationLoaded('lines')
            ? $request->lines->isNotEmpty()
            : $request->lines()->exists();

        return [
            'submit' => $status === MaterialRequestStatus::Draft && $hasLines,
            'cancel' => ! $status->isFinal(),
            'issue' => $status->isOpenToTheStore(),
        ];
    }

    /** How many rows one page of the queue holds, bounded. */
    public function perPage(?int $requested): int
    {
        $perPage = $requested ?? self::PER_PAGE_DEFAULT;

        return max(1, min($perPage, self::PER_PAGE_MAX));
    }

    // ---- internals ---------------------------------------------------------

    /**
     * FC-01 / DEC-20260807-006. The signal for "common input" is the item's
     * kg-family unit — the SAME predicate the day bin's own raw-material
     * reads use (Item::KG_UOM_VARIANTS via hasKgUom()), because this
     * database carries no other classification of a material. Resin and
     * masterbatch are both kg items and FC-04 already issues both from the
     * one factory location, so both are treated as common input here.
     */
    private function guardCommonInputNamesNoMachine(Item $item): void
    {
        if ($item->hasKgUom()) {
            throw CommonInputNamesNoMachineException::forItem($item);
        }
    }

    /** @param  array<string, mixed>  $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;
        if ($status !== null && $status !== []) {
            $query->whereIn('status', array_map(
                fn ($value) => $value instanceof MaterialRequestStatus ? $value->value : (string) $value,
                is_array($status) ? $status : [$status],
            ));
        }

        foreach (['shift_id', 'work_center_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, (int) $filters[$column]);
            }
        }

        // The date range is on requested_at — when the FLOOR asked, which is
        // the only date the storekeeper is looking for — and it is a FACTORY
        // day, not a UTC one.
        //
        // The app clock is UTC and must stay UTC (CLAUDE.md); the factory's
        // day is IST. A night-shift request raised at 02:00 IST on the 17th
        // is stored as 20:30 UTC on the 16th, so comparing the column
        // against the raw string "2026-08-17 00:00:00" would file it under
        // the wrong day and hide it from the queue the storekeeper is
        // actually working. Both ends are therefore localised through
        // config('tally-sync.factory_timezone') first — the same treatment
        // (and the same half-open upper bound) as Sales'
        // SalesDocumentQuery::applyFactoryDayRange, so a date range means
        // the same thing on every list in this ERP.
        $timezone = config('tally-sync.factory_timezone');

        if (! empty($filters['from'])) {
            $query->where('requested_at', '>=', CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            // "< the next factory midnight" rather than "<= 23:59:59", so a
            // request raised in the last second of the day is never lost.
            $query->where('requested_at', '<', CarbonImmutable::parse($filters['to'], $timezone)->addDay()->startOfDay()->utc());
        }

        // Material: a request is IN the queue for an item if any of its
        // lines asks for it. An EXISTS subquery, so it narrows in SQL and
        // never duplicates a header.
        if (! empty($filters['item_id'])) {
            $query->whereHas('lines', fn (Builder $lines) => $lines->where('item_id', (int) $filters['item_id']));
        }

        // `q` is the request NUMBER in any spelling — "MR-12", "mr 12", "12".
        // Deliberately not a free-text search of notes: the store's queue is
        // worked by number, and a notes search would quietly widen it.
        if (! empty($filters['q'])) {
            $digits = preg_replace('/\D+/', '', (string) $filters['q']);
            $query->where('id', $digits === '' ? -1 : (int) $digits);
        }
    }

    private function applySort(Builder $query, ?string $sort): void
    {
        $sort ??= '-id';
        $descending = str_starts_with($sort, '-');
        $column = ltrim($sort, '-');

        $query->orderBy($column, $descending ? 'desc' : 'asc');

        if ($column !== 'id') {
            $query->orderBy('id', $descending ? 'desc' : 'asc');
        }
    }

    /**
     * Submitted → partially_issued → issued, from what the lines now say —
     * and back again when a handover is cancelled.
     *
     * A DRAFT OR CANCELLED REQUEST IS NEVER MOVED BY THIS. Those two are the
     * floor's and the owner's statements about the document, not arithmetic
     * about kilograms: a reversal must never turn a withdrawn request back
     * into an open one behind the person who withdrew it.
     *
     * For the other three the status is derived, not remembered, so it is
     * reversible: a request whose only handover has been cancelled reads
     * `submitted` again — which is what the store's queue has to show,
     * because the material is back on the shelf and it is owed in full.
     */
    private function fulfilmentStatus(MaterialRequest $request): MaterialRequestStatus
    {
        if ($request->status === MaterialRequestStatus::Draft
            || $request->status === MaterialRequestStatus::Cancelled) {
            return $request->status;
        }

        $lines = $request->lines()->get();

        $anyIssued = $lines->contains(fn ($line) => bccomp((string) $line->issued_quantity, '0', 4) === 1);
        $allSettled = $lines->every(fn ($line) => bccomp($line->remainingQuantity(), '0', 4) === 0);

        if ($lines->isNotEmpty() && $allSettled) {
            return MaterialRequestStatus::Issued;
        }

        return $anyIssued ? MaterialRequestStatus::PartiallyIssued : MaterialRequestStatus::Submitted;
    }

    private function decorate(MaterialRequest $request): MaterialRequest
    {
        $request->can = $this->abilities($request);

        return $request;
    }
}
