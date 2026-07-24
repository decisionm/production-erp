<?php

namespace App\Modules\TallySync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The list of Tally companies the agent found on the local install. Reported
 * without a company being selected yet (Tally's company list needs no loaded
 * company), so the Settings UI can offer them for selection.
 */
class SyncCompaniesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ability checked in the controller
    }

    public function rules(): array
    {
        return [
            'companies' => ['required', 'array'],
            'companies.*' => ['required', 'string', 'max:255'],
        ];
    }
}
