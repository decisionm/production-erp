<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Procurement\Exceptions\InvalidStatusTransitionException;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return PurchaseOrder::query()
            ->with(['vendor', 'lines.item'])
            ->orderByDesc('id')
            ->paginate($perPage);
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
            ]);

            foreach ($data['lines'] as $line) {
                $order->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'quantity_received' => 0,
                ]);
            }

            return $order->load(['vendor', 'lines.item']);
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
}
