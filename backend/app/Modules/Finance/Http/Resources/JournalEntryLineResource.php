<?php

namespace App\Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gl_account' => GLAccountResource::make($this->whenLoaded('glAccount')),
            'debit' => $this->debit,
            'credit' => $this->credit,
            'memo' => $this->memo,
        ];
    }
}
