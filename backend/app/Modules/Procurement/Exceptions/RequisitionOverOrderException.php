<?php

namespace App\Modules\Procurement\Exceptions;

use App\Exceptions\DomainException;
use App\Modules\Inventory\Models\Item;
use RuntimeException;

/**
 * A purchase order would have ordered more of an item than the requisition
 * it answers asked for. Renders as a plain 422 (DomainException).
 *
 * The message NAMES EVERY offending item with all three figures, because
 * the buyer's next action depends on which item and by how much — a bare
 * "exceeds the requisition" sends them to count the other orders by hand,
 * which is the work this whole feature exists to remove. Quantities are
 * printed with their item's `uom` and never added together across items
 * (RequisitionCoverageService's class note).
 */
class RequisitionOverOrderException extends RuntimeException implements DomainException
{
    /**
     * @param  list<array{item_id: int, requested: string, ordered: string, excess: string}>  $overOrdered
     */
    public static function forItems(int $requisitionId, array $overOrdered): self
    {
        $names = Item::query()
            ->whereIn('id', array_column($overOrdered, 'item_id'))
            ->get(['id', 'sku', 'name', 'display_name', 'uom'])
            ->keyBy('id');

        $clauses = array_map(function (array $over) use ($names, $requisitionId): string {
            /** @var Item|null $item */
            $item = $names->get($over['item_id']);
            $label = $item?->display_name ?: $item?->name ?: "item #{$over['item_id']}";
            $uom = trim((string) ($item?->uom ?? ''));
            $unit = $uom === '' ? '' : " {$uom}";

            return "{$label} — PR-{$requisitionId} asked for {$over['requested']}{$unit}, "
                ."this and every other open order would make {$over['ordered']}{$unit} "
                ."({$over['excess']}{$unit} too many)";
        }, $overOrdered);

        return new self(
            'This order would exceed what PR-'.$requisitionId.' asked for: '
            .implode('; ', $clauses)
            .'. Reduce the quantity, or cancel an order raised earlier against the same requisition.'
        );
    }
}
