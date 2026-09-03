<?php

namespace App\Modules\Maintenance\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /maintenance/work-orders — the existing asset_id filter plus sort,
 * page and page size (03-Sep-2026). An unknown sort column is refused.
 *
 * parts_cost and total_cost embed the purchase rate (FC-06): the resource
 * omits them for anyone without finance.view / finance.manage, and the same
 * reader may not ORDER the list by them either — ranking work orders by a
 * figure the reader cannot see is still a reading of that figure. So the two
 * cost columns are sortable only for the eyes that see the column.
 */
class ListMaintenanceWorkOrdersRequest extends FormRequest
{
    /** The columns every reader may sort on, besides id — each one a real column of `maintenance_work_orders`. */
    public const SORTABLE = ['type', 'status', 'reported_date', 'labor_cost'];

    /** The columns only finance eyes may sort on. */
    public const COST_SORTABLE = ['parts_cost', 'total_cost'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sort' => ListSort::rule($this->sortableColumns()),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** @return list<string> */
    private function sortableColumns(): array
    {
        $user = $this->user();
        $seesCosts = $user !== null && $user->hasAnyPermission(['finance.view', 'finance.manage']);

        return $seesCosts ? [...self::SORTABLE, ...self::COST_SORTABLE] : self::SORTABLE;
    }
}
