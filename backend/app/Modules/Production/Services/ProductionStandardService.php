<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The two things a person does to a standard from the standards page:
 * attach the Tally item it applies to, and add one the workbook never had.
 *
 * Both are the same job the importer does, done one row at a time by someone
 * who knows the answer — so both go through the importer's own rules rather
 * than beside them. Row identity, packaging derivation and the
 * one-option-is-the-default rule are all the importer's; re-deciding any of
 * them here would produce a hand-added standard that behaves differently from
 * an imported one on the Start Batch screen, which is exactly the sort of
 * difference nobody discovers until a shift is running.
 */
class ProductionStandardService
{
    public function __construct(private readonly ProductionStandardImportService $import) {}

    /**
     * Attach a Tally item to a standard that has none.
     *
     * The rule the importer's adopt block exists to protect: a standard
     * gaining its item KEEPS ITS ROW. It does not spawn a sibling, because the
     * page would then show one mould twice — once attached, once orphaned —
     * which is the confusion this endpoint was built to end.
     *
     * Row identity in the database is (item_id, source_product_name, cavities,
     * unit_weight_grams, cycle_time), a real unique index. Setting item_id is
     * therefore a change of identity, and it can collide with a row that
     * already occupies the one being moved into — soft-deleted rows included,
     * because a trashed row still holds its slot in the index. That collision
     * is a genuine question for a person ("these two are the same variant of
     * the same item — which do you want?"), so it is refused by name rather
     * than allowed to surface as a database error nobody can act on.
     */
    public function attachItem(ProductionStandard $standard, int $itemId, ?User $actor, bool $confirmReattach = false): ProductionStandard
    {
        // Re-pointing an attached standard is allowed ONLY as an explicit,
        // confirmed correction (DEC-20260810-003: the Tally identity is
        // editable configuration). The confirmation is not ceremony — the
        // change alters whose figures every FUTURE run of this product uses,
        // and the refusal below names exactly that so a stray click cannot do
        // it. History stays honest either way: completed batches froze the
        // identity they posted under, and posted vouchers are never rewritten.
        if ($standard->item_id !== null && ! $confirmReattach) {
            throw ValidationException::withMessages([
                'item_id' => sprintf(
                    'This standard is already attached to "%s". Changing it re-points every FUTURE run of this product — confirm the change to proceed (already-posted vouchers and completed batches keep the identity they recorded).',
                    (string) ($standard->item?->name ?? 'another item'),
                ),
            ]);
        }

        // Not `exists:items,id`: that rule counts soft-deleted rows, so a
        // deleted item would attach and the standard would point at a bottle
        // the factory has retired.
        $item = Item::query()->find($itemId);

        if ($item === null) {
            throw ValidationException::withMessages([
                'item_id' => 'That item no longer exists in the catalogue.',
            ]);
        }

        if (! $item->is_active) {
            throw ValidationException::withMessages([
                'item_id' => sprintf('"%s" is not an active item, so no shift can be run against it.', (string) $item->name),
            ]);
        }

        $clash = ProductionStandard::withTrashed()
            ->where('item_id', $item->id)
            ->where('source_product_name', $standard->source_product_name)
            ->where('cavities', $standard->cavities)
            ->where('unit_weight_grams', $standard->unit_weight_grams)
            ->where('cycle_time', $standard->cycle_time)
            ->whereKeyNot($standard->getKey())
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'item_id' => sprintf(
                    'Standard #%d already covers "%s" at %s%s. Two identical variants of one item cannot both exist — use that one, or change this row\'s figures first.',
                    $clash->id,
                    (string) $item->name,
                    $clash->variantLabel(),
                    $clash->trashed() ? ' (deleted, but still holding the slot)' : '',
                ),
            ]);
        }

        // Provenance names a CHANGE as a change: "re-pointed from X" is a
        // different fact than a first attachment, and the person auditing a
        // voucher months later needs to see which one happened.
        $previous = $standard->item_id === null ? null : (string) $standard->item?->name;

        $standard->item_id = $item->id;
        $standard->item_attached_by = $actor?->id;
        $standard->item_attached_at = now();
        $standard->notes = $this->appendNote($standard->notes, $previous === null
            ? sprintf(
                'Tally item "%s" attached in the app on %s by %s.',
                (string) $item->name,
                now()->toDateString(),
                $actor?->name ?? 'an unidentified user',
            )
            : sprintf(
                'Tally identity re-pointed from "%s" to "%s" in the app on %s by %s (confirmed change; future runs only).',
                $previous,
                (string) $item->name,
                now()->toDateString(),
                $actor?->name ?? 'an unidentified user',
            ));
        $standard->save();

        return $standard->fresh(['item', 'packagings']);
    }

    /**
     * Add a standard for a product the workbook never carried.
     *
     * Lands as `draft` and as source MANUAL — the same footing an imported row
     * has before anyone approves it. It is deliberately NOT created approved:
     * approval is a separate decision and nothing typed into a form has been
     * reviewed by definition.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor): ProductionStandard
    {
        $product = trim((string) $data['source_product_name']);
        $cavities = (int) $data['cavities'];
        $weight = (string) $data['unit_weight_grams'];
        $cycleTime = (string) $data['cycle_time'];
        $itemId = isset($data['item_id']) ? (int) $data['item_id'] : null;

        if ($itemId !== null) {
            $item = Item::query()->find($itemId);

            if ($item === null || ! $item->is_active) {
                throw ValidationException::withMessages([
                    'item_id' => 'Pick an active item from the catalogue, or leave it unattached and attach one later.',
                ]);
            }
        }

        // The unique index cannot catch the unattached case: NULLs are
        // distinct in it, so two identical item-less rows insert happily and
        // the page grows the duplicate the index was added to prevent. Both
        // cases are checked here, in the one place that knows what the person
        // is trying to add.
        $clash = ProductionStandard::withTrashed()
            ->where('item_id', $itemId)
            ->where('source_product_name', $product)
            ->where('cavities', $cavities)
            ->where('unit_weight_grams', $weight)
            ->where('cycle_time', $cycleTime)
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'source_product_name' => sprintf(
                    'Standard #%d is already "%s" at %s%s. Open that one rather than adding a second copy.',
                    $clash->id,
                    $clash->source_product_name,
                    $clash->variantLabel(),
                    $clash->trashed() ? ' (deleted — restore it instead)' : '',
                ),
            ]);
        }

        return DB::transaction(function () use ($data, $product, $cavities, $weight, $cycleTime, $itemId, $actor) {
            $standard = ProductionStandard::create([
                'item_id' => $itemId,
                'source_product_name' => $product,
                'cavities' => $cavities,
                'unit_weight_grams' => $weight,
                'cycle_time' => $cycleTime,
                'carton_spec' => $this->textOrNull($data['carton_spec'] ?? null),
                'tray_spec' => $this->textOrNull($data['tray_spec'] ?? null),
                'pouch_spec' => $this->textOrNull($data['pouch_spec'] ?? null),
                'status' => 'draft',
                'source' => ProductionStandard::SOURCE_MANUAL,
                'confirmation_status' => 'Added in app',
                'notes' => $this->appendNote($this->textOrNull($data['notes'] ?? null), sprintf(
                    'Added by hand on the standards page on %s by %s — not from the factory workbook.',
                    now()->toDateString(),
                    $actor?->name ?? 'an unidentified user',
                )),
                'created_by' => $actor?->id,
                'item_attached_by' => $itemId !== null ? $actor?->id : null,
                'item_attached_at' => $itemId !== null ? now() : null,
            ]);

            // The importer's own derivation, called rather than copied: it
            // prefers a stated containers-per-box figure and only falls back to
            // dividing, which is the distinction that stopped 520-per-box rows
            // reporting 4 pouches instead of the sheet's 5.
            foreach ($this->import->packagings($data) as $packaging) {
                ProductionStandardPackaging::create(
                    $packaging + ['production_standard_id' => $standard->id],
                );
            }

            // Exactly one option = the default, so the completion screen is
            // never asked a question with one answer. Same rule as the import.
            $options = $standard->packagings()->get();
            if ($options->count() === 1) {
                $options->first()->update(['is_default' => true]);
            }

            return $standard->fresh(['item', 'packagings']);
        });
    }

    private function appendNote(?string $existing, string $line): string
    {
        $existing = trim((string) $existing);

        return $existing === '' ? $line : $existing."\n".$line;
    }

    private function textOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
