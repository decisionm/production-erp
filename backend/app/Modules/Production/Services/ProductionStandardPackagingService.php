<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Exceptions\DuplicatePackagingVariantException;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Support\Configuration\ActiveFlag;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\HardDeleteAuthority;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The packaging variants of one product standard — add the way a product is
 * really packed (the 490/box tray the workbook never carried), and set or
 * correct each variant's own Tally identity (DEC-20260810-003).
 *
 * THE VARIANT KEY IS THE MODE PLUS ITS COUNTS (Phase 5, D1). One standard
 * may carry a 490/box tray AND a 520/box tray; what it may not carry is the
 * same mode with the same counts twice — that is refused here, named, as a
 * 422 (DuplicatePackagingVariantException). THIS REFUSAL IS THE GUARD. The
 * database's psp_standard_variant_unique documents the key but enforces
 * nothing a real row meets: every real row leaves the other mode's counts
 * NULL (a tray row has no pouch counts, a direct box has no inner counts at
 * all), and NULL never collides in a unique index — so no real row is ever
 * fully stated and the index never fires. Writers on one standard are
 * serialised by a row lock on the standard (store/update), so two requests
 * adding the same twin at once cannot both pass this check.
 *
 * AN IDENTITY-ONLY WRITE TOUCHES NO COUNT. setIdentity() sets item_id (and
 * its provenance) and nothing else, and attributes() keeps a stored
 * nos_per_box when the mode's inner counts are unchanged — the importer
 * deliberately keeps rows whose three figures disagree (105/pouch × 5, sheet
 * says 520 — flagged "confirm which is correct") and neither a link nor an
 * edit that leaves the counts alone may pick the figure the import refused
 * to pick.
 *
 * ONE DEFAULT PER STANDARD. `is_default` is what resolvePackaging() adopts
 * when a standard has more than one complete option and nobody chose —
 * two defaults would put a question with one answer back on the picker,
 * so setting a default here clears the standard's previous one in the same
 * transaction.
 *
 * Identity provenance mirrors the packing-material master: item_set_by /
 * item_set_at stamp who answered, and only when the ANSWER changes — an
 * edit that only touches counts must not claim a person re-confirmed the
 * identity.
 *
 * Nothing here rewrites history: a completed batch froze the identity it
 * posted under (shift_production_entries.finished_item_id), so editing a
 * variant changes future batches only.
 */
class ProductionStandardPackagingService
{
    use ManagesConfigurationLifecycle;

    protected function configurationLabel(): string
    {
        return 'packaging variant';
    }

    /**
     * No in-service flag on this table, so Archive is the soft delete added
     * by migration 2026_08_18_090000 and Activate is the restore. `is_default`
     * is not it: a variant can be in service without being the default.
     */
    protected function configurationActiveColumn(): ActiveFlag|string|null
    {
        return null;
    }

    /** A refusal names the variant the way the screen labels it. */
    protected function configurationNameUsing(): ?Closure
    {
        return static fn (ProductionStandardPackaging $packaging): string => $packaging->label();
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return HardDeleteAuthority::callback();
    }

