<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Services\PackingMaterialMappingService;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One packing-material mapping as the clients see it.
 *
 * DELEGATES its shape to PackingMaterialMappingService::describe(), the same
 * way MasterbatchDosingResource delegates to its service and for the same
 * reason: the maintenance screen and the batch preview read the same rows,
 * and two independent field lists for one object is exactly how a screen and
 * a report end up disagreeing about the same shift.
 *
 * @mixin PackingMaterialMapping
 */
class PackingMaterialMappingResource extends JsonResource
{
    /**
     * @param  iterable<int, PackingMaterialMapping>  $rows
     * @return list<array<string, mixed>>
     */
    public static function list(iterable $rows): array
    {
        return collect($rows)
            ->map(fn (PackingMaterialMapping $row) => (new self($row))->resolve())
            ->values()
            ->all();
    }

    public function toArray($request): array
    {
        return app(PackingMaterialMappingService::class)->describe($this->resource);
    }
}
