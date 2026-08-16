<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * One read-only answer to "did a local-only fixture reach anywhere it must
 * never post from?" — the detector behind the Phase 1 deferral
 * (MASTER-PLAN P1-04: close Hole B, then AUDIT live for what the old hole
 * let through; P3-05 keeps it).
 *
 * A LOCAL- fixture exists in this database and in no Tally company
 * (Item::isLocalFixture — the column OR the SKU prefix, either signal), so
 * a voucher naming it can only fail. Three places it can have reached:
 *
 *   (a) production_standard_packagings.item_id — a packaging IDENTITY that
 *       is a fixture. Every batch packed that way freezes the fixture as
 *       its finished item and is then declined at the posting gate, for a
 *       product that IS in Tally. The edit surface refuses this now
 *       (PackagingIdentityRefusesFixtureTest); rows written before it do
 *       not repair themselves.
 *   (b) shift_production_entries whose EFFECTIVE identity is a fixture AND
 *       that carry a tally_sync_entry_id — vouchered under the old sweep
 *       that judged only the base item's SKU (Hole B). The rebuilds now
 *       leave such a member's lines off the payload; its stamp is left as
 *       it was, and this is where a person sees it.
 *   (c) tally_sync_entries whose payload NAMES a fixture item on any line
 *       — a voucher that can never be accepted, whatever its status says.
 *
 * REPORT ONLY. Three reads and a verdict, exit code 0 always: what to do
 * with a finding on live — dismiss the voucher, re-point the packaging,
 * leave history as it is — is the owner's call (AGENTS.md: an agent
 * proposes; the owner decides), and a detector that also repaired would be
 * making that call in a maintenance window. Mirrors roles:show — safe
 * against the live database at any hour, and its test pins that the
 * database is byte-identical before and after.
 */
class AuditLocalFixtures extends Command
{
    protected $signature = 'tally-sync:audit-fixtures';

    protected $description = 'Read-only: lists packaging identities, vouchered production entries and queued vouchers that name a local-only fixture item. Reports; changes nothing.';

    public const string VERDICT_CLEAN = 'VERDICT: clean — no packaging identity, vouchered entry or queued voucher names a local-only fixture.';