    /**
     * EVERYTHING THAT MAY REFER TO ONE PACKAGING VARIANT.
     *
     * The schema, read 18-Aug-2026:
     *
     *   shift_production_entries.production_standard_packaging_id      SET NULL
     *   shift_production_entry_packing_lines.
     *                        production_standard_packaging_id          SET NULL
     *
     * NEITHER CASCADES, so `SchemaCascades` reports nothing for this table and
     * there is NO backstop of any kind here — both are declared by hand or
     * they are not checked at all. Both would be silently blanked by a hard
     * delete, and a packing line that no longer names the variant it was
     * counted under is a posted production document rewritten.
     *
     * THE TALLY IDENTITY IS THE THIRD, and it is the record's OWN attribute.
     * `item_id` is the Tally stock item THIS packing posts as
     * (DEC-20260810-003) — a variant carrying one has been given a place in
     * Tally's world by a person, and dropping the row drops that mapping
     * while Tally's own history keeps referring to it. Archive preserves it;
     * hard delete may not (DEC-20260817-002 §4 — historical Tally identity
     * and mapping are preserved). No Tally call is made to establish this:
     * the attribute on our own row is the evidence.
     *
     * CHECKED NEGATIVE, stated so it is visibly not an oversight:
     * `shift_production_entries.config_snapshot` freezes `packaging_mode` —
     * the WORD "pouch", "tray", "direct_box" — and the resolved counts, but
     * never the packaging ROW ID (ShiftProductionEntryService's snapshot
     * block). A mode string is not a reference to this row, so there is
     * nothing there to count.
     *
     * @return list<DependencyCheck>
     */
    protected function dependencyChecks(): array
    {
        return [
            DependencyCheck::table('shift_production_entries', 'production_standard_packaging_id')
                ->label('shift production entry'),

            DependencyCheck::table('shift_production_entry_packing_lines', 'production_standard_packaging_id')
                ->label('packing line'),

            DependencyCheck::attribute('item_id', 'tally_identity')
                ->label('Tally identity of its own'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  a SaveProductionStandardPackagingRequest's validated payload
     */
    public function store(ProductionStandard $standard, array $data, ?int $userId): ProductionStandardPackaging
    {
        return DB::transaction(function () use ($data, $standard, $userId) {
            $this->lockStandard($standard);
            $this->refuseExactDuplicate($standard, $data, null);

            $packaging = $standard->packagings()->create(
                $this->attributes($data, null, (int) $userId),
            );

            $this->keepOneDefault($standard, $packaging);

            return $packaging->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  a SaveProductionStandardPackagingRequest's validated payload
     */
    public function update(
        ProductionStandard $standard,
        ProductionStandardPackaging $packaging,
        array $data,
        ?int $userId,
    ): ProductionStandardPackaging {
        $this->refuseForeignRow($standard, $packaging);

        return DB::transaction(function () use ($data, $standard, $packaging, $userId) {
            $this->lockStandard($standard);
            $this->refuseExactDuplicate($standard, $data, $packaging);

            $packaging->fill($this->attributes($data, $packaging, (int) $userId))->save();

            $this->keepOneDefault($standard, $packaging);

            return $packaging->fresh();
        });
    }

    /**
     * Set or clear ONE packaging's own Tally identity and touch nothing else
     * — no mode, no count, no default. The review panel's Link and any
     * other identity-only client come through here so that a link can never
     * move a figure (P1-a). Provenance stamps only when the answer changes,
     * exactly as the full update does.
     */
    public function setIdentity(
        ProductionStandard $standard,
        ProductionStandardPackaging $packaging,
        ?int $itemId,
        ?int $userId,
    ): ProductionStandardPackaging {
        $this->refuseForeignRow($standard, $packaging);

        return DB::transaction(function () use ($packaging, $itemId, $userId) {
            $current = $packaging->item_id === null ? null : (int) $packaging->item_id;

            if ($itemId !== $current) {
                $packaging->fill([
                    'item_id' => $itemId,
                    'item_set_by' => $userId ?: null,
                    'item_set_at' => now(),
                ])->save();
            }

            return $packaging->fresh();
        });
    }

    /**
     * ONE of a standard's packaging variants, ARCHIVED ROWS INCLUDED, and
     * only ever one of THAT standard's — the same refusal the writes make,
     * so a delete cannot reach a sibling product's variant through a guessed
     * id. Archived rows are found because Activate has to be able to undo an
     * Archive, and a hard delete answers for a trashed row rather than
     * 404ing it.
     */
    public function resolveFor(ProductionStandard $standard, int $packagingId): ProductionStandardPackaging
    {
        $packaging = ProductionStandardPackaging::withTrashed()->find($packagingId);

        if ($packaging === null) {
            throw (new ModelNotFoundException)->setModel(ProductionStandardPackaging::class, [$packagingId]);
        }

        $this->refuseForeignRow($standard, $packaging);

        return $packaging;
    }

    /**
     * The row as every consumer sees it — counts, completeness, and the
     * identity with its provenance.
     *
     * @return array<string, mixed>
     */
    public function describe(ProductionStandardPackaging $packaging, bool $resolveDelete = true): array
    {
        $packaging->loadMissing(['tallyItem', 'standard.item']);

        return [
            'id' => (int) $packaging->id,
            // The Configuration Lifecycle Contract's `can` — the SAME
            // predicate the actions enforce, so no screen re-derives
            // eligibility. Taken from the controller's stamp when there is one
            // (it has already intersected the record's eligibility with this
            // user's grant, ManagesConfigurationRecords); asked here otherwise.
            // On a list `$resolveDelete` is false and `delete` comes back
            // null: undetermined, ask, rather than a lie either way.
            'can' => $packaging->can ?? $this->abilities($packaging, $resolveDelete),
            'is_archived' => $packaging->trashed(),
            'mode' => (string) $packaging->mode,
            'nos_per_pouch' => $packaging->nos_per_pouch,
            'pouches_per_box' => $packaging->pouches_per_box,
            'nos_per_tray' => $packaging->nos_per_tray,
            'trays_per_box' => $packaging->trays_per_box,
            'nos_per_box' => $packaging->nos_per_box,
            'is_default' => (bool) $packaging->is_default,
            'is_complete' => $packaging->isComplete(),
            'tally_item' => $packaging->item_id === null ? null : [
                'id' => (int) $packaging->item_id,
                'name' => (string) $packaging->tallyItem?->name,
            ],
            // What the run WILL post as, resolved — so the screen can print
            // "using product identity" without re-deriving the fallback rule.
            'resolved_item_name' => (string) ($packaging->tallyItem?->name
                ?? $packaging->standard?->item?->name
                ?? ''),
            'uses_product_identity' => $packaging->item_id === null,
        ];
    }

    /**
     * The writable columns from one validated payload. nos_per_box is
     * DERIVED for pouch/tray (inner × count) exactly as the standards page
     * derives it — the third figure can never disagree with the two it came
     * from — EXCEPT when an existing row's mode and inner counts are left
     * exactly as they were: then the stored box count stands, byte for
     * byte. The importer keeps rows whose figures disagree on purpose
     * (105 × 5 stored beside the sheet's 520, flagged for a person), and an
     * edit that touches no count — an identity, a default flag — must not
     * be the thing that silently settles which figure was right. A genuine
     * count edit still derives. Identity provenance stamps only when the
     * answer changes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?ProductionStandardPackaging $existing, int $userId): array
    {
        $mode = (string) $data['mode'];

        $pouch = $mode === ProductionStandardPackaging::MODE_POUCH;
        $tray = $mode === ProductionStandardPackaging::MODE_TRAY;

        $nosPerPouch = $pouch ? ($data['nos_per_pouch'] ?? $existing?->nos_per_pouch) : null;
        $pouchesPerBox = $pouch ? ($data['pouches_per_box'] ?? $existing?->pouches_per_box) : null;
        $nosPerTray = $tray ? ($data['nos_per_tray'] ?? $existing?->nos_per_tray) : null;
        $traysPerBox = $tray ? ($data['trays_per_box'] ?? $existing?->trays_per_box) : null;

        $innerCountsUnchanged = $existing !== null
            && (string) $existing->mode === $mode
            && match (true) {
                $pouch => $this->sameCount($nosPerPouch, $existing->nos_per_pouch)
                    && $this->sameCount($pouchesPerBox, $existing->pouches_per_box),
                $tray => $this->sameCount($nosPerTray, $existing->nos_per_tray)
                    && $this->sameCount($traysPerBox, $existing->trays_per_box),
                default => false,
            };

        $nosPerBox = match (true) {
            $innerCountsUnchanged => $existing->nos_per_box,
            $pouch => $nosPerPouch !== null && $pouchesPerBox !== null ? $nosPerPouch * $pouchesPerBox : null,
            $tray => $nosPerTray !== null && $traysPerBox !== null ? $nosPerTray * $traysPerBox : null,
            default => $data['nos_per_box'] ?? $existing?->nos_per_box,
        };

        $attributes = [
            'mode' => $mode,
            'nos_per_pouch' => $nosPerPouch,
            'pouches_per_box' => $pouchesPerBox,
            'nos_per_tray' => $nosPerTray,
            'trays_per_box' => $traysPerBox,
            'nos_per_box' => $nosPerBox,
            'is_default' => (bool) ($data['is_default'] ?? $existing?->is_default ?? false),
        ];

        if (array_key_exists('item_id', $data)) {
            $newItemId = $data['item_id'] === null ? null : (int) $data['item_id'];

            $attributes['item_id'] = $newItemId;

            if ($newItemId !== ($existing?->item_id === null ? null : (int) $existing->item_id)) {
                $attributes['item_set_by'] = $userId ?: null;
                $attributes['item_set_at'] = now();
            }
        }

        return $attributes;
    }

    /**
     * Two rows stating the SAME mode and the SAME counts would leave the
     * Start Batch picker offering one choice twice — refused with the row
     * named, so the person corrects the existing one instead.
     *
     * Compared on the four INNER counts as they would be stored (a count
     * that does not belong to the mode is null whatever the payload said),
     * so a tray payload carrying a stray pouch figure cannot slip past a
     * genuine twin — the same normalisation attributes() applies. nos_per_box
     * is derived from those for pouch/tray and stated for direct_box, where
     * it is the whole spec and is compared as such.
     *
     * @param  array<string, mixed>  $data
     */
    private function refuseExactDuplicate(ProductionStandard $standard, array $data, ?ProductionStandardPackaging $existing): void
    {
        $mode = (string) $data['mode'];
        $stored = $this->attributes($data, $existing, 0);

        $duplicate = $standard->packagings()
            // WITH THE ARCHIVED ONES. Adding `deleted_at` to this table
            // (migration 2026_08_18_090000) gave Archive somewhere to go, and
            // it must not have quietly loosened this guard on the way past:
            // an ARCHIVED variant RETAINS and RESERVES its key
            // (DEC-20260817-002 §2), so the twin refusal still fires against
            // it and the answer names the archived row rather than letting a
            // second copy in behind its back.
            ->withTrashed()
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
            ->where('mode', $mode)
            ->where('nos_per_pouch', $stored['nos_per_pouch'])
            ->where('pouches_per_box', $stored['pouches_per_box'])
            ->where('nos_per_tray', $stored['nos_per_tray'])
            ->where('trays_per_box', $stored['trays_per_box'])
            ->when(
                $mode === ProductionStandardPackaging::MODE_DIRECT_BOX,
                fn ($query) => $query->where('nos_per_box', $stored['nos_per_box']),
            )
            ->first();

        if ($duplicate !== null) {
            throw DuplicatePackagingVariantException::make($standard, $mode);
        }
    }

    /** Two count values are the same figure when both are null or both are the same integer. */
    private function sameCount(mixed $incoming, mixed $stored): bool
    {
        if ($incoming === null || $stored === null) {
            return $incoming === null && $stored === null;
        }

        return (int) $incoming === (int) $stored;
    }

    /** A packaging is only ever written through its OWN standard's URL. */
    private function refuseForeignRow(ProductionStandard $standard, ProductionStandardPackaging $packaging): void
    {
        if ((int) $packaging->production_standard_id !== (int) $standard->id) {
            throw ValidationException::withMessages([
                'packaging' => 'This packing option belongs to a different product standard.',
            ]);
        }
    }

    /**
     * Serialise the writers of one standard's packagings: the twin check
     * reads siblings and then writes, and two requests adding the same twin
     * at once would both pass the read. A FOR UPDATE on the standard row
     * makes the second wait for the first's commit (a no-op on sqlite,
     * which serialises writers anyway).
     */
    private function lockStandard(ProductionStandard $standard): void
    {
        ProductionStandard::query()->whereKey($standard->id)->lockForUpdate()->first();
    }

    /**
     * ONE default per standard: when the row just written is the default,
     * every sibling stops being one. Rows on other standards are untouched
     * — the rule is per standard, not per product.
     */
    private function keepOneDefault(ProductionStandard $standard, ProductionStandardPackaging $packaging): void
    {
        if (! $packaging->is_default) {
            return;
        }

        $standard->packagings()
            ->whereKeyNot($packaging->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
