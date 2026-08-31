<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use App\Modules\Inventory\Models\Enums\MeasurementType;
use App\Support\Configuration\Concerns\RecordsConfigurationAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Fillable([
    'sku', 'sku_provisional', 'name', 'display_name', 'description', 'uom', 'hsn_sac_code', 'reorder_level',
    'variant_of_item_id', 'variant_label',
    'nominal_weight_grams', 'nos_per_tray', 'trays_per_box', 'nos_per_box',
    'nos_per_pouch', 'pouches_per_box',
    'colour', 'standard_cycle_time', 'standard_cavities',
    'tracking_type', 'is_active', 'is_local_fixture', 'is_production_input', 'category',
    'tally_stock_item_guid', 'tally_company', 'tally_alter_id', 'tally_synced_at', 'item_group_id',
])]
class Item extends Model
{
    /**
     * Read-side decorations set by MaterialRequestService::requestableMaterials()
     * and read by RequestableMaterialResource — plain properties, never
     * attributes: not persisted, not in toArray(), null on a bare model.
     *
     * What is USABLY standing in Production/WIP for this material
     * (DEC-20260831-001), and whether the unit it was handed over in still
     * agrees with this item's own. A disagreement reports a quantity that is
     * really there and still refuses to net it (FC-03).
     */
    public ?string $availableInProduction = null;

    /**
     * What is ACTUALLY standing there, netted or not — negative included.
     * The owner's rule (DEC-20260831-005, reconfirmed 31-Aug-2026): a negative
     * or unit-mismatched balance stays VISIBLE and is excluded from netting.
     * Reporting only the usable figure would show 0 for both, which reads as
     * an empty floor and is the opposite of visible.
     */
    public ?string $standingInProduction = null;

    public ?bool $productionUnitMatches = null;

    use RecordsConfigurationAudit;
    use SoftDeletes;

    /**
     * SKU prefix of a LOCAL-ONLY fixture item — one fabricated by the product
     * master import for a product the Tally catalogue does not carry, so the
     * factory's own standards can be loaded and exercised before the item is
     * created in Tally.
     *
     * The convention lives here, on the item, rather than on the importer that
     * writes it: the readiness gate and the Tally voucher queue both have to
     * READ it, and neither should have to reach into Production's importer to
     * ask what a local item looks like.
     */
    public const LOCAL_FIXTURE_SKU_PREFIX = 'LOCAL-';

    /**
     * The kg-family spellings of `uom`, lowercased. `uom` is Tally's raw
     * free-text base_unit, so the real data carries 'Kgs', 'Kgs.', 'KGS',
     * 'kg' interchangeably — a "raw material" filter has to tolerate all of
     * them. Bottles/caps are the Nos family ('NOS', 'Nos', 'Nos.') and must
     * never match.
     *
     * THE ENUMERATED FORM EXISTS FOR SQL ONLY. scopeKgUom() matches this list
     * literally because the two engines this runs on spell a trailing-dot
     * strip differently (MySQL `TRIM(TRAILING '.' FROM …)`, SQLite
     * `rtrim(x,'.')`), and a dialect branch inside a scope is a worse bug
     * surface than a longer list. PHP-side callers must use isKgUom(), which
     * normalises instead of enumerating; KgUomParityTest pins the two to the
     * same answer on every spelling.
     *
     * 'kilogram(s)' JOINED THIS LIST 23-Aug-2026. It was already accepted by
     * the four private isMassUom() copies this class now replaces, so the
     * list was the odd one out, not the addition — see isKgUom().
     *
     * @var list<string>
     */
    public const KG_UOM_VARIANTS = [
        'kg', 'kg.', 'kgs', 'kgs.',
        'kilogram', 'kilogram.', 'kilograms', 'kilograms.',
    ];

    /**
     * Items whose unit is a kg-family spelling — the only signal this
     * database has today for "raw material" (resin, masterbatch), since no
     * item-group classification is read anywhere yet.
     */
    /**
     * Items the factory may issue to production.
     *
     * THE COLUMN DECIDES. This is the authoritative eligibility rule for
     * Store -> Production issue, and it exists because there was no such rule:
     * the picker used to offer the WHOLE item master, so a finished good was
     * offered as a requestable input (the owner reported exactly that on
     * 18-Aug-2026, naming BTL-PET-1000).
     *
     * Do NOT reach for scopeKgUom() for this question. It answers a different
     * one — "is this measured in kilograms" — and it is wrong in both
     * directions here: caps are `Nos.` and ARE inputs; packing film is kg and
     * is not resin. Eligibility is configuration, and this is where it lives.
     *
     * Activeness is deliberately NOT folded in: callers that offer NEW work
     * add ->where('is_active', true), while a screen rendering HISTORY must
     * still be able to resolve an input that has since been archived.
     */
    /**
     * What this item's unit MEASURES — weight, a count, or unclassified.
     *
     * The one classifier. Prefer it over hasKgUom() for any new question: that
     * one answers "is this kilograms", which is a narrower thing than it looks
     * and has been standing in for questions it cannot answer (see Q54(a) —
     * packing FILM is measured in kilograms and is not resin).
     */
    public function measurementType(): MeasurementType
    {
        return MeasurementType::forUom($this->uom);
    }

