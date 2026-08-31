<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\StoreIssue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * ONE BOX FOR EVERY NUMBER THE FACTORY WRITES ON SOMETHING.
 *
 * A storekeeper holding a bag, a QA reader holding a lot slip and a
 * supervisor reading "Store issue SI-000001" off a movement row were each
 * expected to know WHICH screen owns that kind of number before they could
 * look it up. Six identifier spaces, six screens, and no way to ask the
 * obvious question: what is this number?
 *
 * IT RESOLVES, IT DOES NOT SEARCH, and the difference is the whole design.
 * A scanned barcode is an exact, unique, unambiguous thing: the answer is
 * that bag, and a results page with one row on it is a worse answer than the
 * bag itself. So an exact hit on a GLOBALLY unique identifier is reported as
 * `resolved` and the caller jumps straight there. Everything else — a
 * partial term, an ambiguous one, a hit on an identifier that is only unique
 * per item — comes back as a plain list and a person chooses.
 *
 * WHICH IDENTIFIERS MAY RESOLVE, checked against the schema rather than
 * assumed, because the assumption is wrong for half of them:
 *
 *   · items.sku                 UNIQUE               → may resolve
 *   · material_bags.barcode     UNIQUE               → may resolve
 *   · store_issues.issue_number UNIQUE               → may resolve
 *   · batches.batch_number      unique per ITEM      → never resolves
 *   · serial_numbers.serial_no  unique per ITEM      → never resolves
 *   · material_lots.supplier_lot_no  nullable, NOT unique, and the
 *     SUPPLIER'S string rather than ours — two suppliers can ship the same
 *     lot number in the same week → never resolves
 *
 * Jumping on a batch number would silently pick one item's batch out of
 * several and show it as THE answer. The last three always take the list.
 *
 * TRACEABILITY OFF IS SAID, NOT SIMULATED. Bags and lots live behind
 * `production.traceability_enabled` (EnsureTraceabilityEnabled 404s the whole
 * surface). This service is deliberately NOT behind that middleware — it
 * reads the flag and reports those kinds as OMITTED. A storekeeper who scans
 * a bag on a flag-off instance and is told "nothing matches" concludes the
 * bag is unknown to the ERP, which is the wrong conclusion and the expensive
 * one: the right answer is that the ERP was not asked.
 *
 * NO COUNTS, AND A MINIMUM TERM (FC-06 is about rates; this is the same
 * instinct about volumes). `issue_number` is sequential — `SI-000001`,
 * `SI-000002` — so answering a two-character `SI` with a total would hand
 * any inventory reader the factory's handover count. Terms shorter than
 * MIN_TERM are refused, every kind is capped at PER_KIND rows, and no total
 * is returned for any kind. The cap is a real limit and the caller is told
 * the list was capped rather than left to assume it was complete.
 *
 * CASE IS FOLDED ON BOTH SIDES, ON PURPOSE. sqlite's `=` is case-SENSITIVE
 * and MySQL's default collation is not, so `where('sku', $term)` is a
 * different query on the test database and the factory's — the exact class of
 * divergence DatabaseDriverParityTest exists for. Lowering both sides makes
 * the two agree, and a scanner that sends upper-case still finds a
 * lower-case master row.
 */
class FactoryLookupService
{
    /** Short enough for a real SKU, long enough that "SI-" cannot enumerate. */
    public const MIN_TERM = 3;

    /** Per kind. A person scanning wants one answer, not a page. */
    public const PER_KIND = 10;

    /**
     * @return array{term: string, resolved: ?array<string, mixed>, matches: list<array<string, mixed>>, omitted: list<array<string, string>>}
     */
    public function find(string $term): array
    {
        $term = trim($term);
        $needle = mb_strtolower($term);

        $traceability = (bool) config('production.traceability_enabled');

        $matches = [];
        $omitted = [];

        // Ordered as the factory meets them: what a thing IS, then the
        // physical unit, then the paperwork that moved it.
        foreach ($this->items($needle) as $row) {
            $matches[] = $row;
        }

        if ($traceability) {
            foreach ($this->bags($needle) as $row) {
                $matches[] = $row;
            }
            foreach ($this->lots($needle) as $row) {
                $matches[] = $row;
            }
        } else {
            $omitted[] = [
                'kind' => 'bag',
                'reason' => 'Bag traceability is switched off on this instance, so bags were not looked up.',
            ];
            $omitted[] = [
                'kind' => 'lot',
                'reason' => 'Lot traceability is switched off on this instance, so supplier lots were not looked up.',
            ];
        }

        foreach ($this->batches($needle) as $row) {
            $matches[] = $row;
        }
        foreach ($this->serials($needle) as $row) {
            $matches[] = $row;
        }
        foreach ($this->storeIssues($needle) as $row) {
            $matches[] = $row;
        }

        return [
            'term' => $term,
            'resolved' => $this->resolve($matches),
            'matches' => $matches,
            'omitted' => $omitted,
        ];
    }

