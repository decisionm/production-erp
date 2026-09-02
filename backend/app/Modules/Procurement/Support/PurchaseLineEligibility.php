<?php

namespace App\Modules\Procurement\Support;

use App\Modules\Inventory\Models\Enums\ItemCategory;

/**
 * DEC-20260902-023, the server half. Called from the two purchase FormRequests'
 * `withValidator` hooks so a client that posts JSON meets the same refusal the
 * picker shows.
 *
 * Reads no model directly — CLAUDE.md requires cross-module reads to go
 * through the other module's Service, so the caller resolves categories via
 * `App\Modules\Inventory\Services\ItemService::categoriesFor()` and hands the
 * resulting map in here.
 */
final class PurchaseLineEligibility
{
    /**
     * @param  array<int, array{item_id?: int|string, unclassified_reason?: string|null}>  $lines
     * @param  callable(string $key, string $message): void  $fail
     * @param  array<int, ?ItemCategory>  $categories  item id => category, as returned by ItemService::categoriesFor(); a missing key means the id named no item at all
     */
    public static function validate(array $lines, callable $fail, array $categories): void
    {
        foreach ($lines as $index => $line) {
            $itemId = isset($line['item_id']) ? (int) $line['item_id'] : null;

            // A missing key means the id named no item at all (never
            // created, or a soft-deleted row categoriesFor's default scope
            // does not see) — `exists:items,id` or PurchasableItem already
            // reports that; this rule must not pile an unrelated
            // "unclassified" error on top of it.
            if ($itemId === null || ! array_key_exists($itemId, $categories)) {
                continue;
            }

            $category = $categories[$itemId];

            if ($category === ItemCategory::FinishedGood) {
                $fail("lines.{$index}.item_id", 'A finished good is not purchased.');

                continue;
            }
            if ($category === null && trim((string) ($line['unclassified_reason'] ?? '')) === '') {
                $fail("lines.{$index}.unclassified_reason", 'An unclassified item needs a reason.');
            }
        }
    }
}
