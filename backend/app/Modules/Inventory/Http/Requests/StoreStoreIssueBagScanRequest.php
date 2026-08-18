<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'quantity_kg' => ['nullable', 'numeric'],
            'received_by' => ['nullable', 'integer', 'exists:users,id'],
            // THE SAME DANGLING-POINTER HOLE AS THE ISSUE HEADER. A scan may
            // say which accepted line it satisfies; it may not name one that
            // does not exist, because nothing downstream could ever resolve it.
            'material_request_line_id' => ['nullable', 'integer', Rule::exists('material_request_lines', 'id')],
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
                $validator->errors()->add(
                    'material_request_line_id',
                    $headerId === null
                        ? 'This handover is not against a material request, so a scan on it cannot name a request line.'
                        : 'That request line belongs to a different material request from the one this handover is for.',
                );
            }
        });
    }
}
