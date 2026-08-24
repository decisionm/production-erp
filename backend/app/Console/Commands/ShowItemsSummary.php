<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use Illuminate\Console\Command;

/**
 * One read-only answer to "what is actually in the catalogue, and how much of
 * it could post to Tally?".
 *
 * THE THREE QUESTIONS THIS EXISTS FOR, asked by the owner 24-Aug-2026:
 * how many finished goods / packing materials / raw materials are there, do
 * the items have SKUs, and what is the Tally-sync state of each packing and
 * raw material. None of them could be answered: the Items screen pages
 * through a list, dev fixtures are not live-shaped (the 09-Aug shift-rail
 * defect came from trusting them), and AGENTS.md requires live data to be
 * counted on the live instance.
 *
 * WHY "CAN POST" IS NOT "HAS A GUID". An item posts only if it carries a
 * Tally GUID AND is not a local fixture — the same predicate the readiness
 * gate uses (ProductVariantService::hasTallyIdentity). A fixture's missing
 * GUID is DELIBERATE, not a gap: it exists in this database and nowhere in
 * Tally, and reporting it as unmapped would put a permanent false positive in
 * front of whoever works this list. Fixtures are counted and named on their
 * own line instead.
 *
 * WHY UNCLASSIFIED IS ITS OWN ROW AND NOT FOLDED INTO `other`. NULL category
 * means "nobody has said yet"; `other` means "somebody said: neither raw nor
 * packing" (ItemCategory's own docblock is explicit, and 458 of 624 active
 * items were unclassified when that enum was written). Merging them would
 * turn an open question into a stated answer, which is the one thing
 * AGENTS.md forbids doing on the factory's behalf. The distinction is load
 * bearing downstream: a purchase order may be raised for raw and packing
 * only, a sales order for finished goods only.
 *
 * Strictly read-only by construction: SELECTs and counting, no options that
 * write, no state touched — safe against the live database at any hour. It
 * creates nothing, so it needs no dry run and no confirmation gate.
 */
class ShowItemsSummary extends Command
{
    protected $signature = 'items:summary';

    protected $description = 'Read-only: how many items per category, how many carry a real SKU, and how many could post to Tally.';

    /** The row for an item whose category column is NULL. */
    public const UNCLASSIFIED = 'UNCLASSIFIED';

    public function handle(): int
    {
        // Archived items are counted but reported apart: a retired master is
        // not a gap to chase, and folding it into the live totals would
        // inflate every number this command exists to give.
        $active = Item::query()->where('is_active', true)->get();
        $archived = Item::query()->where('is_active', false)->count();
        $deleted = Item::onlyTrashed()->count();

        if ($active->isEmpty()) {
            $this->warn('No active items in this database.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'CATALOGUE — %d active, %d inactive, %d soft-deleted.',
            $active->count(),
            $archived,
            $deleted,
        ));
        $this->newLine();

        // ---- by category, each split by whether it could post -------------
        $this->line('BY CATEGORY (active items)');
        $this->line(sprintf('  %-18s %6s %10s %10s', '', 'items', 'can post', 'cannot'));

        $buckets = [];
        foreach (ItemCategory::cases() as $case) {
            $buckets[$case->value] = $active->filter(
                fn (Item $item) => $item->category?->value === $case->value,
            );
        }
        $buckets[self::UNCLASSIFIED] = $active->filter(fn (Item $item) => $item->category === null);

        foreach ($buckets as $label => $items) {
            $canPost = $items->filter(fn (Item $item) => $this->canPost($item))->count();

            $this->line(sprintf(
                '  %-18s %6d %10d %10d',
                $label,
                $items->count(),
                $canPost,
                $items->count() - $canPost,
            ));
        }

        // ---- the SKU question ---------------------------------------------
        $provisional = $active->filter(fn (Item $item) => (bool) $item->sku_provisional);
        $noSku = $active->filter(fn (Item $item) => trim((string) $item->sku) === '');
        $fixtures = $active->filter(fn (Item $item) => $item->isLocalFixture());

        $this->newLine();
        $this->line('SKUs');
        $this->line(sprintf('  %-46s %6d', 'carry a real SKU', $active->count() - $provisional->count() - $noSku->count()));
        $this->line(sprintf('  %-46s %6d', 'still provisional (seeded from the Tally name)', $provisional->count()));
        $this->line(sprintf('  %-46s %6d', 'no SKU at all', $noSku->count()));

        // ---- the Tally question, for the two categories that were asked ----
        $this->newLine();
        $this->line('TALLY POSTING READINESS');
        $this->line(sprintf(
            '  %-46s %6d',
            'local fixtures (no Tally item BY DESIGN)',
            $fixtures->count(),
        ));

        foreach ([ItemCategory::RawMaterial, ItemCategory::PackingMaterial, ItemCategory::FinishedGood] as $case) {
            $blocked = $buckets[$case->value]
                ->reject(fn (Item $item) => $this->canPost($item) || $item->isLocalFixture());

            $this->line(sprintf(
                '  %-46s %6d',
                "{$case->value} that cannot post (not a fixture)",
                $blocked->count(),
            ));

            // Name them, capped. A count tells somebody there is work; the
            // names are what lets them start it. The cap keeps a catalogue
            // with hundreds of gaps from burying the totals above it.
            foreach ($blocked->take(15) as $item) {
                $this->line(sprintf(
                    '      - %s [%s] uom=%s',
                    trim((string) $item->name) ?: "item #{$item->id}",
                    trim((string) $item->sku) ?: '-',
                    trim((string) $item->uom) ?: '?',
                ));
            }
            if ($blocked->count() > 15) {
                $this->line(sprintf('      … and %d more', $blocked->count() - 15));
            }
        }

        // ---- the verdict ---------------------------------------------------
        $unclassified = $buckets[self::UNCLASSIFIED]->count();
        $blockedTotal = $active
            ->reject(fn (Item $item) => $this->canPost($item) || $item->isLocalFixture())
            ->count();

        $this->newLine();
        if ($unclassified > 0) {
            $this->warn(sprintf(
                'VERDICT: %d of %d active items have NO category. Unclassified means nobody has said yet — not "none of the above" — so the purchase-order, sales-order and material-request rules cannot act on them.',
                $unclassified,
                $active->count(),
            ));
        } else {
            $this->info('VERDICT: every active item carries a category.');
        }

        if ($blockedTotal > 0) {
            $this->warn(sprintf(
                '         %d active items carry no Tally identity and are not fixtures — each is a line that cannot reach a voucher.',
                $blockedTotal,
            ));
        } else {
            $this->info('         Every non-fixture active item carries a Tally identity.');
        }

        return self::SUCCESS;
    }

    /**
     * The SAME predicate the readiness gate posts on
     * (ProductVariantService::hasTallyIdentity), not a second spelling of it:
     * a GUID, and not a local fixture. A report that disagrees with the gate
     * it describes would send somebody chasing a row that is already fine, or
     * miss one that is not.
     */
    private function canPost(Item $item): bool
    {
        return $item->tally_stock_item_guid !== null && ! $item->isLocalFixture();
    }
}
