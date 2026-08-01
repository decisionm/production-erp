<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TallyGodownResolver;
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
 *
 * THREE KINDS OF SENTENCE, and the difference matters to the person reading:
 *
 *  - `problems` (voucher-level) and `lines[].problems` are BLOCKERS. Anything
 *    here makes `postable` false, and the owner's rule is that posting stays
 *    unavailable while it is: "If the Tally preview is invalid, posting must
 *    remain unavailable." Each one names a thing a person can go and fix.
 *  - `withheld` is what this voucher deliberately does NOT carry — the tape
 *    whose unit question is still open, the scrap nobody has ruled on. Held
 *    back on purpose is not the same as broken, so these never block.
 *  - `notes` are the quiet lines: true, worth reading once, nothing to do.
 */
class VoucherPreviewService
{
    public function __construct(
        private readonly TallySyncService $sync,
        private readonly TallyGodownResolver $godowns,
        private readonly PackingVoucherLines $packing,
    ) {}

    /**
     * @return array{
     *     voucher: array<string, mixed>,
     *     lines: list<array{side: string, item: ?string, quantity: mixed, uom: ?string, godown: ?string, problems: list<string>}>,
     *     withheld: list<array<string, mixed>>,
     *     notes: list<string>,
     *     problems: list<string>,
     *     postable: bool,
     * }
     */
    public function forShiftProductionEntry(ShiftProductionEntry $entry): array
    {
        $payload = $this->sync->buildBatchVoucherPayload($entry);

        // Named once, checked per line: when the factory has no packing-material
        // store every packing line is wrong in the same way, and the accountant
        // needs to be told WHICH lines those are — not once at the top, where it
        // reads as somebody else's problem.
        //
        // Asked here rather than carried on the line: the consumed line's shape
        // is the accountant's frozen contract (item / quantity / godown, asserted
        // by NegativeStockOnCompletionTest), so the preview re-asks the same
        // question of the same class the payload builder asked.
        $packingStoreMissing = ($payload['packing_store'] ?? null) === null;

        $lines = [];
        foreach ($payload['consumed'] ?? [] as $line) {
            $lines[] = $this->resolveLine(
                'consumption',
                $line['item'] ?? null,
                $line['quantity'] ?? null,
                $line['godown'] ?? null,
                $packingStoreMissing,
            );
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
            'withheld' => array_values($payload['withheld'] ?? []),
            'notes' => $this->notes($entry, $payload),
            'problems' => $problems,
            'postable' => $problems === [] && $lineProblems === [],
        ];
    }

    /**
     * The quiet lines — true, and worth reading once before signing.
     *
     * Deliberately NOT blockers. A withheld scrap line is the owner's own
     * decision being honoured, and a local fixture is a product that was never
     * going to reach Tally; turning either into a red problem would train the
     * accountant to click past the red ones that matter.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function notes(ShiftProductionEntry $entry, array $payload): array
    {
        $notes = [];

        foreach ($payload['withheld'] ?? [] as $withheld) {
            if (($withheld['kind'] ?? null) === PackingVoucherLines::WITHHELD_SCRAP) {
                $notes[] = (string) $withheld['reason'];
            }
        }

        $tape = array_values(array_filter(
            $payload['withheld'] ?? [],
            fn ($withheld) => ($withheld['kind'] ?? null) === PackingVoucherLines::WITHHELD_TAPE,
        ));

        if ($tape !== []) {
            $notes[] = 'Tape is calculated on this batch but not posted — see the withheld tape line for the '
                .'metres and the reason.';
        }

        $entry->loadMissing('item');

        if ($entry->item?->isLocalFixture() === true) {
            $notes[] = 'This product exists only in this ERP (a "LOCAL-" fixture) and in no Tally company, so no '
                .'voucher is queued for this batch even after approval. The batch itself stays fully recorded.';
        }

        return $notes;
    }

    /**
     * Resolve one line's item and godown against the masters, reporting the
     * exact reason Tally would refuse it.
     *
     * Items are matched by NAME because that is what the voucher carries and
     * what Tally matches on — an item that exists in the ERP under a
     * different name than in Tally is precisely the failure being hunted.
     *
     * @param  bool  $packingStoreMissing  whether this factory has yet to name
     *                                     a packing-material store — checked
     *                                     against the line's own item, so only
     *                                     the cartons and the tape are flagged
     *                                     and the resin beside them is not.
     * @return array{side: string, item: ?string, quantity: mixed, uom: ?string, godown: ?string, problems: list<string>}
     */
    private function resolveLine(string $side, ?string $itemName, mixed $quantity, ?string $godownName, bool $packingStoreMissing = false): array
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

            if ($item->isLocalFixture()) {
                // The one case where "create it in Tally" is the wrong advice:
                // a LOCAL- fixture is a rehearsal product that was never meant
                // to reach the accountant's books at all.
                $problems[] = "\"{$itemName}\" is a local rehearsal product, not a real one — run the batch against "
                    .'the real product instead of creating this one in Tally.';
            }
        }

        if ($godownName === null) {
            $problems[] = 'Line has no godown.';
        } else {
            $godown = Warehouse::query()->where('name', $godownName)->first();
            if ($godown === null) {
                $problems[] = "No warehouse named \"{$godownName}\" exists.";
            } elseif ($this->godowns->resolve($godown) === null) {
                // Judged by the SAME resolver the payload builder used: a
                // warehouse without its own tally_guid is fine when it
                // aliases to a Tally-known godown (the internal day bin
                // posting under its parent / the sole company godown).
                // Only a genuinely unresolvable godown is flagged.
                $problems[] = "Godown \"{$godownName}\" does not exist in Tally.";
            }
        }

        if ($quantity === null || (float) $quantity <= 0) {
            $problems[] = 'Quantity is zero or missing.';
        }

        if ($packingStoreMissing && $item !== null && $this->packing->kindFor($item->id) !== null) {
            // The owner's split: "packing materials from the Packing Material
            // Store". With no store named this line would come out of whichever
            // warehouse the material was issued from — usually the resin store —
            // and the accountant would be reconciling cartons against it.
            $problems[] = "\"{$itemName}\" is packing material, and packing material has to be issued from the "
                .'Packing Material Store — this factory has not named one yet, so this line would come out of "'
                .($godownName ?? '?').'". Name the packing-material store in Production settings.';
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
