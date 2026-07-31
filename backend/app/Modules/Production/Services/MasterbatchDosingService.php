<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\MasterbatchDosing;
use Illuminate\Support\Collection;

/**
 * The masterbatch dosing master, and the ONE place the kg suggestion is
 * worked out.
 *
 * What this service is for
 * ------------------------
 * The factory gave a figure on 31-Jul — amber masterbatch, 0.25 g per bottle
 * — so the floor no longer has to remember it. This turns that figure into a
 * prefill: bottles × grams ÷ 1000 kg, ready in the field, editable.
 *
 * What it is emphatically NOT for
 * ------------------------------
 * It never decides what was consumed. The supervisor's weighed kg is the
 * truth (owner's standing decision), and NOTHING in the completion path calls
 * this service: completeBatch stores exactly the material_consumptions rows
 * it was sent, and the Tally voucher carries those. A suggestion that could
 * overwrite a submitted figure server-side would be a recipe pretending to be
 * a measurement — which is the thing the owner refused. Read-only by
 * construction on the floor's side; the only writes here are a person
 * entering or withdrawing the master figure itself.
 *
 * No prefill is a valid answer
 * ----------------------------
 * Two of the three masterbatches have no figure. resolve() returns null for
 * them, suggestionFor() returns null, and the screen must show an empty,
 * editable field. Never 0.0000 — a zero asserts that a colour needs no
 * masterbatch, which no one at the factory has said.
 *
 * Which masterbatch, though?
 * --------------------------
 * Deliberately NOT answered here by matching a product's colour against
 * masterbatch item NAMES. FactoryDayBinService already records why that
 * approach is wrong ("better than a hardcoded list of names that a new
 * masterbatch would fall out of the moment the factory bought one"). Instead
 * candidatesFor() returns every dosing that could apply to a product and the
 * caller matches it against the masterbatch it already knows about — the bag
 * that was actually scanned into the bin, or the row the supervisor picked.
 * One seeded dosing today means exactly one candidate, so the floor still
 * gets a single unambiguous prefill without anybody inventing a colour rule.
 */
class MasterbatchDosingService
{
    public function __construct(private readonly ProductionCalculationEngine $engine) {}

