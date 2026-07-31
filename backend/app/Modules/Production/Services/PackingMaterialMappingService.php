<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\PackingMaterialMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The packing-material mapping master: which Tally item each workbook spec
 * string means, and the seed that fills in what the catalogue can prove.
 *
 * Mirrors MasterbatchDosingService deliberately — resolve() / upsert() /
 * withdraw(), soft-deleted rows, provenance on every write — because it is
 * the same kind of object: a factory answer held as data so the next one
 * arrives through the app instead of through a deploy.
 *
 * ## Advisory, absolutely
 *
 * Nothing in completeBatch calls this, and nothing here is consumed, posted
 * or reconciled against. It produces a PREFILL. What the supervisor submits
 * is stored, and what is stored is what Tally receives — there are pinned
 * tests that will fail the moment that stops being true.
 *
 * ## Why the seed matches names instead of shipping a hardcoded list
 *
 * Not one of the eighteen packing item names the factory uses exists in any
 * migration, seeder or test in this repo — they live in the live Tally
 * catalogue and nowhere else. A constant list of ids would be wrong on the
 * first instance whose ids differ; a constant list of NAMES would be wrong
 * the day somebody fixes a spelling in Tally. So the seed reads the catalogue
 * that is actually there, at migration time, and records what it could not
 * resolve rather than guessing past it.
 */
class PackingMaterialMappingService
{
    public function __construct(private readonly PackingItemMatcher $matcher) {}

    /**
     * The mapping in force for one (kind, spec), or null.
     *
     * Exact spelling wins, then a case- and spacing-folded match, then the
     * lowest id. Deterministic on purpose: the workbook spells the same tray
     * "60 ML" on one row and "60ML" on the next, and a prefill that changed
     * between two reads of the same shift would be worse than no prefill.
     */
    public function resolve(string $kind, ?string $spec): ?PackingMaterialMapping
    {
        if ($spec === null || trim($spec) === '') {
            return null;
        }

        // Read the kind's rows and choose in PHP rather than in SQL: LOWER()
        // and TRIM() collation behaviour differs between MySQL and SQLite,
        // and a mapping that resolves on one engine and not the other is a
        // bug that only ever appears in production. The table holds tens of
        // rows, not thousands.
        $rows = $this->forKind($kind);

        $exact = $rows->first(fn (PackingMaterialMapping $row) => (string) $row->spec_value === $spec);

        if ($exact !== null) {
            return $exact;
        }

        $folded = $this->fold($spec);

        return $rows->first(fn (PackingMaterialMapping $row) => $this->fold((string) $row->spec_value) === $folded);
    }

    /**
     * Every mapping, newest question first for a maintenance screen: ordered
     * by kind then by spec so the list is stable between calls.
     *
     * @return Collection<int, PackingMaterialMapping>
     */
    public function all(?string $kind = null, ?string $spec = null): Collection
    {
        return PackingMaterialMapping::query()
            ->with(['item', 'setBy'])
            ->when($kind !== null, fn ($query) => $query->where('spec_kind', $kind))
            ->orderBy('spec_kind')
            ->orderBy('spec_value')
            ->orderBy('id')
            ->get()
            ->when(
                $spec !== null && trim($spec) !== '',
                fn (Collection $rows) => $rows->filter(
                    fn (PackingMaterialMapping $row) => $this->fold((string) $row->spec_value) === $this->fold($spec),
                )->values(),
            );
    }

    /**
     * One mapping as the clients see it — the single shaping place, so the
     * maintenance screen and the batch preview cannot disagree about the same
     * row.
     *
     * @return array{
     *     id: int, spec_kind: string, spec_value: string,
     *     item: array{id: int, name: string}, factor: ?string, unit: string,
     *     factor_unit: string, basis: string, quantity_basis: string,
     *     grams_per_piece: ?string, metres_per_box: ?string, note: ?string,
     *     set_by: ?string, set_at: ?string,
     * }
     */
    public function describe(PackingMaterialMapping $mapping): array
    {
        $basis = $mapping->basis();

        return [
            'id' => (int) $mapping->id,
            'spec_kind' => (string) $mapping->spec_kind,
            'spec_value' => (string) $mapping->spec_value,
            'item' => [
                'id' => (int) $mapping->item_id,
                'name' => (string) $mapping->item?->name,
            ],
            'factor' => $mapping->factor(),
            'unit' => $basis['unit'],
            'factor_unit' => $basis['factor_unit'],
            'basis' => $basis['basis'],
            'quantity_basis' => $basis['quantity_basis'],
            'grams_per_piece' => $mapping->grams_per_piece === null ? null : (string) $mapping->grams_per_piece,
            'metres_per_box' => $mapping->metres_per_box === null ? null : (string) $mapping->metres_per_box,
            'note' => $mapping->note,
            'set_by' => $mapping->setBy?->name,
            'set_at' => $mapping->set_at?->toIso8601String(),
        ];
    }

