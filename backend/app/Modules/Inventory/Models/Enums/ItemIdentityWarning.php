<?php

namespace App\Modules\Inventory\Models\Enums;

/**
 * WHAT LOOKS WRONG ABOUT AN ITEM'S IDENTITY — the eight classes
 * `App\Modules\Inventory\Services\ItemIdentityService` can report. (Named in
 * full rather than imported: this enum is domain vocabulary and the service
 * reads it, not the other way round, so it takes no dependency on Services —
 * not even a nominal one for a docblock.)
 *
 * EVERY ONE OF THESE IS A WARNING. Not one of them blocks, refuses, merges,
 * renames, reclassifies or archives anything, and none may be turned into a
 * gate here: Q43 (does a duplicate master name BLOCK approval or only warn?)
 * and Q59 (which categories may each document use?) are OPEN owner
 * questions, and AGENTS.md is explicit that an agent proposes and the owner
 * decides. The machinery exists so that when an answer arrives it has
 * somewhere to land — and so a person can see today what they would be
 * deciding about.
 *
 * The `value` of each case is the stable string key: it is what the API
 * takes as `?warning=`, what a stored filter would hold, and what the
 * frontend keys its badges on. Renaming one is a breaking change.
 *
 * `label()` is a BADGE, not a sentence — the floor does not read page prose
 * (25-Aug standing rule). The sentence, per item and with the item's own
 * numbers in it, is the `note` the service attaches.
 */
enum ItemIdentityWarning: string
{
    /** Active, not a local fixture, and carries no Tally stock item GUID. */
    case MissingTallyMapping = 'missing_tally_mapping';

    /** Another item master carries this exact name. Q43's surface. */
    case DuplicateName = 'duplicate_name';

    /** Another master's name folds to the same string. A suggestion, never a merge. */
    case PossibleDuplicateMaster = 'possible_duplicate_master';

    /** A shared name where at least one of the set is Tally-linked — what a voucher cannot resolve. */
    case OutboundAmbiguity = 'outbound_ambiguity';

    /** No category has been recorded. Q60. */
    case Unclassified = 'unclassified';

    /** One variant group, more than one unit of measure. */
    case VariantUomConflict = 'variant_uom_conflict';

    /** Classified a finished good, yet named on a purchase-order line. */
    case FgPurchaseConflict = 'fg_purchase_conflict';

    /** Out of service, yet named on a live sales order. */
    case InactiveReferenced = 'inactive_referenced';

    /** Short enough to sit in a badge. */
    public function label(): string
    {
        return match ($this) {
            self::MissingTallyMapping => 'No Tally mapping',
            self::DuplicateName => 'Duplicate name',
            self::PossibleDuplicateMaster => 'Possible duplicate',
            self::OutboundAmbiguity => 'Ambiguous to Tally',
            self::Unclassified => 'Unclassified',
            self::VariantUomConflict => 'Variant UOM conflict',
            self::FgPurchaseConflict => 'FG on a purchase order',
            self::InactiveReferenced => 'Inactive but ordered',
        };
    }

    /** @return list<string> the stable keys, in the order health() reports them */
    public static function keys(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
