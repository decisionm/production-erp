<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\AppSetting;

/**
 * Read/write instance configuration held in the DB (app_settings). Keeps config
 * out of code so onboarding a client is a settings change, not a deploy. Single
 * tenant — one company's config, no scoping.
 */
class AppSettingService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = AppSetting::query()->where('key', $key)->first();

        return $setting !== null ? $setting->value : $default;
    }

    public function set(string $key, mixed $value): void
    {
        AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
