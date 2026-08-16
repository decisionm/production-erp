<?php

namespace App\Modules\TallySync\Http\Resources;

use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Services\AgentIdentity;
use App\Modules\TallySync\Services\EntryMappingSurface;
use App\Modules\TallySync\Services\EntryPresenter;
use App\Modules\TallySync\Services\ShiftVoucherReleaseGate;
use App\Modules\TallySync\Services\TransactionClassifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FC-06 ON THIS RESOURCE — BOTH HALVES. "Purchase rates and supplier details
 * are Owner/Accounts only. Floor and sales logins never see what a material
 * cost or who supplied it." ONE predicate decides who may
 * (AgentIdentity::mayReadPurchaseDetails: finance.view / finance.manage, or
 * the sync agent by its real token), judged ONCE per row and applied to
 * every surface this resource emits, so no reader can be told two things:
 *
 *   RATES (every category — the keys are the same on the same resource)
 *     payload.lines[].rate / amount / debit / credit, payload.total_amount
 *       → OMITTED for a reader who may not (LINE_RATE_KEYS / TOTAL_RATE_KEYS)
 *     summary / timeline / flags / mappings
 *       → never carry money for ANY reader (EntryPresenter, EntryMappingSurface)
 *
 *   SUPPLIER IDENTITY (categories whose partyIsSupplier() — Receipt Note today)
 *     root `party`                → null for a reader who may not (list and show alike)
 *     payload.party_ledger / party_gstin
 *                                 → OMITTED for a reader who may not (SUPPLIER_IDENTITY_KEYS)
 *     summary.headline            → the party segment left out (EntryPresenter::summary)
 *     mappings.party              → {name: null, state: 'withheld', note} (EntryMappingSurface)
 *     A CUSTOMER on a Sales invoice / Delivery Note is not FC-06 and always reads.
 *
 * The AGENT is inside the predicate on purpose: it must receive the whole
 * payload to build the voucher Tally is sent (receiptNote.ts writes
 * PARTYLEDGERNAME, RATE, AMOUNT from it), so /pending hands it every key,
 * byte-identical to what was stored (SyncPayloadRateVisibilityTest,
 * SupplierIdentityVisibilityTest).
 */
class TallySyncEntryResource extends JsonResource
{
    /**
     * The payload keys that carry a price. On a Receipt Note they ARE the
     * GRN's purchase rate per line and the bill total — FC-06, Owner/Accounts
     * only, the very numbers GoodsReceiptNoteLineResource gates on
     * finance.view/finance.manage. On a Sales invoice the same keys are
     * selling prices, not FC-06 — but they are the SAME keys on the SAME
     * resource, and one resource whose `rate` is secret on one row and open
     * on the next is a gate nobody can reason about, so they are gated
     * alike. A Journal's `debit` / `credit` are money on the same resource
     * too and ride the same rule for the same reason — one resource, one
     * rule; the summary reads their SIDE and never their figure. Production
     * payloads (produced[] / consumed[]) carry quantities only and are
     * untouched.
     */
    private const LINE_RATE_KEYS = ['rate', 'amount', 'debit', 'credit'];

    private const TOTAL_RATE_KEYS = ['total_amount'];

    /**
     * The payload keys that name the supplier on a supplier-party voucher
     * (TallyTransactionCategory::partyIsSupplier) — the vendor's name and
     * GSTIN, as enqueueGoodsReceiptNote() writes them. FC-06's second half;
     * OMITTED, like the rates, for a reader who may not. Left in place on
     * every other category (a customer is not FC-06).
     */
    private const SUPPLIER_IDENTITY_KEYS = ['party_ledger', 'party_gstin'];

