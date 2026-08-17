<?php

namespace App\Modules\Production\Models;

use App\Support\Configuration\Concerns\RecordsConfigurationAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'is_active'])]
class ScrapReason extends Model
{
    use RecordsConfigurationAudit;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