    /**
     * The dosing in force for one masterbatch, optionally narrowed to a
     * product. Most specific wins: a dosing set FOR THIS BOTTLE beats the
     * factory-wide one for the same material.
     *
     * The ordering is fully deterministic (product-scoped, then factory-wide,
     * then lowest id) on purpose — the unique index cannot stop two
     * factory-wide rows for one material, because neither MySQL nor SQLite
     * treats NULLs as equal, and a prefill that changes between two reads of
     * the same shift is worse than no prefill at all.
     */
    public function resolve(?int $masterbatchItemId, ?int $productItemId = null): ?MasterbatchDosing
    {
        if ($masterbatchItemId === null) {
            return null;
        }

        return MasterbatchDosing::query()
            ->with(['masterbatchItem', 'productItem', 'setBy'])
            ->where('masterbatch_item_id', $masterbatchItemId)
            ->where(function ($query) use ($productItemId) {
                $query->whereNull('product_item_id');
                if ($productItemId !== null) {
                    $query->orWhere('product_item_id', $productItemId);
                }
            })
            ->orderByRaw('CASE WHEN product_item_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('id')
            ->first();
    }

    /**
     * Every dosing that could apply to a product — one row per masterbatch,
     * the most specific for that material.
     *
     * With no product named this is simply the whole master list, which is
     * what a maintenance screen wants. Ordered by masterbatch item id so the
     * list is stable between calls.
     *
     * @return Collection<int, MasterbatchDosing>
     */
    public function candidatesFor(?int $productItemId = null): Collection
    {
        $rows = MasterbatchDosing::query()
            ->with(['masterbatchItem', 'productItem', 'setBy'])
            ->when(
                $productItemId !== null,
                fn ($query) => $query->where(fn ($q) => $q
                    ->whereNull('product_item_id')
                    ->orWhere('product_item_id', $productItemId)),
            )
            ->orderBy('masterbatch_item_id')
            ->orderByRaw('CASE WHEN product_item_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('id')
            ->get();

        // Only collapse to one-per-material when a product was named: without
        // one, the caller is looking at the master list and every row is a
        // separate fact about a separate bottle.
        if ($productItemId === null) {
            return $rows->values();
        }

        return $rows
            ->groupBy('masterbatch_item_id')
            // The group is already in precedence order, so first() IS the
            // winner — same rule as resolve(), not a second implementation.
            ->map(fn (Collection $group) => $group->first())
            ->values();
    }

    /**
     * The floor's answer: the dosing plus the kg it implies for a bottle
     * count. Null when no dosing is set for that masterbatch — the caller
     * must render an empty field, not a zero.
     *
     * @return array{
     *     id: int, masterbatch_item: array{id: int, name: string},
     *     product_item: ?array{id: int, name: string}, scope: string,
     *     grams_per_bottle: string, note: ?string, set_by: ?string,
     *     set_at: ?string, bottles: ?int, suggested_kg: ?string,
     * }|null
     */
    public function suggestionFor(?int $masterbatchItemId, ?int $productItemId = null, ?int $bottles = null): ?array
    {
        $dosing = $this->resolve($masterbatchItemId, $productItemId);

        return $dosing === null ? null : $this->describe($dosing, $bottles);
    }

    /**
     * One dosing rendered for a client, with the kg for `$bottles`.
     *
     * `suggested_kg` stays null until a bottle count exists — during
     * completion that count is being typed, and a kg quoted against no count
     * would be a number with no basis. `bottles` is echoed back so the screen
     * can SAY which count the kg is for ("3.3333 kg for 13,333 bottles")
     * instead of showing a figure the supervisor cannot check.
     *
     * @return array{
     *     id: int, masterbatch_item: array{id: int, name: string},
     *     product_item: ?array{id: int, name: string}, scope: string,
     *     grams_per_bottle: string, note: ?string, set_by: ?string,
     *     set_at: ?string, bottles: ?int, suggested_kg: ?string,
     * }
     */
    public function describe(MasterbatchDosing $dosing, ?int $bottles = null): array
    {
        return [
            'id' => (int) $dosing->id,
            'masterbatch_item' => [
                'id' => (int) $dosing->masterbatch_item_id,
                'name' => (string) $dosing->masterbatchItem?->name,
            ],
            'product_item' => $dosing->product_item_id === null ? null : [
                'id' => (int) $dosing->product_item_id,
                'name' => (string) $dosing->productItem?->name,
            ],
            'scope' => $dosing->scope(),
            'grams_per_bottle' => (string) $dosing->grams_per_bottle,
            'note' => $dosing->note,
            'set_by' => $dosing->setBy?->name,
            'set_at' => $dosing->set_at?->toIso8601String(),
            'bottles' => $bottles,
            'suggested_kg' => $this->engine->masterbatchKg($bottles, (string) $dosing->grams_per_bottle),
        ];
    }

    /**
     * Enter or change the figure for one (masterbatch, product) pair.
     *
     * Upsert rather than insert: the factory correcting 0.25 to something
     * else must change the figure in force, not stack a second row behind it
     * that resolve() would have to arbitrate. A previously withdrawn row is
     * restored and rewritten, so the history of a figure stays on one row.
     *
     * @param  array{masterbatch_item_id: int, product_item_id?: ?int, grams_per_bottle: string|float, note?: ?string}  $data
     */
    public function upsert(array $data, ?int $userId): MasterbatchDosing
    {
        $productItemId = $data['product_item_id'] ?? null;

        $dosing = MasterbatchDosing::withTrashed()
            ->where('masterbatch_item_id', $data['masterbatch_item_id'])
            ->when($productItemId === null,
                fn ($query) => $query->whereNull('product_item_id'),
                fn ($query) => $query->where('product_item_id', $productItemId))
            ->orderBy('id')
            ->first();

        $attributes = [
            'masterbatch_item_id' => (int) $data['masterbatch_item_id'],
            'product_item_id' => $productItemId === null ? null : (int) $productItemId,
            'grams_per_bottle' => (string) $data['grams_per_bottle'],
            'note' => $data['note'] ?? null,
            // Who and when, every time the figure moves. A dosing nobody can
            // attribute is one nobody can defend when the variance is
            // questioned six weeks later.
            'set_by' => $userId,
            'set_at' => now(),
        ];

        if ($dosing === null) {
            $dosing = MasterbatchDosing::create($attributes);
        } else {
            if ($dosing->trashed()) {
                $dosing->restore();
            }
            $dosing->fill($attributes)->save();
        }

        return $dosing->fresh(['masterbatchItem', 'productItem', 'setBy']);
    }

    /**
     * Withdraw a figure: back to "no prefill", not to zero.
     *
     * Soft delete, so the record that this dosing once applied survives —
     * shifts completed while it was in force were prefilled from it, and
     * erasing it would leave those figures unexplainable.
     */
    public function withdraw(MasterbatchDosing $dosing): void
    {
        $dosing->delete();
    }
}