    public function toArray(Request $request): array
    {
        // Derived on read from the columns and payload already on the row —
        // no query, no Tally (TALLY-SYNC-CHAIN.md §3 "Classification").
        $classifier = app(TransactionClassifier::class);
        $category = $classifier->classify($this->resource);

        // THE ONE FC-06 VERDICT for this row and this reader (class docblock)
        // — finance, or the sync agent by its real token (AgentIdentity, never
        // tokenCan() alone: a staff session's transient token answers can()
        // TRUE for everything, and Phase 2's filters would otherwise make
        // this list a purchase-rate archive searchable by vendor and date
        // for anyone with tally-sync.view). Everyone else gets the payload
        // with the rate keys — and on a supplier-party voucher the supplier
        // keys — OMITTED, not nulled: a null rate would read as "cost
        // nothing", a null party as "no party".
        $mayReadPurchaseDetails = AgentIdentity::mayReadPurchaseDetails($request->user());
        $withholdsSupplier = $category->partyIsSupplier() && ! $mayReadPurchaseDetails;

        return [
            'id' => $this->id,
            'syncable_type' => class_basename($this->syncable_type),
            'syncable_id' => $this->syncable_id,
            'tally_voucher_type' => $this->tally_voucher_type,
            // What this entry IS, as the Control Center groups and filters
            // it. `tally_voucher_type` above stays the raw label the agent
            // dispatches on; `category` says what that label means, including
            // (erp_label_differs_from_wire) where the ERP's label is not the
            // voucher type Tally receives. Same shape as
            // TallyTransactionCategory::catalogue() rows.
            'category' => $category->describe(),
            // The voucher's own facts, lifted out of the payload so a list
            // can show and filter them without every client re-parsing the
            // raw payload: business_date is voucher_date, document_number is
            // voucher_number, party is the customer/vendor (null for
            // production and journal — and null for the VENDOR on a
            // supplier-party voucher when this reader may not see who
            // supplied it, FC-06), item_summary is {first, count} over the
            // distinct item names the voucher moves.
            'business_date' => $classifier->businessDate($this->resource),
            'document_number' => $classifier->documentNumber($this->resource),
            'party' => $withholdsSupplier ? null : $classifier->party($this->resource),
            'item_summary' => $classifier->itemSummary($this->resource),
            'payload' => $mayReadPurchaseDetails
                ? $this->payload
                : $this->payloadWithoutPurchaseDetails($this->payload, $withholdsSupplier),
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            // Tally's rejection text arrives verbatim from the agent, and for
            // an unresolvable ledger Tally routinely NAMES it — "Ledger does
            // not exist : <vendor>". On a supplier-party voucher that is the
            // supplier's identity by another road (FC-06, second half), so
            // for a reader without standing the text is withheld and a
            // sibling note says why — never a bare null, which would read as
            // "no error". `fix` stays: it is derived from the SHAPE of the
            // error, never echoes it.
            'error_message' => $withholdsSupplier ? null : $this->error_message,
            ...($withholdsSupplier && $this->error_message !== null ? [
                'error_withheld' => 'Tally\'s rejection text is withheld on this voucher: it can name the supplier, and supplier identity is Owner/Accounts only (FC-06).',
            ] : []),
            'synced_at' => $this->synced_at?->toIso8601String(),
            // When this voucher was first handed to the sync agent. The
            // agent's own double-post guard reads it: an entry that arrives
            // already stamped is one it has been given before, so it acks or
            // refuses rather than rebuilding the voucher. See
            // TallySyncService::pending() for why the stamp lands after the
            // rows are read.
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            // Why a pending shift voucher is not with the agent yet — null
            // once it is deliverable (or for batch vouchers, which are
            // never held). Drives the "collecting / quiet period" copy and
            // the Release now button on the Tally Sync page.
            'hold' => $this->holdState(),
            'created_at' => $this->created_at?->toIso8601String(),
            // The story of a failed voucher's repair: each retry after a
            // failure records the previous error and that the payload was
            // regenerated from current mappings, so "it failed, the mapping
            // was fixed, it went through" stays readable afterwards.
            'resolution_log' => $this->resolutionLog($withholdsSupplier),
            'fix' => $this->fixSuggestion(),
            // The entry as a person would say it (EntryPresenter; MASTER-PLAN
            // P3-02/P3-03): `summary` {headline, lines} per category and
            // `timeline` (events + the entry's own timestamps, de-duplicated,
            // reconstructions flagged) ride the SAME gate as `history` — the
            // show endpoint is the only caller that loads `events` — so the
            // list and every action response stay exactly as light as they
            // were. `flags` (unvalidated builder, order reference not emitted,
            // label differs from wire, held) is on EVERY response: it costs no
            // query and the list's Sales rows need the unvalidated banner.
            // None of the three carries a rate, an amount or a total for ANY
            // reader (FC-06) — quantities and counts only; the presenter's
            // docblock is the rule, EntryPresenterTest the proof. The summary
            // takes this row's FC-06 verdict for the supplier segment.
            'summary' => $this->when($this->relationLoaded('events'), fn () => app(EntryPresenter::class)->summary($this->resource, $mayReadPurchaseDetails)),
            'timeline' => $this->when($this->relationLoaded('events'), fn () => app(EntryPresenter::class)->timeline($this->resource, $mayReadPurchaseDetails)),
            // Cast to an object so the wire shape is stable: an empty PHP
            // array serialises as `[]` and a non-empty one as `{}`, and a
            // client typed to `Record<string, …>` should never meet a list.
            'flags' => (object) app(EntryPresenter::class)->flags($this->resource),
            // The append-only history (tally_sync_events), oldest first —
            // ONLY when the caller loaded it, which is the show endpoint and
            // nothing else. The list stays as light as it was: a page of 200
            // vouchers must not drag 200 timelines with it. Every other
            // response of this resource (retry, dismiss, release, pending)
            // keeps its prior shape PLUS `flags` (Phase 3) — the agent's
            // /pending included; it does no strict parse of the row and
            // reads only what it always read (TALLY-SYNC-CHAIN.md §3).
            'history' => $this->when(
                $this->relationLoaded('events'),
                fn () => TallySyncEventResource::collectionWithholding($this->events, $withholdsSupplier),
            ),
            // `mappings` + `mapping_summary` — for every NAME this voucher
            // hands Tally (each line's item and godown, the ledgers, the
            // party, the Sales ledger), whether the ERP resolved it by
            // identity, by name only, or not at all (EntryMappingSurface /
            // LineMappingResolver; MASTER-PLAN P3-04). Derived at read time
            // against the masters as they stand — no stored verdict. Rides
            // the SAME gate as `history` (the show endpoint is the only
            // caller that loads `events`), so the list and every action
            // response stay exactly as they were, and one computation feeds
            // both keys. Carries names, ids, GUIDs, states, counts and notes
            // only — never a rate — and takes this row's FC-06 verdict for
            // the party row (withheld for a reader who may not see the vendor).
            $this->mergeWhen($this->relationLoaded('events'), fn () => app(EntryMappingSurface::class)->for($this->resource, $mayReadPurchaseDetails)),
        ];
    }

