<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Support\Configuration\Concerns\RecordsConfigurationAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Machine + Product + Mould + Colour — the controlling production standard.
 */
#[Fillable([
    'work_center_id', 'item_id', 'mold_id', 'colour',
    'unit_weight_grams', 'default_cycle_time', 'cycle_time_min', 'cycle_time_max',
    'default_cavities', 'cavities_min', 'cavities_max', 'permitted_cavities',
    'bom_id', 'status', 'effective_from', 'effective_to',
    'source', 'source_reference', 'confirmation_status', 'notes', 'created_by',
    // Who approved and when. Without these on the fillable list the
    // approve() update silently drops them and the audit trail — the whole
    // point of approval being an act rather than a flag — is lost.
    'approved_by', 'approved_at',
])]
class ProductionConfiguration extends Model
{
    use RecordsConfigurationAudit;
    use SoftDeletes;

    /**
     * The Configuration Lifecycle Contract's `can`, stamped by the
     * controller (authoritative) or by the service (cheap, delete: null) so
     * ProductionConfigurationResource never re-derives eligibility. A plain
     * public property, not an Eloquent attribute.
     *
     * @var array{edit: bool, activate: bool, archive: bool, delete: bool|null}|null
     */
    public ?array $can = null;

    protected function casts(): array
    {
        return [
            'unit_weight_grams' => 'decimal:4',
            'default_cycle_time' => 'decimal:2',
            'cycle_time_min' => 'decimal:2',
            'cycle_time_max' => 'decimal:2',
            'default_cavities' => 'integer',
            'cavities_min' => 'integer',
            'cavities_max' => 'integer',
            'permitted_cavities' => 'array',
            'status' => ConfigurationStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function mold(): BelongsTo
    {
        return $this->belongsTo(Mold::class);
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
