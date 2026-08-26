<?php

namespace App\Modules\Sales\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Production\Services\ProductionRequestService;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class SalesOrderService
{
    /** Loaded on every order the service hands back, so the resource never lazy-loads. */
    private const WITH = ['customer', 'lines.item'];

    /** How many orders cursor() reads per query — a page of the export, never the whole file. */
    private const EXPORT_CHUNK = 500;

    public function __construct(
        private readonly SalesDocumentQuery $query,
        private readonly SalesDocumentTraceService $trace,
        // S6 — a cancelled order stops holding stock and stops asking the
        // floor for pieces. Both through the owning module's own service,
        // never its tables.
        private readonly StockReservationService $reservations,
        private readonly ProductionRequestService $productionRequests,
    ) {}

    /**
     * The list, filtered (Phase 3.5). $filters is the validated
     * ListSalesOrdersRequest input; an empty array is the unfiltered list
     * every earlier caller (the dashboard's recent orders) still gets —
     * newest first, same page size.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        // withQueryString(): the paginator's own links carry the request's
        // query string (as typed — unknown keys are reflected too, harmlessly),
        // so an API client walking links.next stays on the same query.
        return $this->listQuery($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * Every matching order, in the list's order, one at a time — the Export
     * Center's read (SalesOrdersExport, Phase 4.5): the SAME filters and the
     * SAME ordering as paginate(), off the same builder, so a file can never
     * carry rows the screen would not, nor in another order. Read a chunk
     * per query (Builder::lazy — Builder::cursor() would skip the eager
     * loads the resource prints), never the whole result.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, SalesOrder>
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
     * One order with its whole chain — the show endpoint. Loads what the
     * resource prints and builds `trace` (deliveries with cartons and Tally
     * links, invoices with Tally links) through SalesDocumentTraceService.
     */
    public function show(SalesOrder $order): SalesOrder
    {
        $this->decorate($order);
        $order->trace = $this->trace->orderTrace($order);

        return $order;
    }

    public function openCount(): int
    {
        return SalesOrder::query()
            ->whereIn('status', [SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyDelivered])
            ->count();
    }

    /**
     * Open orders with their lines, soonest promise first (undated last) —
     * the dashboard's order-book read.
     *
     * @return Collection<int, SalesOrder>
     */
    public function openWithLines(int $limit = 10): Collection
    {
        return SalesOrder::query()
            ->with(self::WITH)
            ->whereIn('status', [SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyDelivered])
            ->orderByRaw('expected_date IS NULL, expected_date')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array{customer_id: int, order_date: string, expected_date?: string, customer_po_reference?: string, notes?: string, lines: array<int, array{item_id: int, quantity: string, unit_price: string}>}  $data
     */
    public function create(array $data, ?int $createdBy): SalesOrder
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $order = SalesOrder::create([
                'customer_id' => $data['customer_id'],
                'status' => SalesOrderStatus::Draft,
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                // The customer's own PO number, recorded as typed — absent on
                // the phone orders that have none, and read by nothing else in
                // this build (no Tally voucher is emitted from it).
                'customer_po_reference' => $data['customer_po_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
            ]);

            foreach ($data['lines'] as $line) {
                $order->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'quantity_delivered' => 0,
                ]);
            }

