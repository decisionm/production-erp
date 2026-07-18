<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Models\Enums\GLAccountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'type', 'is_active'])]
class GLAccount extends Model
{
    use SoftDeletes;

    // Eloquent's snake_case table-name guesser mishandles the "GL" acronym
    // ("GLAccount" -> "g_l_accounts" instead of "gl_accounts") — spell it
    // out rather than rename the class.
    protected $table = 'gl_accounts';

    protected function casts(): array
    {
        return [
            'type' => GLAccountType::class,
            'is_active' => 'boolean',
        ];
    }
}
