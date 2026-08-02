<?php

namespace App\Modules\Procurement\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return PurchaseOrder::query()
            ->with(['vendor', 'lines.item', 'lines.schedules'])
            ->orderByDesc('id')
            ->paginate($perPage);
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
}
