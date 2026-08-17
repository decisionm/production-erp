<?php

namespace App\Modules\Procurement\Http\Requests\Rules;

use Illuminate\Validation\Rules\Enum;

/**
 * Rule::enum that ALSO admits a list (Phase 6): `?status=sent` and
 * `?status[]=draft&status[]=cancelled` on the same key. A scalar is judged
 * here exactly as Rule::enum judges it (the wrong word → the attribute's
 * own error); a list passes here and is judged member by member by the
 * `{attribute}.*` Rule::enum the request declares beside it (the wrong
 * member → `{attribute}.N`). Extends Enum rather than wrapping it so the
 * Export Center's FilterSchema — which reads `instanceof Enum` to draw a
 * select of the cases — still describes the field as the select it is.
 */
class EnumOrList extends Enum
{
    public function passes($attribute, $value)
    {
        if (is_array($value)) {
            return true;
        }

        return parent::passes($attribute, $value);
    }
}