    /**
     * The payload as a reader who may not read purchase details sees it:
     * every rate-carrying key removed — `rate`, `amount`, `debit`, `credit`
     * off each of lines[], `total_amount` off the top — and, on a
     * supplier-party voucher, the supplier's `party_ledger` and
     * `party_gstin` too; nothing else touched. Keys are unset, never set to
     * null (see the class note on LINE_RATE_KEYS). Anything that is not
     * the shape the builders write (a payload with no lines, a line that is
     * not an array) passes through as it is.
     *
     * @param  array<string, mixed>|null  $payload
     * @param  bool  $withholdsSupplier  the row's category names a supplier as
     *                                   its party AND this reader may not see it
     * @return array<string, mixed>|null
     */
    private function payloadWithoutPurchaseDetails(?array $payload, bool $withholdsSupplier): ?array
    {
        if ($payload === null) {
            return null;
        }

        foreach (self::TOTAL_RATE_KEYS as $key) {
            unset($payload[$key]);
        }

        if ($withholdsSupplier) {
            foreach (self::SUPPLIER_IDENTITY_KEYS as $key) {
                unset($payload[$key]);
            }
            // The repair story rides INSIDE the payload too (retry() writes
            // resolution_log there), and each entry's previous_error is
            // Tally's own words — the same second road to the supplier
            // that the root `resolution_log` and `error_message` are
            // withheld on. Strip it here so the payload copy cannot say
            // what the gated keys will not.
            if (is_array($payload['resolution_log'] ?? null)) {
                $payload['resolution_log'] = array_values(array_map(
                    fn ($repair) => is_array($repair) ? array_diff_key($repair, ['previous_error' => true]) : $repair,
                    $payload['resolution_log'],
                ));
            }
        }

        if (is_array($payload['lines'] ?? null)) {
            $payload['lines'] = array_map(
                fn ($line) => is_array($line) ? array_diff_key($line, array_flip(self::LINE_RATE_KEYS)) : $line,
                $payload['lines'],
            );
        }

        return $payload;
    }