    public function handle(): int
    {
        // Trashed items INCLUDED on purpose: a fixture that was later
        // soft-deleted is still a name Tally has never had, and a voucher
        // that names it still cannot post. The predicate itself is the
        // model's, once — the SQL below only narrows the candidates.
        $fixtures = Item::withTrashed()
            ->where(fn ($query) => $query
                ->where('is_local_fixture', true)
                ->orWhere('sku', 'like', Item::LOCAL_FIXTURE_SKU_PREFIX.'%'))
            ->orderBy('id')
            ->get()
            ->filter(fn (Item $item) => $item->isLocalFixture())
            ->values();

        $this->line(sprintf('Local-only fixture items: %d', $fixtures->count()));
        foreach ($fixtures as $item) {
            $this->line(sprintf('  item #%d %s "%s"%s', $item->id, $item->sku, $item->name, $item->trashed() ? '  (deleted)' : ''));
        }
        $this->newLine();

        $packagings = $this->fixturePackagings();
        $this->line('(a) production_standard_packagings whose item_id is a local-only fixture:');
        $this->listOrNone($packagings, fn (ProductionStandardPackaging $packaging) => sprintf(
            '  packaging #%d  standard #%d "%s"  %s  item #%d %s "%s"',
            $packaging->id,
            $packaging->production_standard_id,
            $packaging->standard?->source_product_name ?? '?',
            $packaging->mode,
            $packaging->tallyItem->id,
            $packaging->tallyItem->sku,
            $packaging->tallyItem->name,
        ));
        $this->newLine();

        $vouchered = $this->fixtureVoucheredEntries();
        // The membership column is a plain id (the model carries no belongsTo
        // for it — the morph runs the other way), so the vouchers are read
        // once, by id, for the status each line shows.
        $vouchers = TallySyncEntry::query()
            ->whereIn('id', $vouchered->pluck('tally_sync_entry_id')->unique()->all())
            ->get()
            ->keyBy('id');
        $this->line('(b) shift_production_entries whose effective identity is a fixture AND that carry a voucher (tally_sync_entry_id):');
        $this->listOrNone($vouchered, function (ShiftProductionEntry $entry) use ($vouchers) {
            $item = $entry->effectiveItem();
            $voucher = $vouchers->get($entry->tally_sync_entry_id);

            return sprintf(
                '  entry #%d  batch %s  %s  effective item #%d %s  voucher #%d %s status=%s',
                $entry->id,
                $entry->batch_number ?? '(none)',
                $entry->production_date?->toDateString() ?? '?',
                $item?->id ?? 0,
                $item?->sku ?? '?',
                $entry->tally_sync_entry_id,
                $voucher?->voucherNumber() ?? '?',
                $voucher?->status?->value ?? 'missing',
            );
        });
        $this->newLine();

        $naming = $this->vouchersNamingAFixture($fixtures->pluck('name')->all());
        $this->line('(c) tally_sync_entries whose payload names a fixture item:');
        $this->listOrNone($naming, fn (array $row) => sprintf(
            '  voucher #%d  %s %s status=%s  names: %s',
            $row['voucher']->id,
            $row['voucher']->tally_voucher_type,
            $row['voucher']->voucherNumber(),
            $row['voucher']->status->value,
            implode(', ', $row['names']),
        ));
        $this->newLine();

        if ($packagings->isEmpty() && $vouchered->isEmpty() && $naming->isEmpty()) {
            $this->info(self::VERDICT_CLEAN);
        } else {
            $this->warn(sprintf(
                'VERDICT: %d packagings, %d vouchered entries, %d vouchers name a fixture — report only; nothing was changed. The owner decides what to do with each (Phase 1 deferral).',
                $packagings->count(),
                $vouchered->count(),
                $naming->count(),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * (a): judged on the packaging's OWN item relation, so "resolves to a
     * fixture" means exactly what the run's freeze would resolve to.
     *
     * @return Collection<int, ProductionStandardPackaging>
     */
    private function fixturePackagings(): Collection
    {
        return ProductionStandardPackaging::query()
            ->whereNotNull('item_id')
            ->with(['tallyItem', 'standard'])
            ->orderBy('id')
            ->get()
            ->filter(fn (ProductionStandardPackaging $packaging) => $packaging->tallyItem?->isLocalFixture() ?? false)
            ->values();
    }

    /**
     * (b): THE ONE PREDICATE (ShiftProductionEntry::isLocalFixtureIdentity)
     * — the same answer the enqueue guard, the sweep and the rebuilds give,
     * so this list can never name an entry those would have posted, or miss
     * one they would refuse.
     *
     * @return Collection<int, ShiftProductionEntry>
     */
    private function fixtureVoucheredEntries(): Collection
    {
        return ShiftProductionEntry::query()
            ->whereNotNull('tally_sync_entry_id')
            ->with(['item', 'finishedItem'])
            ->orderBy('id')
            ->get()
            ->filter(fn (ShiftProductionEntry $entry) => $entry->isLocalFixtureIdentity())
            ->values();
    }

    /**
     * (c): every line shape the builders write carries the Tally name under
     * `item` — lines[] (Sales / Receipt Note / Delivery Note), produced[] /
     * consumed[] / withheld[] (production). Exact-name match against the
     * fixture set, never fuzzy: a near-miss is not evidence.
     *
     * @param  list<string>  $fixtureNames
     * @return Collection<int, array{voucher: TallySyncEntry, names: list<string>}>
     */
    private function vouchersNamingAFixture(array $fixtureNames): Collection
    {
        if ($fixtureNames === []) {
            return collect();
        }

        $lookup = array_flip($fixtureNames);
        $found = collect();

        foreach (TallySyncEntry::query()->orderBy('id')->cursor() as $voucher) {
            $named = [];
            foreach (['lines', 'produced', 'consumed', 'withheld'] as $section) {
                foreach ((array) ($voucher->payload[$section] ?? []) as $line) {
                    $name = is_array($line) ? ($line['item'] ?? null) : null;
                    if (is_string($name) && isset($lookup[$name])) {
                        $named[$name] = true;
                    }
                }
            }

            if ($named !== []) {
                $found->push(['voucher' => $voucher, 'names' => array_keys($named)]);
            }
        }

        return $found;
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @param  callable(mixed): string  $format
     */
    private function listOrNone(Collection $rows, callable $format): void
    {
        if ($rows->isEmpty()) {
            $this->line('  (none)');

            return;
        }

        foreach ($rows as $row) {
            $this->line($format($row));
        }
    }
}
