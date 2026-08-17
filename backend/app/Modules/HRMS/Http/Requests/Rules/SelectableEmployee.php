<?php

namespace App\Modules\HRMS\Http\Requests\Rules;

use App\Modules\HRMS\Models\Enums\EmployeeStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * WHICH EMPLOYEES A NEW RECORD MAY NAME — one rule, one place.
 *
 * The audit's second matrix finding, for this master: every employee picker
 * in the repo used a bare `exists:employees,id`, and that rule queries the
 * table directly. It therefore accepted an ARCHIVED (soft-deleted) employee
 * and an inactive or terminated one alike — so a person who had been taken
 * out of service was still selectable as tomorrow's operator, supervisor,
 * CAPA owner or salary-structure subject. The Configuration Lifecycle
 * Contract's Activate/Deactivate means nothing if deactivating does not
 * remove the row from NEW selection.
 *
 * WHAT IT DOES NOT DO, and this is the half that matters just as much:
 * nothing here touches HISTORY. Existing rows keep the employee they name,
 * every report still renders them, and no past shift, payslip or attendance
 * is re-validated. Only the act of choosing an employee for something NEW is
 * narrowed.
 *
 * This WIDENS the refusal set on live data — an in-flight form naming an
 * archived employee now fails validation instead of silently succeeding —
 * which is the intended correction, and it is pinned by its own test.
 */
final class SelectableEmployee
{
    public static function rule(): Exists
    {
        return Rule::exists('employees', 'id')
            ->whereNull('deleted_at')
            ->where('status', EmployeeStatus::Active->value);
    }
}
