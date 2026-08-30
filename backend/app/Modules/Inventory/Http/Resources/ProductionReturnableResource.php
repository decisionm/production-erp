<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One material standing in the production area, split by what may come back
 * which way.
 *
 * The split is the point of the row. `attributed` is held by open store
 * issues and must go home against their lines so the handover's arithmetic
 * closes; `unattributed` answers no document and is the only part an
 * unattributed return may draw on. Publishing one total would leave the
 * storekeeper guessing, and guessing is how an unattributed return takes
 * another issue's kilograms.
 *
 * `on_floor` may be NEGATIVE and is published as it is. It is a real state
 * (a batch may consume more than was issued), and the sign is the message —
 * `unattributed` is then zero, so nothing can be returned from it.
 *
 * FC-06: no rate, no amount, no supplier. Quantities, units and issue
 * numbers only.
 */
class ProductionReturnableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = (array) $this->resource;

        return [
            'item_id' => $row['item_id'],
            'sku' => $row['sku'],
            'name' => $row['name'],
            'display_name' => $row['display_name'],
            'uom' => $row['uom'],
            // The floor must be able to see WHY a material it is standing next
            // to cannot be requested again — deactivation does not block the
            // way home, and the screen should not imply that it does.
            'item_is_active' => $row['item_is_active'],
            'warehouse_id' => $row['warehouse_id'],
            'on_floor' => $row['on_floor'],
            'attributed' => $row['attributed'],
            'unattributed' => $row['unattributed'],
            'store_issue_lines' => array_map(
                fn (array $line) => [
                    'store_issue_line_id' => $line['store_issue_line_id'],
                    'store_issue_id' => $line['store_issue_id'],
                    'issue_number' => $line['issue_number'],
                    'status' => $line['status'],
                    'outstanding' => $line['outstanding'],
                    'to_warehouse_id' => $line['to_warehouse_id'],
                ],
                $row['store_issue_lines'],
            ),
        ];
    }
}
