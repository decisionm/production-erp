<?php

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\OpportunityService;
use App\Modules\Finance\Services\AccountsReceivableService;
use App\Modules\HRMS\Services\LeaveRequestService;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Procurement\Services\PurchaseRequisitionService;
use App\Modules\Production\Services\ProductionStandardResolver;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Services\CapaService;
use App\Modules\Quality\Services\NonConformanceReportService;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\SalesOrderService;

/**
 * A cross-module read aggregator for the dashboard landing page. Every
 * figure here is fetched through the owning module's own Service class
 * (never a raw Eloquent query against another module's tables), same rule
 * as any other cross-module read in this codebase — this class just fans
 * out to a lot of them at once.
 */
class DashboardService
{
    public function __construct(
        private readonly ItemService $items,
        private readonly WarehouseService $warehouses,
        private readonly StockMovementService $stock,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly PurchaseRequisitionService $purchaseRequisitions,
        private readonly WorkOrderService $workOrders,
        private readonly SalesOrderService $salesOrders,
        private readonly DeliveryService $deliveries,
        private readonly AccountsReceivableService $receivables,
        private readonly NonConformanceReportService $ncrs,
        private readonly CapaService $capas,
        private readonly LeaveRequestService $leaveRequests,
        private readonly MaintenanceWorkOrderService $maintenanceWorkOrders,
        private readonly LeadService $leads,
        private readonly OpportunityService $opportunities,
        private readonly ProductionStandardResolver $standards,
    ) {}

    /**
     * Only the blocks this user's role may see. The frontend hides cells by
     * the same permissions, but hiding is not security — the figures
     * themselves must not leave the server for a user who cannot open the
     * module behind them.
     */
    public function summary(User $user): array
    {
        $sees = fn (string $module): bool => $user->can("{$module}.view") || $user->can("{$module}.manage");

        $summary = [];

        if ($sees('inventory')) {
            $summary['inventory'] = [
                'total_items' => $this->items->count(),
                'total_warehouses' => $this->warehouses->count(),
                'low_stock_items' => $this->stock->lowStockCount(),
            ];
        }
        if ($sees('procurement')) {
            $summary['procurement'] = [
                'open_purchase_orders' => $this->purchaseOrders->openCount(),
                'pending_requisitions' => $this->purchaseRequisitions->pendingApprovalCount(),
            ];
            $summary['incoming_stock'] = $this->incomingStock();
        }
        if ($sees('production')) {
            $summary['production'] = [
                'open_work_orders' => $this->workOrders->openCount(),
            ];
            $summary['recent_work_orders'] = $this->recentWorkOrders();
        }
        if ($sees('sales')) {
            $summary['sales'] = [
                'open_sales_orders' => $this->salesOrders->openCount(),
                // Open orders whose promise date the factory's calendar has
                // already passed. A wider set than open_sales_orders above
                // (drafts count here), so it can read higher — it is not a
                // subset of the figure beside it.
                'overdue_sales_orders' => $this->salesOrders->overdueOpenCount(),
                'orders_awaiting_delivery' => $this->deliveries->pendingCount(),
                'receivables_outstanding' => $this->receivables->outstandingTotal(),
            ];
            $summary['demand'] = $this->demand();
            $summary['recent_sales_orders'] = $this->recentSalesOrders();
        }
        if ($sees('quality')) {
            $summary['quality'] = [
                'open_ncrs' => $this->ncrs->openCount(),
                'open_capas' => $this->capas->openCount(),
            ];
        }
        if ($sees('hrms')) {
            $summary['hrms'] = [
                'pending_leave_requests' => $this->leaveRequests->pendingCount(),
            ];
        }
        if ($sees('crm')) {
            $summary['crm'] = [
                'open_leads' => $this->leads->openCount(),
                'open_opportunities' => $this->opportunities->openCount(),
            ];
        }
        if ($sees('maintenance')) {
            $summary['maintenance'] = [
                'open_work_orders' => $this->maintenanceWorkOrders->openCount(),
            ];
        }

        return $summary;
    }

