<?php

namespace App\Modules\Procurement\Models\Enums;

/** DEC-20260902-026: the five vendor classifications, in the owner's words. */
enum VendorClassification: string
{
    case Resin = 'resin';
    case Packaging = 'packaging';
    case ConsumablesSparesTooling = 'consumables_spares_tooling';
    case Service = 'service';
    case Other = 'other';

    /** The three the Vendors tab and the PO picker show by default. */
    public static function defaults(): array
    {
        return [self::Resin, self::Packaging, self::ConsumablesSparesTooling];
    }

    public function label(): string
    {
        return match ($this) {
            self::Resin => 'Resin',
            self::Packaging => 'Packaging',
            self::ConsumablesSparesTooling => 'Consumables, Spares and Tooling',
            self::Service => 'Service',
            self::Other => 'Other',
        };
    }
}
