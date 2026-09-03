<?php

namespace App\Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Renaming a conversation. The title is the only thing a reader may change
 * about one — the questions and answers are the record of what was asked and
 * stay as they were asked.
 */
class RenameConversationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Same 120 the column holds and the auto-title trims to, so a
            // rename cannot be silently cut in a way a first question is not.
            'title' => ['required', 'string', 'min:1', 'max:120'],
        ];
    }
}
