<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The variant tree ProductVariantService::tree() builds, on the wire under
 * `data`. The service already shapes every key (it is the one place the
 * status vocabulary lives), so this resource passes the array through
 * rather than restating it and drifting.
 */
class ProductVariantsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
