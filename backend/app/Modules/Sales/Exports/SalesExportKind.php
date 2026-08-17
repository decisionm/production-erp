<?php

namespace App\Modules\Sales\Exports;

use App\Modules\Core\Exports\AbstractExportKind;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the three Sales kinds share (Phase 4.5): the module and its
 * permission pair, the list request's grammar minus its paging, and the
 * resource-to-row step.
 *
 * Every Sales kind is a Sales list, downloaded — GET /sales/{list} with the
 * SAME filters (the List*Request's rules, delegated), the SAME query and
 * order (the service's cursor() beside paginate()), and every row built
 * THROUGH the list's JsonResource for THIS reader, so what the file says is
 * what the screen says. Nothing FC-06 lives on a sales document — a selling
 * price is the customer's, a customer is not a supplier — so the columns are
 * the same for every sales reader; the discipline is inherited all the same,
 * so a gate added to a resource tomorrow reaches the file without a second
 * edit.
 */
abstract class SalesExportKind extends AbstractExportKind
{
    public function module(): string
    {
        return 'sales';
    }

    public function permissionAny(): array
    {
        return ['sales.view', 'sales.manage'];
    }
}
