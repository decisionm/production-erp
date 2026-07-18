<?php

namespace App\Modules\CRM\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\CRM\Models\Enums\QuotationStatus;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\CRM\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Accepting a quotation creates a real Sales Order via Sales' own
 * SalesOrderService — never touches Sales' tables directly — the same
 * cross-module-write-through-the-owning-Service rule Procurement/Sales
 * already follow for their Inventory calls.
 */
class QuotationService
{
    public function __construct(private readonly SalesOrderService $salesOrders) {}

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Quotation::query()
            ->with(['lines.item', 'customer', 'opportunity'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{opportunity_id: int, quotation_date: string, valid_until?: string, notes?: string, lines: array<int, array{item_id: int, quantity: string, unit_price: string}>}  $data
     */
    public function create(array $data, ?int $createdBy): Quotation
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $opportunity = Opportunity::findOrFail($data['opportunity_id']);

            $quotation = Quotation::create([
                'opportunity_id' => $opportunity->id,
                // Derived server-side rather than trusting a client-supplied
                // value — mirrors how Sales' Invoice derives customer_id
                // from its Sales Order.
                'customer_id' => $opportunity->customer_id,
                'status' => QuotationStatus::Draft,
                'quotation_date' => $data['quotation_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
            ]);

            foreach ($data['lines'] as $line) {
                $quotation->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                ]);
            }

            return $quotation->load(['lines.item', 'customer', 'opportunity']);
        });
    }

    public function send(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw InvalidStatusTransitionException::make(
                'quotation',
                $quotation->status->value,
                QuotationStatus::Sent->value,
            );
        }

        $quotation->update(['status' => QuotationStatus::Sent]);

        return $quotation;
    }

    /**
     * @return array{quotation: Quotation, sales_order: SalesOrder}
     */
    public function accept(Quotation $quotation, ?int $createdBy): array
    {
        if ($quotation->status !== QuotationStatus::Sent) {
            throw InvalidStatusTransitionException::make(
                'quotation',
                $quotation->status->value,
                QuotationStatus::Accepted->value,
            );
        }

        return DB::transaction(function () use ($quotation, $createdBy) {
            $salesOrder = $this->salesOrders->create([
                'customer_id' => $quotation->customer_id,
                'order_date' => now()->toDateString(),
                'notes' => "Created from Quotation #{$quotation->id}",
                'lines' => $quotation->lines->map(fn ($line) => [
                    'item_id' => $line->item_id,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                ])->all(),
            ], $createdBy);

            $quotation->update(['status' => QuotationStatus::Accepted]);

            return [
                'quotation' => $quotation->fresh(['lines.item', 'customer', 'opportunity']),
                'sales_order' => $salesOrder,
            ];
        });
    }

    public function reject(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Sent) {
            throw InvalidStatusTransitionException::make(
                'quotation',
                $quotation->status->value,
                QuotationStatus::Rejected->value,
            );
        }

        $quotation->update(['status' => QuotationStatus::Rejected]);

        return $quotation;
    }
}
