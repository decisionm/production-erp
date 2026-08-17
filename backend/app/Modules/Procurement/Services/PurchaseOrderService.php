<?php

namespace App\Modules\Procurement\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class PurchaseOrderService
{
    /** Loaded on every order the list hands back, so the resource never lazy-loads. */
    private const WITH = ['vendor', 'lines.item', 'lines.schedules'];

    /** How many orders cursor() reads per query — a page of the export, never the whole file. */
    private const EXPORT_CHUNK = 500;

    public function __construct(private readonly ProcurementDocumentQuery $query) {}

    /**
     * The list, filtered (Phase 4.5, mirroring Sales' Phase 3.5 lists).
     * $filters is the validated ListPurchaseOrdersRequest input; an empty
     * array is the unfiltered list every earlier caller still gets — newest
     * first, same page size.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        // withQueryString(): the paginator's own links carry the request's
        // query string, so an API client walking links.next stays on the
        // same query (as the Sales lists do).
        return $this->listQuery($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * Every matching order, in the list's order, one at a time — the Export
     * Center's read (PurchaseOrdersExport / PurchaseOrderLinesExport): the
     * SAME filters and the SAME ordering as paginate(), off the same
     * builder, so a file can never carry rows the screen would not, nor in
     * another order. Builder::lazy, not Builder::cursor(): cursor() skips
     * the eager loads the resource prints.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, PurchaseOrder>
     */
    public function cursor(array $filters = []): LazyCollection
    {
        return $this->listQuery($filters)->lazy(self::EXPORT_CHUNK);
    }

    /**
     * How many orders the list would carry — one COUNT over the filtered
     * query (the export's cap check; also the list's meta.total).
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters = []): int
    {
        return $this->filtered($filters)->count();
    }

    /**
     * How many LINES the matching orders carry between them — one COUNT
     * with the filtered orders as a subquery (the line export's cap check).
     *
     * @param  array<string, mixed>  $filters
     */
    public function linesCount(array $filters = []): int
    {
        return PurchaseOrderLine::query()
            ->whereIn('purchase_order_id', $this->filtered($filters)->select('purchase_orders.id'))
            ->count();
    }

    /**
     * Orders with stock still to arrive, soonest expected first (undated
     * last) — the dashboard's "stock coming in" read. Drafts are excluded:
     * nothing is coming until an order has been sent.
     *
     * @return Collection<int, PurchaseOrder>
     */
    public function upcoming(int $limit = 5): Collection
    {
        return PurchaseOrder::query()
            ->with(['vendor', 'lines.item'])
            ->whereIn('status', [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyReceived])
            ->orderByRaw('expected_date IS NULL, expected_date')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function openCount(): int
    {
        return PurchaseOrder::query()
            ->whereIn('status', [
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::Sent,
                PurchaseOrderStatus::PartiallyReceived,
            ])
            ->count();
    }

    /**
     * @param  array{vendor_id: int, purchase_requisition_id?: int, order_date: string, expected_date?: string, notes?: string, lines: array<int, array{item_id: int, quantity: string, unit_price: string}>}  $data
     */
    public function create(array $data, ?int $createdBy): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $order = PurchaseOrder::create([
                'vendor_id' => $data['vendor_id'],
                'purchase_requisition_id' => $data['purchase_requisition_id'] ?? null,
                'status' => PurchaseOrderStatus::Draft,
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
                // 'tally' = a read-only mirror of the order that lives in
                // Tally (the PO/schedule source of truth). It is corrected
                // in Tally and re-mirrored, never edited here.
                'source' => $data['source'] ?? 'erp',
                'tally_order_no' => $data['tally_order_no'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $created = $order->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'quantity_received' => 0,
                ]);

                // Item/due-date delivery windows — the mirror of Tally's
                // order allocations. Their sum may not exceed the line: a
                // schedule promising more than was ordered is a typo, not a
                // plan.
                $total = '0.0000';
                foreach ($line['schedules'] ?? [] as $schedule) {
                    $total = bcadd($total, (string) $schedule['quantity'], 4);
                    $created->schedules()->create([
                        'due_date' => $schedule['due_date'],
                        'quantity' => $schedule['quantity'],
                        'quantity_received' => 0,
                        'tally_reference' => $schedule['tally_reference'] ?? null,
                    ]);
                }

                if (bccomp($total, (string) $created->quantity, 4) > 0) {
                    throw new InvalidStatusTransitionException(
                        "the delivery schedules for one line promise {$total} against an ordered {$created->quantity} — correct the schedule quantities",
                    );
                }
            }

            // A Tally mirror arrives already sent — it IS the live order;
            // draft/send is the ERP-native lifecycle only.
            if (($data['source'] ?? 'erp') === 'tally') {
                $order->update(['status' => PurchaseOrderStatus::Sent]);
            }

            return $order->load(['vendor', 'lines.item', 'lines.schedules']);
        });
    }

    public function send(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== PurchaseOrderStatus::Draft) {
            throw InvalidStatusTransitionException::make(
                'purchase order',
                $order->status->value,
                PurchaseOrderStatus::Sent->value,
            );
        }

        $order->update(['status' => PurchaseOrderStatus::Sent]);

        return $order;
    }

    // ---- internals --------------------------------------------------------------

    /**
     * The list's builder: every filter applied, the relations the resource
     * prints, then the list's order — what paginate() pages and cursor()
     * streams.
     *
     * @param  array<string, mixed>  $filters
     */
    private function listQuery(array $filters): Builder
    {
        $query = $this->filtered($filters)->with(self::WITH);
        $this->query->applySort($query, $filters['sort'] ?? null, ['order_date', 'expected_date']);

        return $query;
    }

    /**
     * The filtered orders, nothing loaded and nothing ordered — the one
     * builder listQuery(), count() and linesCount() all start from.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        $query = PurchaseOrder::query();
        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * Every filter of ListPurchaseOrdersRequest. `q` matches the order
     * number in any spelling ("PO-12", "po 12", "12") or the vendor's name
     * or code — never notes. The date range is on order_date, a plain date.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->query->applyDateRange($query, 'order_date', $filters['from'] ?? null, $filters['to'] ?? null);

        if (! empty($filters['item_id'])) {
            $query->whereHas('lines', fn (Builder $lines) => $lines->where('item_id', (int) $filters['item_id']));
        }

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $term = trim((string) $filters['q']);
            $id = $this->query->documentId($term, 'PO');

            $query->where(function (Builder $any) use ($term, $id) {
                if ($id !== null) {
                    $any->orWhere('purchase_orders.id', $id);
                }
                $any->orWhereHas('vendor', fn (Builder $vendor) => $this->query->whereVendorMatches($vendor, $term));
            });
        }
    }
}