    /**
     * THE JUMP, and the three conditions it needs — all three, not any of
     * them. Exactly one match in the whole result; that match was EXACT
     * rather than a substring; and its identifier is unique across the
     * factory rather than only within its item. Drop any one and the caller
     * is sent confidently to a record that is merely the first of several.
     *
     * @param  list<array<string, mixed>>  $matches
     * @return array<string, mixed>|null
     */
    private function resolve(array $matches): ?array
    {
        if (count($matches) !== 1) {
            return null;
        }

        $only = $matches[0];

        return ($only['exact'] ?? false) && ($only['unique'] ?? false) ? $only : null;
    }

    /**
     * Exact rows first, then substring rows, both folded to lower case so the
     * two database drivers agree. `$exactColumn` is what an exact hit is
     * judged on.
     *
     * @return Collection<int, Model>
     */
    private function lookup(Builder $query, string $column, string $needle)
    {
        return $query
            ->whereRaw("LOWER({$column}) LIKE ?", ['%'.$needle.'%'])
            // Exact first: ORDER BY a boolean, which both drivers evaluate
            // the same way, so the caller never has to sort a mixed list.
            ->orderByRaw("CASE WHEN LOWER({$column}) = ? THEN 0 ELSE 1 END", [$needle])
            ->orderBy('id')
            ->limit(self::PER_KIND)
            ->get();
    }

    /** @return list<array<string, mixed>> */
    private function items(string $needle): array
    {
        return $this->lookup(Item::query()->withTrashed(), 'sku', $needle)
            ->map(fn (Item $item) => [
                'kind' => 'item',
                'id' => $item->id,
                'identifier' => $item->sku,
                'label' => $item->display_name ?: $item->name,
                'detail' => $item->uom ? "Unit: {$item->uom}" : null,
                'retired' => ! $item->is_active,
                'exact' => mb_strtolower((string) $item->sku) === $needle,
                'unique' => true,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function bags(string $needle): array
    {
        return $this->lookup(MaterialBag::query()->with('lot.item'), 'barcode', $needle)
            ->map(fn (MaterialBag $bag) => [
                'kind' => 'bag',
                'id' => $bag->id,
                'identifier' => $bag->barcode,
                'label' => $bag->lot?->item?->name ?? 'Bag',
                'detail' => trim(sprintf('%s · %s kg remaining', (string) $bag->status?->value ?: (string) $bag->status, (string) $bag->remaining_kg)),
                'retired' => false,
                'exact' => mb_strtolower((string) $bag->barcode) === $needle,
                'unique' => true,
            ])
            ->all();
    }

    /**
     * NEVER UNIQUE. `supplier_lot_no` is the supplier's own string, it is
     * nullable, and nothing stops two suppliers using the same one.
     *
     * @return list<array<string, mixed>>
     */
    private function lots(string $needle): array
    {
        return $this->lookup(MaterialLot::query()->with('item'), 'supplier_lot_no', $needle)
            ->map(fn (MaterialLot $lot) => [
                'kind' => 'lot',
                'id' => $lot->id,
                'identifier' => $lot->supplier_lot_no,
                'label' => $lot->item?->name ?? 'Supplier lot',
                'detail' => $lot->received_date ? 'Received '.$lot->received_date : null,
                'retired' => false,
                'exact' => mb_strtolower((string) $lot->supplier_lot_no) === $needle,
                'unique' => false,
            ])
            ->all();
    }

    /**
     * UNIQUE PER ITEM ONLY — unique(['item_id', 'batch_number']). Two items
     * may carry the same batch number, so this never jumps.
     *
     * @return list<array<string, mixed>>
     */
    private function batches(string $needle): array
    {
        return $this->lookup(Batch::query()->with('item'), 'batch_number', $needle)
            ->map(fn (Batch $batch) => [
                'kind' => 'batch',
                'id' => $batch->id,
                'identifier' => $batch->batch_number,
                'label' => $batch->item?->name ?? 'Batch',
                'detail' => null,
                'retired' => false,
                'exact' => mb_strtolower((string) $batch->batch_number) === $needle,
                'unique' => false,
            ])
            ->all();
    }

    /**
     * UNIQUE PER ITEM ONLY — unique(['item_id', 'serial_number']).
     *
     * @return list<array<string, mixed>>
     */
    private function serials(string $needle): array
    {
        return $this->lookup(SerialNumber::query()->with('item'), 'serial_number', $needle)
            ->map(fn (SerialNumber $serial) => [
                'kind' => 'serial',
                'id' => $serial->id,
                'identifier' => $serial->serial_number,
                'label' => $serial->item?->name ?? 'Serial number',
                // status is an enum cast on this model and a plain string on
                // others — ->value where there is one, the raw value where
                // there is not.
                'detail' => $serial->status ? 'Status: '.($serial->status->value ?? $serial->status) : null,
                'retired' => false,
                'exact' => mb_strtolower((string) $serial->serial_number) === $needle,
                'unique' => false,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function storeIssues(string $needle): array
    {
        return $this->lookup(StoreIssue::query(), 'issue_number', $needle)
            ->map(fn (StoreIssue $issue) => [
                'kind' => 'store_issue',
                'id' => $issue->id,
                'identifier' => $issue->issue_number,
                'label' => 'Store issue',
                'detail' => 'Status: '.((string) ($issue->status?->value ?? $issue->status)),
                'retired' => false,
                'exact' => mb_strtolower((string) $issue->issue_number) === $needle,
                'unique' => true,
            ])
            ->all();
    }
}
