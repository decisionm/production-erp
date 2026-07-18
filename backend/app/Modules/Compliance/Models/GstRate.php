<?php

namespace App\Modules\Compliance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['hsn_sac_code', 'description', 'rate_percent', 'is_active'])]
class GstRate extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
