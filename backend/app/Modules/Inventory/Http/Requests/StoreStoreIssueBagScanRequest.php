<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * One bag scanned at the handover.
 *
 * quantity_kg is OPTIONAL and means "this much was weighed off"; absent
 * means the whole bag. There is NO machine and NO area field, and that is
 * FC-01 with DEC-20260807-006 behind it: resin enters through one common
 * piped loading point, so a resin scan cannot name a machine. (A consumable
 * request — film, cartons, tape — does carry a work centre, and it carries
 * it on the REQUEST, where the ask was made.)
 */
class StoreStoreIssueBagScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'barcode' => ['required', 'string', 'max:255'],
            'quantity_kg' => ['nullable', 'numeric', new PlainDecimal],
            'received_by' => ['nullable', 'integer', 'exists:users,id'],
            // NO `exists:` HERE, deliberately — the ownership check below
            // subsumes it and answers with ONE body. When both ran, a
            // nonexistent line produced two errors and a real line owned by
            // someone else produced one, so line ids were enumerable through
            // the difference. The issue door was collapsed to a single body;
            // this one was left telling them apart.
            'material_request_line_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lineId = $this->input('material_request_line_id');

            if ($lineId === null) {
                return;
            }

            // THE LINE MUST BELONG TO THE ASK THIS ISSUE IS FOR — the same
            // rule the issue's own lines already carry, applied to the scan.
            // `exists` alone let a scan on a headerless issue name ANY real
            // request line, including an unsent draft's or another request's,
            // and file a pointer to it that nothing could reconcile.
            $issue = $this->route('store_issue');
            $headerId = $issue?->material_request_id;

            $belongs = $headerId !== null && DB::table('material_request_lines')
                ->where('id', (int) $lineId)
                ->where('material_request_id', (int) $headerId)
                ->exists();

            if (! $belongs) {
                // ONE BODY for "does not exist", "belongs to someone else" and
                // "this handover has no request". Distinguishing them is a
                // disclosure, not a courtesy: it tells a caller which line ids
                // are real and whose they are.
                $validator->errors()->add(
                    'material_request_line_id',
                    'This scan names a request line that this handover cannot satisfy.',
                );
            }
        });
    }
}
