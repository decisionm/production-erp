<?php

namespace App\Modules\Quality\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncomingInspectionRequest extends FormRequest
{
    /**
     * THE BOUND IS THE COLUMN, NOT A GUESS.
     *
     * `incoming_inspections.{inspected,accepted,rejected}_quantity` and
     * `goods_receipt_note_lines.quantity` are all `decimal(15, 4)` — see
     * 2026_07_18_171930_create_incoming_inspections_table.php and
     * 2026_07_18_160522_create_goods_receipt_note_lines_table.php. 15 total
     * digits less 4 fractional leaves ELEVEN integer digits, so the largest
     * representable figure is 99999999999.9999 and `max:99999999999` is the
     * whole-number ceiling every other quantity path in this repo already
     * spells for the same column shape (StoreGoodsReceiptRequest,
     * StoreStockIssueRequest, StoreMaterialLotRequest, and a dozen more).
     * Copied verbatim rather than re-derived.
     */
    private const DECIMAL_15_4_MAX = 99999999999;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'goods_receipt_note_line_id' => ['required', 'integer', 'exists:goods_receipt_note_lines,id'],

            /*
             * `numeric` ALONE WAS A 500, AND IT WAS REACHABLE FROM THE PUBLIC
             * API. `numeric` accepts `1e3`, `0x1A`, `INF` and `NAN`; bcmath
             * accepts none of them and raises a ValueError. Reproduced on this
             * branch before the fix: POSTing `inspected_quantity: "1e3"` to
             * /api/v1/quality/incoming-inspections returned
             *
             *     500  bccomp(): Argument #1 ($num1) is not well-formed
             *     IncomingInspectionService.php:60
             *
             * which is the exact failure App\Rules\PlainDecimal was written
             * for. Its docblock says the predicate had already drifted four
             * times and that writing it a fifth was not the answer — so this
             * imports the rule instead of restating the pattern.
             *
             * THE `max` HALF is the same failure by another road. A JSON
             * number past PHP's integer range is decoded as a FLOAT, and a
             * float of that size stringifies to E-notation — verified:
             * json_decode('1e20') gives `(string) 1.0E+20`, which is another
             * `bccomp(): not well-formed` 500. Bounding at the column's own
             * ceiling makes that a 422 before the service ever sees it.
             *
             * WHAT NEITHER RULE CAN FIX, STATED HONESTLY. A JSON number that
             * is *within* the bound but carries more significant digits than
             * PHP's float→string precision of 14 is already lossy by the time
             * validation runs: json_decode('12345678901.2345') stringifies to
             * '12345678901.235', which is MORE than was received and is
             * therefore refused at exact equality. The digits are gone before
             * any rule here can look at them. The fix for that is the caller
             * sending a decimal STRING, which the rebuilt Incoming Quality
             * page now does, and which
             * IncomingInspectionPendingQueueTest::
             * test_a_decimal_string_survives_where_a_json_float_would_not
             * pins from both sides.
             */
            'inspected_quantity' => ['required', 'numeric', 'gt:0', 'max:'.self::DECIMAL_15_4_MAX, new PlainDecimal],
            'accepted_quantity' => ['required', 'numeric', 'min:0', 'max:'.self::DECIMAL_15_4_MAX, new PlainDecimal],
            'rejected_quantity' => ['required', 'numeric', 'min:0', 'max:'.self::DECIMAL_15_4_MAX, new PlainDecimal],

            'inspection_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
