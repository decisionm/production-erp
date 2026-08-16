<?php

namespace App\Modules\Sales\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    /** Loaded on every order the service hands back, so the resource never lazy-loads. */
    private const WITH = ['customer', 'lines.item'];

    public function __construct(
        private readonly SalesDocumentQuery $query,
        private readonly SalesDocumentTraceService $trace,
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
        $query = $this->withAggregates(SalesOrder::query()->with(self::WITH));

        $this->applyFilters($query, $filters);
        $this->query->applySort($query, $filters['sort'] ?? null, ['order_date', 'expected_date']);

        return $query->paginate($perPage);
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
     * @param  array{customer_id: int, order_date: string, expected_date?: string, notes?: string, lines: array<int, array{item_id: int, quantity: string, unit_price: string}>}  $data
     */
    public function create(array $data, ?int $createdBy): SalesOrder
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $order = SalesOrder::create([
                'customer_id' => $data['customer_id'],
                'status' => SalesOrderStatus::Draft,
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
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
     */
    public function cancel(SalesOrder $order): SalesOrder
    {
        $order->loadMissing('lines');

        if (! $order->isCancellable()) {
            throw InvalidStatusTransitionException::make(
                'sales order',
                $order->status->value,
                SalesOrderStatus::Cancelled->value,
            );
        }

        $order->update(['status' => SalesOrderStatus::Cancelled]);

        return $this->decorate($order);
    }

    // ---- internals --------------------------------------------------------------

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