    /**
     * The repair story off the payload — with each entry's `previous_error`
     * (Tally's own words, which can name the supplier) removed for a reader
     * who may not read supplier identity on this voucher. The rest of each
     * entry (when, by whom, the note) stays: that a retry happened is not
     * FC-06; what Tally said about the vendor is.
     *
     * @return list<array<string, mixed>>
     */
    private function resolutionLog(bool $withholdsSupplier): array
    {
        $log = $this->payload['resolution_log'] ?? [];
        if (! is_array($log)) {
            return [];
        }
        if (! $withholdsSupplier) {
            return array_values($log);
        }

        return array_values(array_map(
            fn ($entry) => is_array($entry) ? array_diff_key($entry, ['previous_error' => true]) : $entry,
            $log,
        ));
    }

    /**
     * The release gate's verdict, shaped for the wire.
     *
     * @return array{phase: string, shift_ends_at: ?string, last_merged_at: string, releasable_at: string}|null
     */
    private function holdState(): ?array
    {
        $hold = app(ShiftVoucherReleaseGate::class)->hold($this->resource);
        if ($hold === null) {
            return null;
        }

        return [
            'phase' => $hold['phase'],
            'shift_ends_at' => $hold['shift_ends_at']?->toIso8601String(),
            'last_merged_at' => $hold['last_merged_at']->toIso8601String(),
            'releasable_at' => $hold['releasable_at']->toIso8601String(),
        ];
    }

    /**
     * The exact place a known Tally refusal is fixed — derived from the
     * error text Tally actually returned, never a guess at a mapping. An
     * unrecognised error carries no fix block rather than a wrong one.
     *
     * @return array{sentence: string, path: string}|null
     */
    private function fixSuggestion(): ?array
    {
        // A dismissed voucher keeps its error as history, but "go fix the
        // mapping, then retry" is advice for a voucher that should still
        // post — on one written off for good it would send someone to
        // repair a thing nobody wants repaired.
        if ($this->status === TallySyncStatus::Dismissed) {
            return null;
        }

        $error = $this->error_message;
        if ($error === null) {
            return null;
        }

        return match (true) {
            str_contains($error, 'does not exist') && str_contains($error, 'Stock Item') => [
                'sentence' => 'Tally does not know this product by this name. Attach the product to its exact Tally stock item on Product Standards, then Regenerate & retry.',
                'path' => '/production/standards?view=incomplete&missing_tally=1',
            ],
            str_contains($error, 'Godown') => [
                'sentence' => 'A store on this voucher does not exist in Tally. Name the factory stores in Machine Setup → Factory Settings, then Regenerate & retry.',
                'path' => '/production/configuration?tab=settings',
            ],
            str_contains($error, 'Unit') => [
                'sentence' => 'A unit on this voucher does not match Tally. The unit question (tape metres) is an open owner decision — the line stays withheld until it is answered.',
                'path' => '/production/standards',
            ],
            str_contains($error, 'No Entries in Voucher') => [
                'sentence' => 'The voucher was generated with no lines — its batch had nothing postable. Regenerate & retry after the batch carries real figures.',
                'path' => '/production/approve-production',
            ],
            default => null,
        };
    }
}
