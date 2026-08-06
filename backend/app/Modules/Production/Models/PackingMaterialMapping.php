<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Which Tally packing item one workbook spec string means, and the dose that
 * goes with it.
 *
 * The owner's request was "along with Resin and master batch, all other
 * packing consumption also need to calculate — carton box and tray, film
 * pouch and tape". Every one of those four is a JOIN this software did not
 * have: the production standard carries a spec string out of the factory's
 * workbook ("170ML", "200ML BRUTE", "LD 28.5 X 38") and Tally carries an item
 * name ("170 Ml Master Box", "LDPE  COVER (28.5x38x120G)"). Nothing connected
 * the two, so nothing could suggest a quantity.
 *
 * This row IS that join, held as editable master data with provenance — the
 * masterbatch_dosings precedent, for the same reason: the specs that cannot
 * be resolved by evidence today (which cartons take Green tape; what "500ML
 * IFF" means when the catalogue holds two items of that name) are answered by
 * a person in the app, not by a deploy and not by a guess.
 *
 * ## The dose lives on the row, per kind
 *
 *  - carton / tray — no dose column at all. One carton is one carton; the
 *    count comes from the completion's own packing entry.
 *  - pouch_film — `grams_per_piece`, because Tally weighs film in Kgs while
 *    the item name states the weight of ONE piece ("…x120G" = 120 g). The
 *    film is consumed PER CARTON (owner, 31 Jul: the pouch film wraps a
 *    carton's contents once), so kg = cartons × grams ÷ 1000.
 *  - tape — `metres_per_box`, the owner's own unit (TapeMetresPerBox), plus
 *    `metres_per_unit`, the slot the factory's still-unanswered question lands
 *    in (see below).
 *
 * A `tape` row's `spec_value` is the CARTON spec, not a tape name: tape is
 * dosed by the box it seals, so the lookup the screen performs is "what tape,
 * and how much, for a 170ML carton".
 *
 * ## `metres_per_unit` — the tape question, and the slot for its answer
 *
 * TapeMetresPerBox has carried an open question since the figures arrived:
 * Tally counts "Packing Tape - Transparent" in Nos, and whether ONE No is a
 * metre or a whole roll/strip is unanswered. Until it is answered, a tape
 * suggestion is a length and the Tally item is a count of somethings, and
 * filing 229 m as 229 Nos on a live voucher is not a rounding error, it is a
 * different number about a different thing.
 *
 * So the tape line is DISPLAY-ONLY while the two units disagree — shown with
 * its metres and its arithmetic, excluded from what the completion submits.
 * `metres_per_unit` is where the answer lands: metres in ONE Tally unit (a
 * 65 m roll → 65). With it set, metres_per_box ÷ metres_per_unit is a factor
 * in Nos, the conversion is stated on screen, and the line posts like every
 * other. The other shape the answer can take is the item's own unit being
 * corrected to a length unit in the masters (LENGTH_UOM_VARIANTS below), in
 * which case the metres already ARE the Tally quantity and nothing needs
 * converting.
 *
 * WHICH OF THE TWO the factory gives is their call, and both are data — no
 * deploy. PackingMaterialSuggestionService is the one place that reads them
 * and the one place that decides; the screen obeys its `submit_as_stock`
 * rather than re-deriving the rule.
 *
 * NOTE — `metres_per_unit` has no COLUMN yet. It is declared here (fillable,
 * cast, read through metresPerUnit()) so the service, the screen and the tests
 * are already built for the answer; the migration that adds the nullable
 * decimal, the FormRequest rule that keeps it > 0 on the way in, and
 * PackingMaterialMappingService::describe()'s exposure of it ship WITH the
 * factory's answer. Declaring a cast for an absent column is inert — Eloquent
 * reads it as null — and nothing mass-assigns the key, so no write can reach a
 * column that is not there. The service treats null/≤0 as "not answered".
 *
 * ## Advisory, like everything upstream of a weighed figure
 *
 * Nothing here is consumed, posted or reconciled against. It produces a
 * PREFILL; the supervisor's submitted line is what is stored and what Tally
 * receives. See PackingMaterialSuggestionService for why that separation is
 * absolute.
 */
#[Fillable([
    'spec_kind', 'spec_value', 'item_id', 'grams_per_piece', 'metres_per_box',
    'metres_per_unit', 'note', 'set_by', 'set_at',
])]
class PackingMaterialMapping extends Model
{
    use SoftDeletes;

