<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /inventory/serial-numbers — `sort` on the serial number or its status.
 * Absent is newest first. The other readers stay on the controller, for the
 * reasons on ListBatchesRequest.
 */
class ListSerialNumbersRequest extends FormRequest
{
    public const SORTABLE = ['serial_number', 'status'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sort' => ListSort::rule(self::SORTABLE),
        ];
    }

    public function sort(): ?string
    {
        $value = $this->validated('sort');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