    /**
     * Stock on its way in: sent / partially received POs, soonest first.
     *
     * @return array<int, array{id: int, vendor: string, expected_date: ?string, status: string, items: string}>
     */
    private function incomingStock(): array
    {
        return $this->purchaseOrders->upcoming(5)
            ->map(function ($order) {
                $names = $order->lines->map(fn ($line) => $line->item->name);

                return [
                    'id' => $order->id,
                    'vendor' => $order->vendor->name,
                    'expected_date' => $order->expected_date?->toDateString(),
                    'status' => $order->status->value,
                    'items' => $names->take(3)->join(', ').($names->count() > 3 ? ', …' : ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The order book against the shelf: every undelivered open sales-order
     * line, what is on hand for it, and — where the product carries exactly
     * one usable standard rate — the running time the shortfall costs at
     * that rate.
     *
     * The time figure is an ESTIMATE at the standard cycle time, nothing
     * more: no downtime, no mould changes, no machine assignment. A product
     * with no recorded standard gets no figure (never interpolated), and one
     * whose variants disagree on rate is reported ambiguous rather than
     * silently averaged.
     *
     * @return array<int, array{sales_order_id: int, customer: string, expected_date: ?string,
     *     item: string, ordered: string, delivered: string, remaining: string, on_hand: string,
     *     to_produce: string, standard: string, hours_at_standard: ?float}>
     */
    private function demand(): array
    {
        $rows = [];

        foreach ($this->salesOrders->openWithLines() as $order) {
            foreach ($order->lines as $line) {
                $remaining = bcsub($line->quantity, $line->quantity_delivered ?? '0', 4);
                if (bccomp($remaining, '0', 4) <= 0) {
                    continue;
                }

                $onHand = $this->stock->totalOnHand($line->item_id);
                $toProduce = bccomp($remaining, $onHand, 4) > 0 ? bcsub($remaining, $onHand, 4) : '0.0000';

                // One usable (cycle time, cavities) pair across the product's
                // variants means the rate is unambiguous even when the pack
                // variants differ; none means no figure; several mean a
                // person must choose, not this page.
                $ratePairs = $this->standards->variantsFor($line->item_id)
                    ->filter(fn ($s) => $s->cycle_time !== null && $s->cavities !== null)
                    ->map(fn ($s) => ['cycle_time' => (float) $s->cycle_time, 'cavities' => $s->cavities])
                    ->unique()
                    ->values();

                $standard = match (true) {
                    $ratePairs->isEmpty() => 'none',
                    $ratePairs->count() === 1 => 'ok',
                    default => 'ambiguous',
                };

                $hours = null;
                if ($standard === 'ok' && bccomp($toProduce, '0', 4) > 0) {
                    $rate = $ratePairs->first();
                    $hours = round(((float) $toProduce) * $rate['cycle_time'] / $rate['cavities'] / 3600, 1);
                }

                $rows[] = [
                    'sales_order_id' => $order->id,
                    'customer' => $order->customer->name,
                    'expected_date' => $order->expected_date?->toDateString(),
                    'item' => "{$line->item->sku} — {$line->item->name}",
                    'ordered' => (string) $line->quantity,
                    'delivered' => (string) ($line->quantity_delivered ?? '0'),
                    'remaining' => $remaining,
                    'on_hand' => $onHand,
                    'to_produce' => $toProduce,
                    'standard' => $standard,
                    'hours_at_standard' => $hours,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array{id: int, item: string, status: string, quantity_planned: string, quantity_completed: string}>
     */
    private function recentWorkOrders(): array
    {
        return $this->workOrders->paginate(5)
            ->getCollection()
            ->map(fn ($order) => [
                'id' => $order->id,
                'item' => "{$order->item->sku} — {$order->item->name}",
                'status' => $order->status->value,
                'quantity_planned' => (string) $order->quantity_planned,
                'quantity_completed' => (string) $order->quantity_completed,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, customer: string, status: string, order_date: ?string}>
     */
    private function recentSalesOrders(): array
    {
        return $this->salesOrders->paginate(5)
            ->getCollection()
            ->map(fn ($order) => [
                'id' => $order->id,
                'customer' => $order->customer->name,
                'status' => $order->status->value,
                'order_date' => $order->order_date?->toDateString(),
            ])
            ->values()
            ->all();
    }
}
