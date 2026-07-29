<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Resources\FactorySettingResource;
use App\Modules\Production\Models\FactorySetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FactorySettingController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FactorySettingResource::collection(
            FactorySetting::query()->with('changedBy')->orderBy('scope')->orderBy('key')->get(),
        );
    }

    /**
     * Upsert by key. A change reason is recorded but not forced: these are
     * operating parameters a production manager tunes, and demanding
     * justification for every tweak trains people to type "update".
     */
    public function upsert(Request $request): FactorySettingResource
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'value' => ['present', 'nullable', 'string', 'max:2000'],
            'data_type' => ['sometimes', 'string', 'in:string,integer,decimal,boolean,json'],
            'scope' => ['sometimes', 'string', 'max:32'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'confirmation_status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
            'change_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $setting = FactorySetting::updateOrCreate(
            ['key' => $data['key']],
            [...$data, 'changed_by' => $request->user()?->id],
        );

        return FactorySettingResource::make($setting->fresh('changedBy'));
    }
}
