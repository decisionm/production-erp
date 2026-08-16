<?php

namespace App\Modules\TallySync\Services;

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
        // THE resolver of names → mapping state, shared with the Control
        // Center's show endpoint (EntryMappingSurface): the preview's
        // blockers below are derived from ITS verdicts, so what the preview
        // refuses before approval and what the sync page later says about
        // the same name can never disagree. It judges godowns through the
        // same TallyGodownResolver the payload builder used.
        private readonly LineMappingResolver $mappings,
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

        // The state, from the one shared resolver; the sentences below are
        // this preview's own and unchanged — each names a thing a person can
        // go and fix. The single matched row (null unless exactly one) is
        // what the line's uom and packing kind are read from.
        $itemState = $this->mappings->item($itemName);
        $item = $this->mappings->itemRow($itemName);

        switch ($itemState['state']) {
            case LineMappingResolver::STATE_NONE:
                $problems[] = 'Line has no stock item.';
                break;
            case LineMappingResolver::STATE_UNMAPPED:
                $problems[] = "No item named \"{$itemName}\" exists.";
                break;
            case LineMappingResolver::STATE_NAME_ONLY:
                $problems[] = "\"{$itemName}\" has no Tally identity — Tally will answer \"Stock Item does not exist\".";
                break;
            case LineMappingResolver::STATE_FIXTURE:
                // The one case where "create it in Tally" is the wrong advice:
                // a LOCAL- fixture is a rehearsal product that was never meant
                // to reach the accountant's books at all. Said with the
                // no-identity sentence when it has none (the usual fixture),
                // and on its own for the contradictory fixture that carries a
                // GUID — the posting paths refuse either.
                if ($itemState['tally_stock_item_guid'] === null) {
                    $problems[] = "\"{$itemName}\" has no Tally identity — Tally will answer \"Stock Item does not exist\".";
                }
                $problems[] = "\"{$itemName}\" is a local rehearsal product, not a real one — run the batch against "
                    .'the real product instead of creating this one in Tally.';
                break;
            case LineMappingResolver::STATE_AMBIGUOUS:
                // Several ERP items share this name; Tally would still match
                // ONE by name, so it is not a blocker here — the show
                // endpoint's mapping state says "ambiguous" so it is seen.
                // Turning it into a refusal would be a new approval gate on
                // live data, which is the owner's call, not this preview's.
                break;
        }

        // Godown, judged by the SAME resolver the payload builder used: a
        // warehouse without its own tally_guid is fine when it aliases to a
        // Tally-known godown (the internal day bin posting under its parent
        // / the sole company godown) — identity. Only a genuinely
        // unresolvable godown (name_only) or an unknown name is flagged.
        $godownState = $this->mappings->godown($godownName);

        switch ($godownState['state']) {
            case LineMappingResolver::STATE_NONE:
                $problems[] = 'Line has no godown.';
                break;
            case LineMappingResolver::STATE_UNMAPPED:
                $problems[] = "No warehouse named \"{$godownName}\" exists.";
                break;
            case LineMappingResolver::STATE_NAME_ONLY:
                $problems[] = "Godown \"{$godownName}\" does not exist in Tally.";
                break;
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
