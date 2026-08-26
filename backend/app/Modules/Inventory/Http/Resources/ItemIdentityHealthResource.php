<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Enums\ItemIdentityWarning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * THE COUNT PER WARNING CLASS — the shape the item list's badge row keys on.
 *
 * `warnings` always carries EVERY class, in {@see ItemIdentityWarning}'s
 * declared order, including the ones sitting at zero. A badge that appears
 * and disappears as its count crosses zero makes the row jump under
 * somebody's cursor; the frontend decides what to do with a zero, this
 * endpoint does not decide for it.
 */
class ItemIdentityHealthResource extends JsonResource
{
    /** The resource is a plain array from the service; no model wrapping to undo. */
    public static $wrap = 'data';

    public function toArray(Request $request): array
    {
        /** @var array{items: int, items_with_any_warning: int, warnings: list<array{class: string, label: string, count: int}>} $health */
        $health = $this->resource;

        return [
            'items' => $health['items'],
            'items_with_any_warning' => $health['items_with_any_warning'],
            'warnings' => $health['warnings'],
        ];
    }
}
