<?php

namespace App\Modules\TallySync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Stock Summary arriving from the on-site agent, for preview only.
 *
 * `company` is REQUIRED, unlike the masters pull where it is optional for
 * backward compatibility with older agents. There is no backward compatibility
 * to keep here — this endpoint is new — and an opening-stock snapshot that does
 * not say which company it came from is exactly the record that produced six
 * foreign godowns nobody could later identify.
 *
 * Quantities stay STRINGS the whole way. They become opening balances, and a
 * float conversion in transit is a rounding error nobody would ever find.
 */
class StockSummaryPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company' => ['required', 'string', 'max:255'],
            'as_of' => ['required', 'date'],

            'lines' => ['present', 'array'],
            'lines.*.item_guid' => ['nullable', 'string', 'max:255'],
            'lines.*.item_name' => ['nullable', 'string', 'max:255'],
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            'lines.*.godown' => ['nullable', 'string', 'max:255'],
            // Numeric-as-string: `numeric` accepts "1234.5000" and refuses
            // anything that is not a number, without casting it.
            'lines.*.closing_quantity' => ['nullable', 'numeric'],
            'lines.*.closing_rate' => ['nullable', 'numeric'],
            'lines.*.closing_value' => ['nullable', 'numeric'],
        ];
    }
}
