<?php

namespace App\Modules\Core\Services;

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
    ) {}

    public function summary(): array
    {
        return [
            'inventory' => [
                'total_items' => $this->items->count(),
                'total_warehouses' => $this->warehouses->count(),
                'low_stock_items' => $this->stock->lowStockCount(),
            ],
            'procurement' => [
                'open_purchase_orders' => $this->purchaseOrders->openCount(),
                'pending_requisitions' => $this->purchaseRequisitions->pendingApprovalCount(),
            ],
            'production' => [
                'open_work_orders' => $this->workOrders->openCount(),
            ],
            'sales' => [
                'open_sales_orders' => $this->salesOrders->openCount(),
                'orders_awaiting_delivery' => $this->deliveries->pendingCount(),
                'receivables_outstanding' => $this->receivables->outstandingTotal(),
            ],
            'quality' => [
                'open_ncrs' => $this->ncrs->openCount(),
                'open_capas' => $this->capas->openCount(),
            ],
            'hrms' => [
                'pending_leave_requests' => $this->leaveRequests->pendingCount(),
            ],
            'crm' => [
                'open_leads' => $this->leads->openCount(),
                'open_opportunities' => $this->opportunities->openCount(),
            ],
            'maintenance' => [
                'open_work_orders' => $this->maintenanceWorkOrders->openCount(),
            ],
            'recent_work_orders' => $this->recentWorkOrders(),
            'recent_sales_orders' => $this->recentSalesOrders(),
        ];
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
