<?php

namespace App\Modules\Assistant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'message_count' => $this->messages_count ?? ($this->relationLoaded('messages') ? $this->messages->count() : 0),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
