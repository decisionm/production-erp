<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Production\Models\MasterbatchDosing;
use App\Modules\Production\Services\MasterbatchDosingService;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One dosing as the clients see it.
 *
 * DELEGATES its shape to MasterbatchDosingService::describe() instead of
 * listing fields itself, deliberately: the Start Batch / completion preview
 * embeds the very same block, and two independent field lists for one object
 * is exactly how a screen and a report end up disagreeing about the same
 * shift. The Resource exists so the module's HTTP layer looks like its
 * neighbours; the service remains the single shaping place.
 *
 * @mixin MasterbatchDosing
 */
class MasterbatchDosingResource extends JsonResource
{
    private ?int $bottles = null;

    /**
     * The bottle count the kg should be quoted against. Absent = master data
     * only: `suggested_kg` comes back null, because a kg with no bottle count
     * behind it is a figure nobody can check.
     */
    public function forBottles(?int $bottles): self
    {
        $this->bottles = $bottles;

        return $this;
    }

    /**
     * @param  iterable<int, MasterbatchDosing>  $rows
     * @return list<array<string, mixed>>
     */
    public static function listForBottles(iterable $rows, ?int $bottles): array
    {
        return collect($rows)
            ->map(fn (MasterbatchDosing $row) => (new self($row))->forBottles($bottles)->resolve())
            ->values()
            ->all();
    }

    public function toArray($request): array
    {
        return app(MasterbatchDosingService::class)->describe($this->resource, $this->bottles);
    }
}
