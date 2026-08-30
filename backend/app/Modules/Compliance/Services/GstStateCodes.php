<?php

namespace App\Modules\Compliance\Services;

/**
 * The statutory GST state code → state name map.
 *
 * WHY THIS EXISTS. The ERP stores a customer's state as a two-character GST
 * code ('33'), because that is what the first two characters of a GSTIN carry
 * and what GstComputationService compares to decide local vs interstate. But a
 * Tally voucher's STATENAME and PLACEOFSUPPLY want the NAME ('Tamil Nadu'), and
 * nothing in this repository could turn one into the other — the tags simply
 * could not be emitted.
 *
 * THIS IS REFERENCE DATA, NOT A FACTORY DECISION. The codes are fixed by the
 * GST Act's state-code list; they are the same for every taxpayer in India and
 * are not the owner's to choose. That is why they live in code rather than in
 * the factory's configuration.
 *
 * VERIFIED AGAINST THE FACTORY'S OWN VOUCHERS. Every state that appears in the
 * 30-Aug reading of the real Tally Sales export resolves here to exactly the
 * STATENAME Tally itself printed:
 *
 *   34 → Puducherry      (the company's own state, CMPGSTIN 34AAWCS7109K1ZQ)
 *   33 → Tamil Nadu      (32 vouchers)
 *   32 → Kerala          (8 vouchers)
 *   37 → Andhra Pradesh  (4 vouchers)
 *   29 → Karnataka       (1 voucher)
 *
 * A code this map does not know returns null, and the caller REFUSES to stage
 * rather than guessing a name — a wrong STATENAME silently changes the place of
 * supply, which is the field the whole local/interstate split turns on.
 */
final class GstStateCodes
{
    /** @var array<string, string> */
    private const NAMES = [
        '01' => 'Jammu & Kashmir',
        '02' => 'Himachal Pradesh',
        '03' => 'Punjab',
        '04' => 'Chandigarh',
        '05' => 'Uttarakhand',
        '06' => 'Haryana',
        '07' => 'Delhi',
        '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh',
        '10' => 'Bihar',
        '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland',
        '14' => 'Manipur',
        '15' => 'Mizoram',
        '16' => 'Tripura',
        '17' => 'Meghalaya',
        '18' => 'Assam',
        '19' => 'West Bengal',
        '20' => 'Jharkhand',
        '21' => 'Odisha',
        '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh',
        '24' => 'Gujarat',
        '25' => 'Daman & Diu',
        '26' => 'Dadra & Nagar Haveli and Daman & Diu',
        '27' => 'Maharashtra',
        '29' => 'Karnataka',
        '30' => 'Goa',
        '31' => 'Lakshadweep',
        '32' => 'Kerala',
        '33' => 'Tamil Nadu',
        '34' => 'Puducherry',
        '35' => 'Andaman & Nicobar Islands',
        '36' => 'Telangana',
        '37' => 'Andhra Pradesh',
        '38' => 'Ladakh',
        '97' => 'Other Territory',
    ];

    /** The state's name, or NULL for a code this map does not know. Never a guess. */
    public static function name(?string $code): ?string
    {
        $code = is_string($code) ? trim($code) : '';

        return self::NAMES[$code] ?? null;
    }

    /** The state code carried by the first two characters of a GSTIN, or null. */
    public static function fromGstin(?string $gstin): ?string
    {
        $gstin = is_string($gstin) ? trim($gstin) : '';

        if (strlen($gstin) < 2) {
            return null;
        }

        $code = substr($gstin, 0, 2);

        return isset(self::NAMES[$code]) ? $code : null;
    }
}
