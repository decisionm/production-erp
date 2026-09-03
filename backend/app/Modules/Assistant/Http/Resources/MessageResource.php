<?php

namespace App\Modules\Assistant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'question' => $this->question,
            'sql' => $this->sql,
            'answer' => $this->answer,
            'tables_used' => $this->tables_used ?? [],
            'row_count' => $this->row_count,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
