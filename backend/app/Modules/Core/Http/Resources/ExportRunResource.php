<?php

namespace App\Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row of "Recent downloads" — the caller's own run, streamed or refused. */
class ExportRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'filters' => (object) ($this->filters ?? []),
            'row_count' => $this->row_count,
            'file_name' => $this->file_name,
            'sha256' => $this->sha256,
            'completed' => $this->completed,
            'refusal_reason' => $this->refusal_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
