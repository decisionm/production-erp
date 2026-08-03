<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;

/**
 * What a Tally Stock Summary WOULD mean here, reported without writing a thing.
 *
 * This exists because the ERP has never had an authoritative opening position.
 * The masters sync carries item and godown names but no quantities, so the
 * rehearsal was filled with hand-typed "provisional" figures — 9,000 kg of
 * resin among them — which then became indistinguishable from real stock. The
 * fix is to read the books; the safeguard is to read them out loud first.
 *
 * THREE RULES, all of them the point rather than decoration:
 *
 *  - **Never fuzzy-match.** Every line joins on the Tally item GUID the ERP
 *    already stores. A line whose GUID matches nothing is reported as unmapped,
 *    never attached to the nearest-looking product. Name similarity is how the
 *    wrong bottle gets an opening balance.
 *  - **Never cross companies.** A godown this ERP does not hold under the bound
 *    company is flagged, not accepted. Six foreign godowns already entered this
 *    system that way and today's live batches issued through them.
 *  - **Never write.** No import, no stock movement, no voucher. The import is a
 *    separate, explicitly-approved act.
 */
class StockSummaryPreviewService
{
    /**
     * @param  list<array{item_guid: string, item_name: string, unit: ?string, godown: ?string,
     *     closing_quantity: ?string, closing_rate: ?string, closing_value: ?string}>  $lines
     * @return array{totals: array<string, int>, lines: list<array<string, mixed>>}
     */
    public function preview(array $lines, ?string $boundCompanyGuidPrefix = null): array
    {
        // Two lookups, both built once. A per-line query over a real catalogue
        // (653 items) would turn a preview into a minute of database work.
        $itemsByGuid = Item::query()
            ->withTrashed()
            ->whereNotNull('tally_stock_item_guid')
            ->get(['id', 'name', 'uom', 'tally_stock_item_guid', 'is_active', 'deleted_at'])
            ->keyBy('tally_stock_item_guid');

        $warehousesByName = Warehouse::query()
            ->withTrashed()
            ->get(['id', 'name', 'code', 'tally_guid', 'is_active'])
            // Godowns arrive by NAME (Tally's batch allocations carry no godown
            // GUID), so this one join is on text — but it only ever RESOLVES an
            // existing ERP row, and a miss is reported rather than guessed at.
            ->keyBy(fn (Warehouse $w) => mb_strtolower(trim((string) $w->name)));

        $out = [];
        $mapped = 0;
        $unmapped = 0;
        $foreignGodown = 0;

        foreach ($lines as $line) {
            $guid = (string) ($line['item_guid'] ?? '');
            $item = $guid === '' ? null : $itemsByGuid->get($guid);

            $godownName = $line['godown'] ?? null;
            $warehouse = $godownName === null
                ? null
                : $warehousesByName->get(mb_strtolower(trim($godownName)));

            // A godown belongs to another Tally company when its stored GUID
            // does not start with the company prefix this ERP is bound to.
            $godownCompanyMismatch = $warehouse !== null
                && $boundCompanyGuidPrefix !== null
                && $warehouse->tally_guid !== null
                && ! str_starts_with((string) $warehouse->tally_guid, $boundCompanyGuidPrefix);

            $problems = [];

            if ($item === null) {
                $problems[] = $guid === ''
                    ? 'This line carries no Tally item id, so it cannot be matched to a product.'
                    : 'No product in this system carries that exact Tally item. It has not been matched to anything.';
                $unmapped++;
            } else {
                $mapped++;

                if ($item->deleted_at !== null) {
                    $problems[] = 'The matching product has been deleted here.';
                }
                if (! $item->is_active) {
                    $problems[] = 'The matching product is not active here.';
                }
            }

            if ($godownName !== null && $warehouse === null) {
                $problems[] = 'No warehouse here is named exactly like that godown.';
            }

            if ($godownCompanyMismatch) {
                $problems[] = 'That godown belongs to a DIFFERENT Tally company. Nothing may be imported against it.';
                $foreignGodown++;
            }

            if ($godownName === null) {
                $problems[] = 'Tally gave no godown for this line, so there is nowhere to put the opening balance.';
            }

            $out[] = [
                'item_guid' => $guid,
                'tally_item_name' => $line['item_name'] ?? null,
                'unit' => $line['unit'] ?? null,
                'godown' => $godownName,
                'closing_quantity' => $line['closing_quantity'] ?? null,
                'closing_rate' => $line['closing_rate'] ?? null,
                'closing_value' => $line['closing_value'] ?? null,
                'erp_item_id' => $item?->id,
                'erp_item_name' => $item?->name,
                'erp_unit' => $item?->uom,
                'erp_warehouse_id' => $warehouse?->id,
                'erp_warehouse_name' => $warehouse?->name,
                'importable' => $problems === [],
                'problems' => $problems,
            ];
        }

        return [
            'totals' => [
                'lines' => count($out),
                'mapped' => $mapped,
                'unmapped' => $unmapped,
                'foreign_godown' => $foreignGodown,
                'importable' => count(array_filter($out, fn (array $r) => $r['importable'])),
            ],
            'lines' => $out,
        ];
    }
}
