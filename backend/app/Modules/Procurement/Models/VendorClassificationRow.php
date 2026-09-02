<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Models\Enums\VendorClassification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['vendor_id', 'classification'])]
class VendorClassificationRow extends Model
{
    protected $table = 'vendor_classifications';

    protected function casts(): array
    {
        return ['classification' => VendorClassification::class];
    }
}
