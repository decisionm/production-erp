<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One product-level standard variant: cavities + weight + cycle time, with
 * the packaging options that standard can be packed in.
 */
#[Fillable([
    'item_id', 'source_product_name', 'cavities', 'unit_weight_grams',
    'cycle_time', 'cycle_time_raw', 'carton_spec', 'tray_spec', 'pouch_spec',
    'spec_provenance', 'status', 'unresolved_reason',
    'source', 'source_reference', 'confirmation_status', 'notes',
    'approved_by', 'approved_at', 'created_by',
    'item_attached_by', 'item_attached_at',
])]
class ProductionStandard extends Model
{
    use SoftDeletes;

    /** A standard added by hand in the app, not read out of the factory workbook. */
    public const SOURCE_MANUAL = 'MANUAL';

    protected function casts(): array
    {
        return [
            'cavities' => 'integer',
            'unit_weight_grams' => 'decimal:4',
            'cycle_time' => 'decimal:2',
            'approved_at' => 'datetime',
            'spec_provenance' => 'array',
            'item_attached_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function packagings(): HasMany
    {
        return $this->hasMany(ProductionStandardPackaging::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function itemAttachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'item_attached_by');
    }

    /**
     * A short human label distinguishing sibling variants of one product.
     *
     * IT HAS TO NAME THE PACK, and that was the whole defect. The label used to
     * carry cavities, weight and cycle time — and the screen's own hint still
     * said "same product, different cavity / weight / cycle time" — but 18 of the
     * workbook's 103 rows are ONE product counted two ways, identical in all
     * three of those and differing only in how many bottles go in a box.
     *
     * So the floor saw two buttons reading "5 cav · 12.9 g · 12.3 s" and "5 cav ·
     * 12.9 g · 12.3 s" and had to choose between them. The owner reported it from
     * the demo (05-Aug): "the number like 840 on 100 ml round pet clear, they say
     * for one round 840, another time 810". Both are right — 840 is the
     * pouch-packed count and 810 the tray-packed one — and neither was on screen.
     *
     * Their Tally agrees that the pack is part of a product's identity: 17 items
     * carry the count in the name, "B.100 Ml Round Pet Bottle Clear 12.9 Gms -840
     * Nos" among them.
     *
     * Only when there is more than one box count to distinguish, and only the
     * counts themselves. A variant with a single pack needs no "/box" on it, and
     * a label that grows a part nobody needs is how a button stops fitting on a
     * phone-width card.
     */
    public function variantLabel(): string
    {
        $parts = array_filter([
            $this->cavities !== null ? "{$this->cavities} cav" : null,
            $this->unit_weight_grams !== null ? rtrim(rtrim((string) $this->unit_weight_grams, '0'), '.').' g' : null,
            $this->cycle_time !== null ? rtrim(rtrim((string) $this->cycle_time, '0'), '.').' s' : null,
            $this->packLabel(),
        ]);

        return $parts === [] ? 'standard' : implode(' · ', $parts);
    }

    /**
     * The box counts this standard packs to — "840/box", or "840 or 810/box" when
     * it carries both — or null when it has nothing to say.
     *
     * Reads the loaded relation rather than querying, because every caller loads
     * `packagings` already (ProductionStandardResolver::variantsFor) and a label
     * that fires a query per variant turns a list of eight into eight queries.
     */
    private function packLabel(): ?string
    {
        if (! $this->relationLoaded('packagings')) {
            return null;
        }

        $counts = $this->packagings
            ->pluck('nos_per_box')
            ->filter(fn ($count) => $count !== null && (int) $count > 0)
            ->map(fn ($count) => (int) $count)
            ->unique()
            ->sort()
            // Descending, so the bigger pack reads first — the factory says
            // "840 or 810", in that order, because 840 is the fuller box.
            ->reverse()
            ->values();

        if ($counts->isEmpty()) {
            return null;
        }

        return $counts->implode(' or ').'/box';
    }
}