    /**
     * Enter or correct the mapping for one (kind, spec) pair.
     *
     * Upsert, and withTrashed: the unique index still holds a withdrawn row's
     * slot, so an insert would fail rather than replace it. A correction
     * changes the answer in force; it never stacks a second row that resolve()
     * would have to arbitrate between.
     *
     * @param  array{spec_kind: string, spec_value: string, item_id: int, grams_per_piece?: string|float|null, metres_per_box?: string|float|null, note?: ?string}  $data
     */
    public function upsert(array $data, ?int $userId): PackingMaterialMapping
    {
        $kind = (string) $data['spec_kind'];
        $spec = trim((string) $data['spec_value']);

        $mapping = PackingMaterialMapping::withTrashed()
            ->where('spec_kind', $kind)
            ->where('spec_value', $spec)
            ->orderBy('id')
            ->first();

        $attributes = [
            'spec_kind' => $kind,
            'spec_value' => $spec,
            'item_id' => (int) $data['item_id'],
            // Null when the kind does not carry that dose — a carton row that
            // kept a stale metres figure from a previous edit would quietly
            // start suggesting tape as if it were cardboard.
            'grams_per_piece' => $kind === PackingMaterialMapping::KIND_POUCH_FILM
                ? $this->decimalOrNull($data['grams_per_piece'] ?? null)
                : null,
            'metres_per_box' => $kind === PackingMaterialMapping::KIND_TAPE
                ? $this->decimalOrNull($data['metres_per_box'] ?? null)
                : null,
            'note' => $data['note'] ?? null,
            'set_by' => $userId,
            'set_at' => now(),
        ];

        if ($mapping === null) {
            $mapping = PackingMaterialMapping::create($attributes);
        } else {
            if ($mapping->trashed()) {
                $mapping->restore();
            }
            $mapping->fill($attributes)->save();
        }

        return $mapping->fresh(['item', 'setBy']);
    }

    /** Withdraw a mapping: back to "no prefill", never to a zero quantity. */
    public function withdraw(PackingMaterialMapping $mapping): void
    {
        $mapping->delete();
    }

    // ------------------------------------------------------------------
    // The deploy-time seed
    // ------------------------------------------------------------------

    /**
     * Resolve every spec string the standards actually carry against the
     * catalogue that is actually there, and write a row only where the answer
     * is unambiguous.
     *
     * A seam rather than migration-body code, for the reason
     * PackingSpecInferences::backfill() states in its own docblock: it has to
     * be runnable — and testable — twice. Under RefreshDatabase the items
     * table is empty when migrations run, so this method is a no-op during
     * the normal test boot and would otherwise never be executed by anything.
     *
     * The spec list is read from `production_standards` rather than
     * hardcoded, because that IS the evidence: the workbook's distinct
     * carton/tray/pouch strings are exactly the questions this table has to
     * answer, inferred cells included (they are marked, not quarantined).
     *
     * IDEMPOTENT, and it never overwrites: a row already present — including
     * one a person has since withdrawn — is left exactly as it is and counted
     * under `kept`. A second run finds nothing to do.
     *
     * @return array{
     *     seeded: list<array{kind: string, spec: string, item: string, factor: ?string, reason: string}>,
     *     missed: list<array{kind: string, spec: string, reason: string}>,
     *     kept: list<array{kind: string, spec: string}>,
     * }
     */
    public function seedFromCatalogue(): array
    {
        $result = ['seeded' => [], 'missed' => [], 'kept' => []];

        $items = $this->catalogue();
        $existing = PackingMaterialMapping::withTrashed()
            ->get(['spec_kind', 'spec_value'])
            ->map(fn ($row) => $row->spec_kind.'|'.$this->fold((string) $row->spec_value))
            ->all();

        foreach ($this->specsFromStandards() as $kind => $specs) {
            foreach ($specs as $spec) {
                if (in_array($kind.'|'.$this->fold($spec), $existing, true)) {
                    $result['kept'][] = ['kind' => $kind, 'spec' => $spec];

                    continue;
                }

                $seeded = $this->seedOne($kind, $spec, $items);

                if ($seeded['item'] === null) {
                    $result['missed'][] = ['kind' => $kind, 'spec' => $spec, 'reason' => $seeded['reason']];

                    continue;
                }

                $result['seeded'][] = [
                    'kind' => $kind,
                    'spec' => $spec,
                    'item' => $seeded['item'],
                    'factor' => $seeded['factor'],
                    'reason' => $seeded['reason'],
                ];
                $existing[] = $kind.'|'.$this->fold($spec);
            }
        }

        return $result;
    }

