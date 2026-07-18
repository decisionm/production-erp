<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['sku', 'name', 'description', 'uom', 'reorder_level', 'is_active'])]
class Item extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
