<?php

namespace App\Modules\HRMS\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One day the factory is shut. The year is the date's, never a column of its own. */
#[Fillable(['date', 'name'])]
class Holiday extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