    /** The outer box a batch is packed into ("170 Ml Master Box"). */
    public const KIND_CARTON = 'carton';

    /** The inner tray ("60 Ml Tray"). */
    public const KIND_TRAY = 'tray';

    /** The film/cover that wraps a carton's contents ("LDPE  COVER (28.5x38x120G)"). */
    public const KIND_POUCH_FILM = 'pouch_film';

    /** Sealing tape, dosed in metres per box and keyed by the CARTON spec. */
    public const KIND_TAPE = 'tape';

    /** @var list<string> */
    /**
     * The FINAL carton — the outer box that goes over a finished master box.
     *
     * The owner, four times over two days: "one big box carton, one more big
     * carton has to come", "one final box for all the batches completion need to
     * be add in consumption", "still final 1 carton box ... missing".
     *
     * It is unlike the other four kinds in one way that decides its whole shape:
     * it is NOT a property of the product. The workbook has no column for it and
     * the 38 Tally journals never name one — it applies to every batch, so it is
     * a standing line held once for the factory rather than a spec per bottle.
     * Hence STANDING_SPEC below.
     */
    public const KIND_FINAL_CARTON = 'final_carton';

    /** The polymer cover that goes over that final carton. Same standing shape. */
    public const KIND_POLYMER_COVER = 'polymer_cover';

    /**
     * The spec_value the two standing kinds are stored under.
     *
     * The mapping table is keyed on (spec_kind, spec_value) and these two have no
     * per-product spec to key on, so they take one literal row each. A word
     * rather than an empty string, so a person reading the table can see that it
     * means "every product" and not "a spec somebody forgot to fill in".
     */
    public const STANDING_SPEC = 'ALL PRODUCTS';

    public const KINDS = [
        self::KIND_CARTON,
        self::KIND_TRAY,
        self::KIND_POUCH_FILM,
        self::KIND_TAPE,
        self::KIND_FINAL_CARTON,
        self::KIND_POLYMER_COVER,
    ];

    /**
     * What each kind is counted against, and in what unit — a property of the
     * KIND, not of the item, and deliberately not read from `items.uom`.
     *
     * This factory's item master is not a faithful mirror of Tally's units:
     * the resin items themselves read "NOS" in places, which is why
     * RunMaterialSuggestionService never trusts a uom to decide what a
     * material IS either. The unit here is the one the arithmetic produces.
     *
     *  - `basis` / `quantity_basis` — what the screen multiplies by. Film and
     *    tape are BOTH per-carton: the film wraps the carton's contents and
     *    the tape seals the carton.
     *  - `factor_unit` — what the stored factor is denominated in. It differs
     *    from `unit` for film on purpose: the item name states grams per
     *    piece, Tally moves kilograms, and hiding the ÷1000 inside a prose
     *    reason is how a screen ends up posting 120 kg of film per carton.
     *
     * @var array<string, array{basis: string, quantity_basis: string, unit: string, factor_unit: string}>
     */
    public const KIND_BASIS = [
        self::KIND_CARTON => ['basis' => 'per_carton', 'quantity_basis' => 'cartons', 'unit' => 'nos', 'factor_unit' => 'nos'],
        self::KIND_TRAY => ['basis' => 'per_tray', 'quantity_basis' => 'trays', 'unit' => 'nos', 'factor_unit' => 'nos'],
        self::KIND_POUCH_FILM => ['basis' => 'per_carton', 'quantity_basis' => 'cartons', 'unit' => 'kg', 'factor_unit' => 'g'],
        self::KIND_TAPE => ['basis' => 'per_carton', 'quantity_basis' => 'cartons', 'unit' => 'm', 'factor_unit' => 'm'],
        // ONE PER BATCH, which is what "one final box for all the batches
        // completion" says. Not per carton: a run that packs 40 master boxes
        // still goes out as one consignment in one outer box.
        //
        // The quantity is editable on the row like every other, so a shift that
        // really shipped two says two. What must not happen is a count derived
        // from the carton total, which would issue forty outer boxes and post
        // them.
        self::KIND_FINAL_CARTON => ['basis' => 'per_batch', 'quantity_basis' => 'batch', 'unit' => 'nos', 'factor_unit' => 'nos'],
        // Also per batch — it covers the final carton, so there is one of each.
        // Weighed like a pouch: the counted sheet gives covers per KILOGRAM, so
        // the factor is grams a piece and the quantity is kg.
        self::KIND_POLYMER_COVER => ['basis' => 'per_batch', 'quantity_basis' => 'batch', 'unit' => 'kg', 'factor_unit' => 'g'],
    ];

