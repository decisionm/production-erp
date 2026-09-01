<?php

namespace App\Modules\Inventory\Models\Enums;

/**
 * WHAT CONDITION MATERIAL CAME BACK IN — recorded on the return, and on the
 * stock movement the return writes.
 *
 * DEC-20260831-005 settled that unused material may come back from
 * Production/WIP to the Store, partially or in full, naming its Store Issue
 * where one exists. It settled the QUANTITY and the LINKAGE. It did not
 * settle what the storekeeper should say about the state the material is in,
 * and until 31-Aug-2026 the ERP had nowhere to put that answer: a return was
 * a bare transfer with a reference string, so resin that came back clean and
 * resin that came back wet were the same row in the ledger.
 *
 * TWO STATES, AND NO THIRD ONE INVENTED. The owner's instruction asks a
 * return to carry a quality state; it does not name a vocabulary, so this
 * enum stays at the smallest pair that can answer the question a storekeeper
 * is actually being asked at the hatch — is this fit to issue again, or not:
 *
 *  · `good`   — fit to go back out. The DEFAULT, and what every return
 *               before this column existed is read as, because that is what
 *               the factory was recording when it had no way to say
 *               otherwise. Reading a historical NULL as anything else would
 *               invent a condition nobody wrote down.
 *  · `damaged` — not fit to go back out as it stands. Wet, torn, spilled,
 *               contaminated, dropped — the word covers the state, not the
 *               cause, and the cause goes in the notes.
 *
 * IT CHANGES NO STOCK ARITHMETIC — deliberately, and this is the boundary,
 * not an omission. A `damaged` return moves exactly the quantity a `good`
 * one moves, into exactly the same Store, and the balance afterwards is
 * identical. What happens NEXT to damaged material — whether the Store may
 * re-issue it, whether it waits for QA, whether it is written off — is a
 * factory decision about what this factory permits, and no agent may pick
 * one. It is asked, narrowed, as Q89 in PENDING-OWNER-QUESTIONS.md.
 *
 * WHAT *IS* SETTLED IS THE OTHER CATEGORY, AND IT DOES NOT REACH THIS DOOR.
 * Damaged FINISHED GOODS become Scrap (DEC-20260901-002). This enum is on the
 * production-RETURN path, which carries raw material, packing material and
 * consumables coming back off the floor — and the owner narrowed Q89 to
 * exactly those three, expressly leaving them undecided. Scrap is a produced
 * OUTPUT this factory books inward per colour (FC-02); a wet sack of resin is
 * a purchased INPUT, and nothing says the two share a disposition. Reading
 * the finished-goods answer across onto a return is the guess that was ruled
 * out, so `damaged` still does nothing here.
 *
 * This is the same shape DEC-20260831-002 took for the stock screen: record
 * and show the fact, change no write path, and let the owner decide what the
 * fact should DO. A recorded condition nobody acts on yet is still strictly
 * better than a condition nobody recorded — the second one cannot be acted
 * on later, because the evidence was never kept.
 */
enum ReturnedQualityState: string
{
    case Good = 'good';

    case Damaged = 'damaged';

    /**
     * How a NULL reads. Every return written before the column existed, and
     * every caller that does not say, means `good` — see the docblock.
     */
    public static function fromNullable(?string $value): self
    {
        return $value === null ? self::Good : self::from($value);
    }

    /** The words a person sees. */
    public function label(): string
    {
        return match ($this) {
            self::Good => 'Good',
            self::Damaged => 'Damaged',
        };
    }

    /** For validation rules and pickers, in the order a person reads them. */
    public static function values(): array
    {
        return array_map(fn (self $state): string => $state->value, self::cases());
    }
}