    /**
     * One (kind, spec): match, then write, or explain.
     *
     * @param  Collection<int, object{id: int, name: string}>  $items
     * @return array{item: ?string, factor: ?string, reason: string}
     */
    private function seedOne(string $kind, string $spec, Collection $items): array
    {
        // Tape is dosed by the box it seals, so a tape row needs BOTH a tape
        // item and a metres figure paired to this carton. Checked before the
        // item lookup so an unpaired carton is reported as the mapping
        // question it is, not as a missing tape.
        $metres = null;

        if ($kind === PackingMaterialMapping::KIND_TAPE) {
            $metres = $this->matcher->tapeMetresFor($spec);

            if ($metres === null) {
                return [
                    'item' => null,
                    'factor' => null,
                    'reason' => 'the owner\'s metres-per-box table has no row paired to this carton',
                ];
            }
        }

        $match = $this->matcher->match($kind, $spec, $items);

        if ($match['item'] === null) {
            return ['item' => null, 'factor' => null, 'reason' => $match['reason']];
        }

        $grams = $kind === PackingMaterialMapping::KIND_POUCH_FILM ? $match['grams_per_piece'] : null;

        $mapping = PackingMaterialMapping::create([
            'spec_kind' => $kind,
            'spec_value' => $spec,
            'item_id' => (int) $match['item']->id,
            'grams_per_piece' => $grams,
            'metres_per_box' => $metres,
            // How it was matched, in the row itself. The owner reviewing this
            // table has to be able to see WHY a spec points where it does
            // without re-running anything.
            'note' => sprintf(
                'Seeded %s: matched "%s" because %s.',
                now()->toDateString(),
                $match['item']->name,
                $match['reason'],
            ).($metres !== null
                ? " Metres per box {$metres} from the owner's table (31 Jul 2026); Tally counts tape in Nos and"
                    .' whether a "No" is a metre or a strip is still open, so this figure is metres.'
                : '').($grams !== null
                ? " Per-piece weight {$grams} g parsed from the item's own name."
                : ''),
            // No set_by: nobody set this in the app. A user id here would
            // claim a person answered a question a deploy answered.
            'set_by' => null,
            'set_at' => now(),
        ]);

        return [
            'item' => (string) $match['item']->name,
            'factor' => $mapping->factor(),
            'reason' => $match['reason'],
        ];
    }

    /**
     * The distinct spec strings the standards carry, by kind — the questions
     * this table exists to answer.
     *
     * `tape` reuses the CARTON strings: tape wraps the box, so "what tape for
     * a 170ML carton" is the only form the question takes.
     *
     * withTrashed on the standards, for PackingSpecInferences::backfill()'s
     * reason: a soft-deleted standard shows its specs again the moment it is
     * restored, and leaving it out would make the seed's result depend on
     * what happened to be deleted the day it ran.
     *
     * @return array<string, list<string>>
     */
    private function specsFromStandards(): array
    {
        $column = [
            PackingMaterialMapping::KIND_CARTON => 'carton_spec',
            PackingMaterialMapping::KIND_TRAY => 'tray_spec',
            PackingMaterialMapping::KIND_POUCH_FILM => 'pouch_spec',
        ];

        $specs = [];

        foreach ($column as $kind => $name) {
            $specs[$kind] = DB::table('production_standards')
                ->select($name)
                ->distinct()
                ->orderBy($name)
                ->pluck($name)
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn (string $value) => $value !== '')
                ->unique()
                ->values()
                ->all();
        }

        $specs[PackingMaterialMapping::KIND_TAPE] = $specs[PackingMaterialMapping::KIND_CARTON];

        return $specs;
    }

    /**
     * The live catalogue: id and name of every item that is not deleted.
     *
     * Read raw, not through the Item model, so the seed cannot be changed by
     * a future global scope — a migration that silently stops seeing half the
     * catalogue is a failure nobody would notice.
     *
     * Deliberately NOT filtered by `uom`. This factory's item master is not a
     * faithful mirror of Tally's units — the resin items themselves read
     * "NOS" in places — so a uom filter would refuse the film items outright
     * on the live database. The kind's own pool word is the signal.
     *
     * @return Collection<int, object{id: int, name: string}>
     */
    private function catalogue(): Collection
    {
        return DB::table('items')
            ->select('id', 'name')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, PackingMaterialMapping> */
    private function forKind(string $kind): Collection
    {
        return PackingMaterialMapping::query()
            ->with(['item', 'setBy'])
            ->where('spec_kind', $kind)
            ->orderBy('id')
            ->get();
    }

    private function decimalOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /** Case- and spacing-insensitive, with '*' read as 'x' — the sheet uses both. */
    private function fold(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['*', '×'], 'x', $value);

        return preg_replace('/\s+/', ' ', $value) ?? '';
    }
}
