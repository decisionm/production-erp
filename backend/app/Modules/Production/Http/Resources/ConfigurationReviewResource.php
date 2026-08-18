<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The review list ConfigurationReviewService::review() builds, on the wire
 * under `data` as `{rows: [...]}`. Shaped in the service (one place for the
 * row vocabulary); passed through here.
 */
class ConfigurationReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
