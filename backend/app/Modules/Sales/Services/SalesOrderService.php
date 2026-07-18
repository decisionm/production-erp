<?php

namespace App\Modules\Sales\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return SalesOrder::query()
            ->with(['customer', 'lines.item'])
            ->orderByDesc('id')
            ->paginate($perPage);
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

            return $order->load(['customer', 'lines.item']);
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

        return $order;
    }
}