            return $this->decorate($order);
        });
    }

    public function confirm(SalesOrder $order): SalesOrder
    {
        if ($order->status !== SalesOrderStatus::Draft) {
            throw InvalidStatusTransitionException::make(
                'sales order',
                $order->status->value,
                SalesOrderStatus::Confirmed->value,
            );
        }

        $order->update(['status' => SalesOrderStatus::Confirmed]);

        return $this->decorate($order);
    }

    /**
     * Cancel an order nothing has happened to yet (Phase 3.5). Allowed ONLY
     * from draft or confirmed with every line's quantity_delivered zero and
     * no invoice against it — SalesOrder::isCancellable(), the same rule
     * the resource's `can_cancel` reports. Anything else is
     * InvalidStatusTransitionException (a 422 on the wire, as elsewhere).
     *
     * A plain status change: touches no stock (nothing was ever issued),
     * fires no event, enqueues nothing for Tally — there is no Sales Order
     * voucher in the ERP's queue to withdraw (DEC-20260809-003: real sales
     * live in Tally). A cancelled order then refuses confirm, delivery and
     * invoice creation through the guards those paths already have.
     *
     * S6 — AND IT STOPS ASKING THE FACTORY FOR THINGS. Inside the same
     * transaction it gives up every hold the order's lines were sitting on
     * and withdraws every open production request behind them. Left standing,
     * those holds would keep finished goods away from orders that can still
     * use them and keep the floor making pieces for a customer who walked
     * away — the two most expensive silences this build could leave. Still no
     * stock moves: releasing a hold changes who stock is spoken for and
     * nothing else (invariant 1), and cancelling a request writes on a piece
     * of paper (invariant 2).
     */
    public function cancel(SalesOrder $order, ?int $userId = null): SalesOrder
    {
        // Judged and written under a row lock, in one transaction: a
        // dispatch or an invoice committing between a plain read and the
        // status write would leave a CANCELLED order that has a delivery —
        // the one state the page promises cannot exist. DeliveryService and
        // InvoiceService read the order inside their own transactions, so
        // the lock here is what serialises the two.
        $cancelled = DB::transaction(function () use ($order, $userId) {
            $fresh = SalesOrder::query()
                ->whereKey($order->getKey())
                ->with('lines')
                ->withCount('invoices')
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->isCancellable()) {
                throw InvalidStatusTransitionException::make(
                    'sales order',
                    $fresh->status->value,
                    SalesOrderStatus::Cancelled->value,
                );
            }

            $fresh->update(['status' => SalesOrderStatus::Cancelled]);

            // The order row is already locked, and it is the HEAD of the
            // global lock order (sales → material_bags → stock_balances →
            // stock_reservations → production_requests), so these two add
            // only locks that come after it. Reservations before requests,
            // in that order, for the same reason.
            $this->reservations->releaseForOrder($fresh, 'so_cancelled', $userId);
            $this->productionRequests->cancelForOrder($fresh, 'so_cancelled');

            return $fresh;
        });

        return $this->decorate($cancelled);
    }

    // ---- internals --------------------------------------------------------------

    /**
     * The list's builder: every filter applied, the relations and aggregates
     * the resource prints, then the list's order — what paginate() pages
     * and cursor() streams.
     *
     * @param  array<string, mixed>  $filters
     */
    private function listQuery(array $filters): Builder
    {
        $query = $this->withAggregates($this->filtered($filters)->with(self::WITH));
        $this->query->applySort($query, $filters['sort'] ?? null, ['order_date', 'expected_date']);

        return $query;
    }

    /**
     * The filtered orders, nothing loaded and nothing ordered — the one
     * builder listQuery() and count() both start from.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        $query = SalesOrder::query();
        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * Every filter of ListSalesOrdersRequest. `q` matches the order number
     * in any spelling ("SO-12", "so 12", "12") or the customer's name or
     * code — never notes.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
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
            $id = $this->query->documentId($term, 'SO');

            $query->where(function (Builder $any) use ($term, $id) {
                if ($id !== null) {
                    $any->orWhere('sales_orders.id', $id);
                }
                $any->orWhereHas('customer', fn (Builder $customer) => $this->query->whereCustomerMatches($customer, $term));
            });
        }
    }

    /** The counts and sums the resource prints (deliveries_count, invoices_count, invoiced_quantity), on a query. */
    private function withAggregates(Builder $query): Builder
    {
        return $query
            ->withCount(['deliveries', 'invoices'])
            ->withSum('invoiceLines as invoiced_quantity', 'quantity');
    }

    /** The same relations and aggregates, on ONE order the service is about to hand back. */
    private function decorate(SalesOrder $order): SalesOrder
    {
        return $order
            ->load(self::WITH)
            ->loadCount(['deliveries', 'invoices'])
            ->loadSum('invoiceLines as invoiced_quantity', 'quantity');
    }
}
