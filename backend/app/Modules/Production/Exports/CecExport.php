<?php

namespace App\Modules\Production\Exports;

use App\Modules\Core\Exports\ExportBlockedException;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The CEC slot (MASTER-PLAN P4.5-05, P5.7-02) — date · shift · all shifts —
 * shipped VISIBLY BLOCKED: no CEC sample or format authority exists
 * anywhere in the repo, and a factory document is never invented. The
 * Center lists the slot for production readers with this reason verbatim
 * and answers 409 with it; the moment the owner's sample lands the format
 * becomes a golden test and this class grows columns and rows — until
 * then it has neither.
 *
 * The filters are documented (the catalogue draws the form from them) so
 * the slot already reads as the CEC it will be — date, and shift_id for
 * one shift or none for all — and neither is required: whatever the body,
 * the honest answer today is the reason, not "date is required".
 */
class CecExport extends AbstractProductionExport
{
    public const BLOCKED_REASON = 'CEC FORMAT = BLOCKED — SOURCE DOCUMENT REQUIRED';

    public function key(): string
    {
        return 'cec';
    }

    public function label(): string
    {
        return 'CEC';
    }

    public function filterRules(): array
    {
        return [
            'date' => ['sometimes', 'nullable', 'date'],
            'shift_id' => ['sometimes', 'nullable', 'integer', 'exists:shifts,id'],
        ];
    }

    public function status(): string
    {
        return self::STATUS_BLOCKED;
    }

    public function blockedReason(): ?string
    {
        return self::BLOCKED_REASON;
    }

    /** No layout exists to name columns for. */
    public function columns(?Authenticatable $reader): array
    {
        return [];
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        throw new ExportBlockedException($this, self::BLOCKED_REASON);
    }

    public function count(array $filters, ?Authenticatable $reader): int
    {
        return 0;
    }
}
