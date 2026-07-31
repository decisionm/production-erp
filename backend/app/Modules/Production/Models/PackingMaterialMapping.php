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
 *  - tape — `metres_per_box`, the owner's own unit (TapeMetresPerBox).
 *
 * A `tape` row's `spec_value` is the CARTON spec, not a tape name: tape is
 * dosed by the box it seals, so the lookup the screen performs is "what tape,
 * and how much, for a 170ML carton".
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
    'note', 'set_by', 'set_at',
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
    public const KINDS = [self::KIND_CARTON, self::KIND_TRAY, self::KIND_POUCH_FILM, self::KIND_TAPE];

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
    ];

    protected function casts(): array
    {
        return [
            // decimal:4, so the figure reaches bcmath as an exact string
            // rather than a float that has already lost precision. Both are
            // multiplied into a quantity a person has to be able to check.
            'grams_per_piece' => 'decimal:4',
            'metres_per_box' => 'decimal:4',
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
            self::KIND_POUCH_FILM => $this->grams_per_piece === null ? null : (string) $this->grams_per_piece,
            self::KIND_TAPE => $this->metres_per_box === null ? null : (string) $this->metres_per_box,
            default => '1',
        };
    }
}
