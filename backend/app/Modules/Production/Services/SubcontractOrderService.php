<?php

namespace App\Modules\Production\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Exceptions\MissingBomException;
use App\Modules\Production\Http\Requests\ListSubcontractOrdersRequest;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\SubcontractOrderStatus;
use App\Modules\Production\Models\SubcontractOrder;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Job-work / subcontracting: materials are sent out to a vendor for
 * outside processing and the finished item comes back — structurally the
 * same shape as WorkOrderService (create snapshots a BOM into required
 * quantities, sendMaterials() issues them, receive() receives the
 * processed output) with one real difference: the received item's cost
 * includes an explicit service_cost (the subcontractor's processing fee),
 * not just materials, since a vendor labor charge isn't something
 * Inventory's stock valuation has any way to know about on its own.
 */
class SubcontractOrderService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly BomService $boms,
    ) {}

    public function paginate(int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        $query = SubcontractOrder::query()
            ->with(['vendor', 'item', 'warehouse', 'materials.component']);

        return ListSort::apply($query, $sort, ListSubcontractOrdersRequest::SORTABLE)->paginate($perPage);
    }

    /**
     * @param  array{vendor_id: int, item_id: int, bom_id?: int, warehouse_id: int, quantity_planned: string}  $data
     */
    public function create(array $data): SubcontractOrder
    {
        return DB::transaction(function () use ($data) {
            $bom = isset($data['bom_id'])
                ? Bom::with('lines')->findOrFail($data['bom_id'])
                : $this->boms->activeFor($data['item_id']);

            if (! $bom) {
                throw MissingBomException::forItem($data['item_id']);
            }

            $order = SubcontractOrder::create([
                'vendor_id' => $data['vendor_id'],
                'item_id' => $data['item_id'],
                'bom_id' => $bom->id,
                'warehouse_id' => $data['warehouse_id'],
                'quantity_planned' => $data['quantity_planned'],
                'quantity_received' => 0,
                'materials_cost' => 0,
                'service_cost' => 0,
                'total_cost' => 0,
                'status' => SubcontractOrderStatus::Draft,
            ]);

            foreach ($bom->lines as $line) {
                $order->materials()->create([
                    'component_item_id' => $line->component_item_id,
                    'quantity_required' => bcmul($line->quantity_per, (string) $data['quantity_planned'], 4),
                ]);
            }

            return $order->load(['vendor', 'item', 'warehouse', 'materials.component']);
        });
    }

    public function sendMaterials(SubcontractOrder $order): SubcontractOrder
    {
        if ($order->status !== SubcontractOrderStatus::Draft) {
            throw InvalidStatusTransitionException::make(
                'subcontract order',
                $order->status->value,
                SubcontractOrderStatus::MaterialsSent->value,
            );
        }

        return DB::transaction(function () use ($order) {
            $materialsCost = '0.0000';

            foreach ($order->materials as $material) {
                $movement = $this->stock->recordIssue(
                    itemId: $material->component_item_id,
                    warehouseId: $order->warehouse_id,
                    quantity: (string) $material->quantity_required,
                    reference: "SCO #{$order->id}",
                );

                $materialsCost = bcadd($materialsCost, bcmul($material->quantity_required, $movement->unit_cost, 4), 4);
                $material->update(['quantity_sent' => $material->quantity_required]);
            }

            $order->update([
                'status' => SubcontractOrderStatus::MaterialsSent,
                'materials_sent_at' => now(),
                'materials_cost' => $materialsCost,
                'total_cost' => bcadd($materialsCost, $order->service_cost, 4),
            ]);

            return $order->fresh(['vendor', 'item', 'warehouse', 'materials.component']);
        });
    }

    public function receive(SubcontractOrder $order, string $quantityReceived, string $serviceCost): SubcontractOrder
    {
        if ($order->status !== SubcontractOrderStatus::MaterialsSent) {
            throw InvalidStatusTransitionException::make(
                'subcontract order',
                $order->status->value,
                SubcontractOrderStatus::Completed->value,
            );
        }

        return DB::transaction(function () use ($order, $quantityReceived, $serviceCost) {
            $totalCost = bcadd($order->materials_cost, $serviceCost, 4);
            $unitCost = bcdiv($totalCost, $quantityReceived, 4);

            $this->stock->recordReceipt(
                itemId: $order->item_id,
                warehouseId: $order->warehouse_id,
                quantity: $quantityReceived,
                unitCost: $unitCost,
                reference: "SCO #{$order->id}",
            );

            $order->update([
                'status' => SubcontractOrderStatus::Completed,
                'completed_at' => now(),
                'quantity_received' => $quantityReceived,
                'service_cost' => $serviceCost,
                'total_cost' => $totalCost,
            ]);

            return $order->fresh(['vendor', 'item', 'warehouse', 'materials.component']);
        });
    }
}