    /** May a quantity of this item carry decimals? Counted things may not. */
    public function permitsFractionalQuantity(): bool
    {
        return $this->measurementType()->permitsFractions();
    }

    public function scopeProductionInput(Builder $query): Builder
    {
        return $query->where('is_production_input', true);
    }

    public function scopeKgUom(Builder $query): Builder
    {
        return $query->whereIn(DB::raw('LOWER(TRIM(uom))'), self::KG_UOM_VARIANTS);
    }

    /**
     * The PHP-side answer for a row already in memory.
     *
     * NOT A TWIN OF scopeKgUom(), and the word was removed on 23-Aug-2026
     * because it had stopped being true. This delegates to isKgUom(), which
     * NORMALISES; the scope ENUMERATES against KG_UOM_VARIANTS in SQL. The
     * two are equal on every spelling Tally can produce, and deliberately
     * unequal beyond it:
     *
     *   "Kgs.."   php=true  sql=false   (PHP rtrim strips a RUN of dots)
     *   "Kgs.\t"  php=true  sql=false   (PHP trim strips tabs; SQL TRIM only spaces)
     *
     * PHP is the superset in both directions of drift, which is the safe way
     * round: the scope narrows a candidate list, so a row it misses is a row
     * a screen does not offer — never a wrong number and never a new refusal.
     * Narrowing PHP to match would flip those inputs accept -> reject, and a
     * new refusal is the one direction this codebase does not permit a
     * cleanup to move (see MeasurementType::forUom, where exactly that was
     * tried and reverted).
     *
     * KgUomParityTest pins BOTH: equality across every real spelling, and
     * these divergences by name, so neither can be mistaken for the other.
     */
    public function hasKgUom(): bool
    {
        return self::isKgUom($this->uom);
    }

    /**
     * IS THIS UNIT KILOGRAMS — the one definition, for a raw string.
     *
     * Until 23-Aug-2026 this question had two answers that disagreed. Four
     * services each carried a private isMassUom() (GoodsReceiptService,
     * ShiftProductionEntryService, BatchEstimationService, BinBayService) —
     * byte-identical to each other, normalising the trailing dot and
     * accepting 'kilogram(s)'. hasKgUom() enumerated the dotted spellings and
     * accepted neither kilogram form. So an item whose Tally unit read
     * 'Kilograms' contributed to a BOM weight norm and simultaneously failed
     * hasKgUom(), and nothing anywhere said which was right.
     *
     * Resolved TOWARDS the services, deliberately: they are the copies doing
     * arithmetic on the answer, four of them already agreed, and widening a
     * filter is the direction that cannot silently drop a real material. The
     * MeasurementType docblock had already caught this drift and recorded
     * that live Tally carries only `Kgs.` — so on today's data every spelling
     * agrees and this is a defensive unification, not a live behaviour change.
     * That was recorded before this change and is not re-verified by it; the
     * live UOM spread is a live read.
     *
     * NOT MeasurementType::forUom(). That is the broader classifier and the
     * right one for "is this weighed rather than counted" — but it answers
     * Weight for grams too, and every caller here SUMS the quantity as
     * kilograms. Routing them through it would silently turn 0.32 g of
     * masterbatch into 0.32 kg. Different question, deliberately not merged.
     */
    public static function isKgUom(?string $uom): bool
    {
        // Normalise rather than enumerate: Tally writes 'Kgs.' with a
        // trailing dot on 90+ live items, and without this they drop out of
        // every kg-family sum (BOM norms, variance, receipt weights).
        return in_array(
            rtrim(mb_strtolower(trim((string) $uom)), '.'),
            ['kg', 'kgs', 'kilogram', 'kilograms'],
            true,
        );
    }

