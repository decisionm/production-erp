<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Instance-level configuration held in the DB rather than in code, so a new
 * client is onboarded by editing config in the app (Settings UI), never by
 * changing source. Single-tenant: these are this one company's settings, no
 * tenant scoping (TECHNICAL-DOCS.md §2).
 */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
