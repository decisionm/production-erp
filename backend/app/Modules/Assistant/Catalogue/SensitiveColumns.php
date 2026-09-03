<?php

namespace App\Modules\Assistant\Catalogue;

/**
 * What a column marked `sensitive:` in the catalogue needs before this
 * reader may see it. FC-06 is the origin of the first two: purchase rates
 * and supplier identity are the office's, never the floor's. Holding any
 * one of the listed permissions is enough.
 */
final class SensitiveColumns
{
    public const array KINDS = [
        'rates' => ['carton-trace.view', 'carton-trace.manage', 'finance.view', 'finance.manage'],
        'supplier-identity' => ['procurement.view', 'procurement.manage'],
        'pay' => ['payroll.view', 'payroll.manage'],
        'personal' => ['hrms.manage'],
    ];

    /** @return list<string> */
    public static function permissionsFor(string $kind): array
    {
        return self::KINDS[$kind] ?? [];
    }
}
