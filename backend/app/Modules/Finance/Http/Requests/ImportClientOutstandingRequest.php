<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Tally "Group Outstandings" export, uploaded by hand.
 *
 * WRITE-GATED, NOT READ-GATED. Reading the debtor book needs `finance.view`;
 * REPLACING it is a different act and needs `finance.manage`. A pull replaces
 * the whole position for the bound company, so anyone who can do this can
 * change what every collection call is based on.
 *
 * THE FILE IS NOT VALIDATED BY MIME TYPE. Tally writes this export as UTF-16
 * XML with a .xml extension, and PHP's finfo reports that as text/plain on
 * some hosts and application/octet-stream on others — a mimes: rule would
 * reject the real file on the real server for reasons nobody could see. The
 * parser is the real gate: it either finds bills in the document or it finds
 * none, and finding none is reported rather than written.
 *
 * `as_of` IS REQUIRED AND IS NOT `today`. It is the date the position was read
 * as at — the "to" date of the Tally report, which the person exporting it
 * chose. Defaulting it would let a month-old export be filed as current, and
 * the page's ageing is computed against it.
 */
class ImportClientOutstandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finance.manage') === true;
    }

    public function rules(): array
    {
        return [
            // 20MB: the measured 03-Sep-2026 export of the live company is
            // 1MB as UTF-16 for 621 bills, so this is roughly a decade of
            // headroom rather than a number tuned to one file.
            'file' => ['required', 'file', 'max:20480'],
            'as_of' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
