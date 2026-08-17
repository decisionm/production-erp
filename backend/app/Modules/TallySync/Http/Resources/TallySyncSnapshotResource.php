<?php

namespace App\Modules\TallySync\Http\Resources;

use App\Modules\TallySync\Models\Enums\TallyTransactionCategory;
use App\Modules\TallySync\Models\TallySyncSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One post-Tally snapshot — what the agent sent and what Tally answered —
 * shaped for the wire (Phase 4). Carries nothing of the entry itself; the
 * entry resource says what the voucher IS, this says what was posted for
 * it and how Tally replied.
 *
 * FC-06 ON THE XML — THE RULE, ONCE (P4-02). Which XML carries what:
 * Receipt Note → supplier party + RATE/AMOUNT (both halves of FC-06);
 * Sales → customer party + selling RATE/AMOUNT (rates on the same
 * resource, gated alike — the entry resource's LINE_RATE_KEYS reasoning);
 * Delivery Note → customer party, quantities; Journal → ledger names +
 * DEBIT/CREDIT amounts; Stock Journal (both production categories, whose
 * wire voucher type is Stock Journal) → items, godowns, quantities, batch —
 * NO rate, NO party, by construction. So the XML body is SHOWN to a reader
 * for whom AgentIdentity::mayReadPurchaseDetails() is true (finance, or the
 * agent by its real token), and to EVERY tally-sync.view reader for a
 * Stock Journal; for every other voucher type it is WITHHELD for a reader
 * without standing — the whole document, never a partial redaction of XML
 * text (an amount can hide in a narration; fail closed), with `xml_withheld`
 * saying why. Nobody is told "no XML" when there was one: `xml` is null
 * with NO note only when the agent uploaded no body.
 *
 * Tally's MESSAGE (its own text — "Ledger does not exist : <vendor>") and
 * the raw response follow the SAME rule as the entry's error_message today:
 * withheld iff the voucher's party is a supplier and the reader may not
 * (withholdsSupplier), with `message_withheld` saying so.
 *
 * What EVERY tally-sync.view reader always gets, whatever the gate says:
 * sha256, byte size, agent version, attempt, when, whether the payload the
 * agent built from is the payload the cloud holds now, and Tally's summary
 * {success, created, errors}.
 *
 * The verdicts are computed in ONE place — forCategory() — from the
 * category and the reader's FC-06 predicate; the entry resource (show) and
 * the store endpoint (the agent reading back its own upload) both go
 * through it, so no reader can be told two things. The instance itself
 * never judges permissions.
 */
class TallySyncSnapshotResource extends JsonResource
{
    public const XML_WITHHELD_NOTE = 'withheld — this voucher\'s XML carries rates or a party; readers with finance standing see it (FC-06)';

    public const MESSAGE_WITHHELD_NOTE = 'Tally\'s response text is withheld on this voucher: it can name the supplier, and supplier identity is Owner/Accounts only (FC-06).';

    /**
     * The categories whose XML is rate-free and party-free by construction
     * — the two production vouchers, both a Stock Journal on the wire
     * (manufacturingJournal.ts / stockJournal.ts emit items, godowns,
     * quantities and the batch; Tally derives value). Anything else,
     * Unknown included, is gated: fail closed.
     */
    private const RATE_FREE_XML_CATEGORIES = [
        TallyTransactionCategory::ProductionStockJournalShift,
        TallyTransactionCategory::ProductionStockJournalBatch,
    ];

    public function __construct(
        $resource,
        private readonly bool $showsXml = false,
        private readonly bool $withholdsSupplier = true,
    ) {
        parent::__construct($resource);
    }

    /**
     * THE verdicts for one voucher and one reader: may this reader see the
     * XML body, and must Tally's text be withheld from them?
     *
     * @return array{0: bool, 1: bool} [showsXml, withholdsSupplier]
     */
    public static function verdicts(TallyTransactionCategory $category, bool $mayReadPurchaseDetails): array
    {
        $showsXml = $mayReadPurchaseDetails || in_array($category, self::RATE_FREE_XML_CATEGORIES, true);
        $withholdsSupplier = $category->partyIsSupplier() && ! $mayReadPurchaseDetails;

        return [$showsXml, $withholdsSupplier];
    }

    /** One snapshot for a reader with these verdicts. */
    public static function forCategory(TallySyncSnapshot $snapshot, TallyTransactionCategory $category, bool $mayReadPurchaseDetails): static
    {
        [$showsXml, $withholdsSupplier] = self::verdicts($category, $mayReadPurchaseDetails);

        return new static($snapshot, $showsXml, $withholdsSupplier);
    }

    /**
     * An entry's snapshots (newest first, as the service hands them) for a
     * reader with these verdicts.
     *
     * @return AnonymousResourceCollection
     */
    public static function collectionForCategory(mixed $snapshots, TallyTransactionCategory $category, bool $mayReadPurchaseDetails)
    {
        [$showsXml, $withholdsSupplier] = self::verdicts($category, $mayReadPurchaseDetails);

        return static::collection(collect($snapshots)->map(fn ($snapshot) => new static($snapshot, $showsXml, $withholdsSupplier)));
    }

    public function toArray(Request $request): array
    {
        /** @var TallySyncSnapshot $snapshot */
        $snapshot = $this->resource;
        $hasBody = $snapshot->xml !== null;

        return [
            'id' => $snapshot->id,
            'attempt' => $snapshot->attempt,
            'created_at' => $snapshot->created_at?->toIso8601String(),
            'agent_version' => $snapshot->agent_version,
            'xml_sha256' => $snapshot->xml_sha256,
            'xml_bytes' => $snapshot->xml_bytes,
            'payload_matches' => $snapshot->payload_matches,
            'tally' => $this->tally($snapshot),
            // The body, or the note — never a bare null for a body that
            // exists (class docblock).
            'xml' => $hasBody && $this->showsXml ? $snapshot->xml : null,
            'xml_withheld' => $hasBody && ! $this->showsXml ? self::XML_WITHHELD_NOTE : null,
        ];
    }

    /**
     * Tally's answer: null when nothing came back (the inconclusive-timeout
     * path — XML sent, no answer); else the summary every reader gets plus
     * the text for a reader who may. `raw` rides with `message`: it is the
     * same words, unparsed.
     *
     * @return array<string, mixed>|null
     */
    private function tally(TallySyncSnapshot $snapshot): ?array
    {
        if (! $snapshot->tallyAnswered()) {
            return null;
        }

        $hasText = $snapshot->tally_message !== null || $snapshot->tally_raw !== null;
        $withheld = $this->withholdsSupplier && $hasText;

        return [
            'success' => $snapshot->tally_success,
            'created' => $snapshot->tally_created,
            'errors' => $snapshot->tally_errors,
            'message' => $withheld ? null : $snapshot->tally_message,
            ...($withheld ? ['message_withheld' => self::MESSAGE_WITHHELD_NOTE] : []),
            'raw' => $withheld ? null : $snapshot->tally_raw,
        ];
    }
}
