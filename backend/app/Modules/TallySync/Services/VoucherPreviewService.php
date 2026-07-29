<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\ShiftProductionEntry;

/**
 * What Tally is about to be sent, resolved against the masters that actually
 * exist, BEFORE anything is posted.
 *
 * The failure this prevents: a voucher is built from names (Tally's API keys
 * on stock-item and godown NAMES, not ids), so a name Tally does not know
 * fails the whole voucher — and today that failure surfaces hours after the
 * shift, in the Failed tab, to someone who wasn't there. The preview resolves
 * every name up front and says which ones Tally will reject.
 *
 * The payload itself comes from TallySyncService::buildBatchVoucherPayload()
 * — the same method the real post uses, so the preview cannot drift from
 * what gets sent.
 */
class VoucherPreviewService
{
    public function __construct(
        private readonly TallySyncService $sync,
    ) {}

    /**
     * @return array{
     *     voucher: array<string, mixed>,
     *     lines: list<array{side: string, item: ?string, quantity: mixed, uom: ?string, godown: ?string, problems: list<string>}>,
     *     problems: list<string>,
     *     postable: bool,
     * }
     */
    public function forShiftProductionEntry(ShiftProductionEntry $entry): array
    {
        $payload = $this->sync->buildBatchVoucherPayload($entry);

        $lines = [];
        foreach ($payload['consumed'] ?? [] as $line) {
            $lines[] = $this->resolveLine('consumption', $line['item'] ?? null, $line['quantity'] ?? null, $line['godown'] ?? null);
        }
        foreach ($payload['produced'] ?? [] as $line) {
            // Production lines carry no godown of their own — they land in
            // the voucher's godown (the entry's FG warehouse).
            $lines[] = $this->resolveLine('production', $line['item'] ?? null, $line['quantity'] ?? null, $payload['godown'] ?? null);
        }

        $problems = [];
        if (($payload['voucher_date'] ?? null) === null) {
            // The exact cause of the live INV-1 failure — a voucher with no
            // date is rejected outright.
            $problems[] = 'The voucher has no date — Tally rejects an undated voucher.';
        }
        if (($payload['godown'] ?? null) === null) {
            $problems[] = 'The voucher has no godown.';
        }
        if ($lines === []) {
            $problems[] = 'The voucher has no stock lines.';
        }

        $lineProblems = array_merge(...array_map(fn ($line) => $line['problems'], $lines ?: [['problems' => []]]));

        return [
            'voucher' => $payload,
            'lines' => $lines,
            'problems' => $problems,
            'postable' => $problems === [] && $lineProblems === [],
        ];
    }

    /**
     * Resolve one line's item and godown against the masters, reporting the
     * exact reason Tally would refuse it.
     *
     * Items are matched by NAME because that is what the voucher carries and
     * what Tally matches on — an item that exists in the ERP under a
     * different name than in Tally is precisely the failure being hunted.
     *
     * @return array{side: string, item: ?string, quantity: mixed, uom: ?string, godown: ?string, problems: list<string>}
     */
    private function resolveLine(string $side, ?string $itemName, mixed $quantity, ?string $godownName): array
    {
        $problems = [];

        $item = $itemName !== null
            ? Item::query()->where('name', $itemName)->first()
            : null;

        if ($itemName === null) {
            $problems[] = 'Line has no stock item.';
        } elseif ($item === null) {
            $problems[] = "No item named \"{$itemName}\" exists.";
        } elseif ($item->tally_stock_item_guid === null) {
            $problems[] = "\"{$itemName}\" has no Tally identity — Tally will answer \"Stock Item does not exist\".";
        }

        if ($godownName === null) {
            $problems[] = 'Line has no godown.';
        } else {
            $godown = Warehouse::query()->where('name', $godownName)->first();
            if ($godown === null) {
                $problems[] = "No warehouse named \"{$godownName}\" exists.";
            } elseif ($godown->tally_guid === null) {
                $problems[] = "Godown \"{$godownName}\" does not exist in Tally.";
            }
        }

        if ($quantity === null || (float) $quantity <= 0) {
            $problems[] = 'Quantity is zero or missing.';
        }

        return [
            'side' => $side,
            'item' => $itemName,
            'quantity' => $quantity,
            'uom' => $item?->uom,
            'godown' => $godownName,
            'problems' => array_values($problems),
        ];
    }
}
