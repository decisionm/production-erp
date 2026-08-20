<?php

namespace App\Modules\Sales\Models;

use App\Support\Configuration\Concerns\RecordsConfigurationAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'email', 'phone', 'address', 'gstin', 'state_code', 'is_active'])]
class Customer extends Model
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