    /**
     * A local-only fixture: it exists in this database and nowhere in Tally.
     * Its missing Tally GUID is intentional, not a gap in the masters — so
     * readiness must not report it as one, and no voucher may ever name it.
     *
     * THE COLUMN DECIDES, NOT THE SKU. This used to be
     * `str_starts_with($sku, 'LOCAL-')`, which put a posting gate on a
     * free-text field. With the SKU becoming owner-managed data across 644
     * items, a typo there could silently stop a real product posting, or start
     * a fixture posting a name Tally cannot accept — neither loudly.
     *
     * The prefix survives as a belt-and-braces fallback for a row written
     * before the column existed. EITHER signal marks the item a fixture —
     * deliberately the conservative direction, because the cost of the two
     * mistakes is not symmetric in the way that matters here: refusing to post
     * leaves a batch fully recorded with a skip logged, while posting a
     * fixture sends Tally a name it cannot accept and leaves the accountant a
     * failed voucher to unpick.
     *
     * A disagreement between the two is not resolved silently — it is logged
     * loudly, because it means either a fixture was created without the flag
     * or somebody typed a SKU that looks like one, and both want a person.
     */
    public function isLocalFixture(): bool
    {
        $flagged = (bool) $this->is_local_fixture;
        $looksLikeOne = str_starts_with((string) $this->sku, self::LOCAL_FIXTURE_SKU_PREFIX);

        if ($flagged !== $looksLikeOne) {
            Log::warning('Item local-fixture flag disagrees with its SKU — treated as a fixture either way.', [
                'item_id' => $this->id,
                'sku' => $this->sku,
                'is_local_fixture' => $flagged,
                'sku_suggests_fixture' => $looksLikeOne,
                'meaning' => $flagged
                    ? 'flagged a fixture but the SKU does not say so — check the SKU was not renamed off the prefix'
                    : 'SKU looks like a fixture but the flag is not set — check whether this item should post to Tally',
            ]);
        }

        return $flagged || $looksLikeOne;
    }

    protected function casts(): array
    {
        return [
            // What kind of thing this is — the column the purchase-order,
            // sales-order and material-request rules read. NULL means nobody
            // has said yet, which is NOT the same as ItemCategory::Other.
            'category' => ItemCategory::class,
            'reorder_level' => 'decimal:4',
            'nominal_weight_grams' => 'decimal:4',
            'nos_per_tray' => 'integer',
            'trays_per_box' => 'integer',
            'nos_per_box' => 'integer',
            // Pouch packing standards — pouch-packed products (Wave A).
            'nos_per_pouch' => 'integer',
            'pouches_per_box' => 'integer',
            // Molding standards (expected-output engine) — seconds per shot
            // and pieces per shot.
            'standard_cycle_time' => 'decimal:2',
            'standard_cavities' => 'integer',
            'tracking_type' => ItemTrackingType::class,
            'is_active' => 'boolean',
            'is_production_input' => 'boolean',
            'is_local_fixture' => 'boolean',
            // "This SKU was seeded from the Tally name, not chosen" — set by
            // the masters pull's create path, cleared by a manual SKU edit
            // (ItemService). Provenance only; no SKU format lives here.
            'sku_provisional' => 'boolean',
            'tally_alter_id' => 'integer',
            'tally_synced_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'item_group_id');
    }

    /**
     * The BASE product this item is a pack variant of, or null when this
     * item IS a base (DEC-20260821-001). Exactly one level deep — a variant
     * of a variant is refused on the way in (see
     * `App\Modules\Inventory\Http\Requests\Concerns\ValidatesVariantLink`,
     * named in full rather than imported: the domain layer carries no
     * dependency on Http, not even a nominal one for a docblock), so this
     * relation never needs walking.
     */
    public function variantOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'variant_of_item_id');
    }

    /** The pack variants that name this item as their base. Empty on a variant, by the same rule. */
    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'variant_of_item_id');
    }

    /**
     * The identity ROOT of this item's variant group — itself when it is a
     * base, its base when it is a variant. One level, no walk, for the same
     * reason variantOf() needs none.
     */
    public function variantRootId(): int
    {
        return (int) ($this->variant_of_item_id ?? $this->getKey());
    }

    /**
     * The name a PERSON should be shown. `name` is Tally's wire key and is
     * locked to Tally's spelling; `display_name` is the ERP's own label when
     * somebody has given one. Never the other way round — nothing that
     * builds a voucher may call this.
     */
    public function displayName(): string
    {
        $chosen = trim((string) $this->display_name);

        return $chosen !== '' ? $chosen : (string) $this->name;
    }

    /** True when this item originated from a Tally masters pull (§3 split-ownership). */
    public function isTallySourced(): bool
    {
        return $this->tally_stock_item_guid !== null;
    }
}
