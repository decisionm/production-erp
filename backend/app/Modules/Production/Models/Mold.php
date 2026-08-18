<?php

namespace App\Modules\Production\Models;

use App\Modules\Production\Models\Enums\MoldStatus;
use App\Support\Configuration\Concerns\RecordsConfigurationAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'cavity_count', 'status', 'notes'])]
class Mold extends Model
{
    use RecordsConfigurationAudit;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => MoldStatus::class,
        ];
    }
}
