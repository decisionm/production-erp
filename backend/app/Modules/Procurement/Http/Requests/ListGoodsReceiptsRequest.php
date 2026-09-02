<?php

namespace App\Modules\Procurement\Http\Requests;

/**
 * GET /procurement/goods-receipts — the shared filters plus
 * purchase_order_id and id; from/to are FACTORY DAYS on received_date (a
 * datetime, like a delivery's delivered_date); sorts on received_date. A
 * receipt has no status of its own — the order it arrived against carries
 * one.
 *
 * `id` is ONE receipt — what the register's `?grn=7` deep link (from the
 * material-lot register) asks for, so the page can let the server narrow
 * instead of reading the whole register to find one row. It composes with
 * `q` and the rest like any other filter.
 */
class ListGoodsReceiptsRequest extends ListProcurementDocumentsRequest
{
    protected function sortableColumns(): array
    {
        return ['received_date'];
    }

    protected function documentRules(): array
    {
        return [
            'purchase_order_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
