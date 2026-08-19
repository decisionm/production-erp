<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use App\Modules\Inventory\Models\Enums\MeasurementType;
use App\Support\Configuration\Concerns\RecordsConfigurationAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Fillable([
    'sku', 'sku_provisional', 'name', 'description', 'uom', 'hsn_sac_code', 'reorder_level',
    'nominal_weight_grams', 'nos_per_tray', 'trays_per_box', 'nos_per_box',
    'nos_per_pouch', 'pouches_per_box',
    'colour', 'standard_cycle_time', 'standard_cavities',
    'tracking_type', 'is_active', 'is_local_fixture', 'is_production_input',
    'tally_stock_item_guid', 'tally_company', 'tally_alter_id', 'tally_synced_at', 'item_group_id',
])]
class Item extends Model
{
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
     * @var list<string>
     */
    public const KG_UOM_VARIANTS = ['kg', 'kg.', 'kgs', 'kgs.'];

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

    /** PHP-side twin of scopeKgUom(), for rows already in memory. */
    public function hasKgUom(): bool
    {
        return in_array(strtolower(trim((string) $this->uom)), self::KG_UOM_VARIANTS, true);
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

    /** True when this item originated from a Tally masters pull (§3 split-ownership). */
    public function isTallySourced(): bool
    {
        return $this->tally_stock_item_guid !== null;
    }
}
