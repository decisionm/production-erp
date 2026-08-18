<?php

namespace App\Modules\Inventory\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A NUMBER A STOREKEEPER WRITES — and the ONLY definition of one.
 *
 * `numeric` accepts `1e3`, `0x1A`, `INF` and `NAN`; bcmath accepts none of
 * them and throws a ValueError, so every quantity path that validated with
 * `numeric` alone and then reached `bcadd`/`bccomp` answered a malformed
 * figure with a **500** instead of a 422. The set below is exactly what bcmath
 * takes: `.5`, `1.` and `+5` included, because narrowing past that started
 * refusing spellings the old code handled happily.
 *
 * THIS CLASS EXISTS BECAUSE THE SAME PREDICATE HAS NOW DRIFTED FOUR TIMES on
 * one branch. Four call sites asked "is this a weight?" three ways, which is
 * why MeasurementType was written. A validation rule and the private guard it
 * feeds were spelled differently, so `+12.5` cleared the rule, missed the
 * guard, and put fractional trays in Production/WIP. A boolean flag was
 * validated one way and read another, so the floor's own page 422'd itself
 * blank. Writing this predicate down a fifth time was not the answer.
 */
class PlainDecimal implements ValidationRule
{
    private const PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    /** For the `after()` guards, which need the question answered, not a message. */
    public static function matches(mixed $value): bool
    {
        return is_scalar($value) && preg_match(self::PATTERN, (string) $value) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::matches($value)) {
            $fail('The :attribute must be an ordinary number — digits, with an optional decimal point.');
        }
    }
}