    /**
     * The LENGTH-family spellings of `items.uom`, lowercased — the only unit
     * an unconverted metres figure may legally be filed against.
     *
     * Deliberately narrow, and deliberately NOT a general uom check. Film's
     * quantity unit is kg while every film item in this factory's master reads
     * "NOS" (StorePackingMaterialMappingRequest refuses a uom rule for exactly
     * that reason), so comparing KIND_BASIS['unit'] to items.uom across the
     * board would drop the film line from every completion — the same defect
     * this list exists to fix, in a different material. It is read for TAPE
     * and nothing else.
     *
     * Mirrors Item::KG_UOM_VARIANTS in spirit: `uom` is Tally's raw free-text
     * base unit, so the spellings have to be tolerated rather than assumed.
     *
     * @var list<string>
     */
    public const LENGTH_UOM_VARIANTS = ['m', 'm.', 'mt', 'mts', 'mtr', 'mtr.', 'mtrs', 'metre', 'metres', 'meter', 'meters'];

    protected function casts(): array
    {
        return [
            // decimal:4, so the figure reaches bcmath as an exact string
            // rather than a float that has already lost precision. Both are
            // multiplied into a quantity a person has to be able to check.
            'grams_per_piece' => 'decimal:4',
            'metres_per_box' => 'decimal:4',
            // No column yet — see the class docblock. Cast so that the day the
            // migration lands, the figure arrives as an exact string like its
            // two neighbours rather than a float that has already drifted.
            'metres_per_unit' => 'decimal:4',
            'set_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /** What this row's factor multiplies, in what unit — never read off the item. */
    public function basis(): array
    {
        return self::KIND_BASIS[$this->spec_kind] ?? self::KIND_BASIS[self::KIND_CARTON];
    }

    /**
     * How much of this material one basis unit takes, as a string, or null
     * when the row carries no dose yet.
     *
     * Cartons and trays are one-for-one by definition — a carton packed is a
     * carton consumed — so their factor is a literal '1' and there is no
     * column for it to drift out of step with.
     */
    public function factor(): ?string
    {
        return match ($this->spec_kind) {
            // Both weighed materials read their grams: a polymer cover is bought
            // by the kilogram exactly as a pouch is.
            self::KIND_POUCH_FILM, self::KIND_POLYMER_COVER => $this->grams_per_piece === null ? null : (string) $this->grams_per_piece,
            self::KIND_TAPE => $this->metres_per_box === null ? null : (string) $this->metres_per_box,
            default => '1',
        };
    }

    /**
     * Metres in ONE Tally unit of this tape, as a string, or null when the
     * factory has not answered — which includes a zero or a negative.
     *
     * The > 0 guard lives here rather than only in a FormRequest on purpose:
     * this figure is a DIVISOR, and the hazard is not a bad edit, it is a zero
     * reaching the division and turning a tape line into an infinity or a
     * fatal. A rule in the write path guards the typist; this guards the
     * arithmetic, including a row that predates the rule or was written by a
     * seed. Null here means "not answered", which is the safe branch:
     * PackingMaterialSuggestionService leaves the line display-only.
     */
    public function metresPerUnit(): ?string
    {
        if ($this->spec_kind !== self::KIND_TAPE || $this->metres_per_unit === null) {
            return null;
        }

        $value = (string) $this->metres_per_unit;

        return bccomp($value, '0', 8) === 1 ? $value : null;
    }

    /**
     * Does this row's Tally item COUNT IN A LENGTH — i.e. do the mapping's
     * metres and the item's own unit already agree?
     *
     * Read for tape and nothing else (see LENGTH_UOM_VARIANTS). `true` is the
     * other way the factory's answer can arrive: they correct the tape item's
     * unit in the masters, the metres are the Tally quantity as they stand,
     * and nothing needs converting.
     */
    public function itemCountsInLength(): bool
    {
        return in_array(strtolower(trim((string) $this->item?->uom)), self::LENGTH_UOM_VARIANTS, true);
    }
}
