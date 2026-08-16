<?php

namespace App\Modules\TallySync\Http\Resources;

use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Services\AgentIdentity;
use App\Modules\TallySync\Services\ShiftVoucherReleaseGate;
use App\Modules\TallySync\Services\TransactionClassifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
     * alike. Production payloads (produced[] / consumed[]) carry quantities
     * only and are untouched.
     */
    private const LINE_RATE_KEYS = ['rate', 'amount'];

    private const TOTAL_RATE_KEYS = ['total_amount'];

    public function toArray(Request $request): array
    {
        // Derived on read from the columns and payload already on the row —
        // no query, no Tally (TALLY-SYNC-CHAIN.md §3 "Classification").
        $classifier = app(TransactionClassifier::class);

        // Who may read the payload's rates: finance (the MaterialLotResource
        // gate — the permission the Owner and Accounts hold and the store
        // does not) or the sync AGENT, which has to receive the whole payload
        // to build the voucher Tally is sent (receiptNote.ts reads line.rate
        // and total_amount). The agent is recognised by its real token and
        // its abilities (AgentIdentity), never by tokenCan() alone: a staff
        // session's transient token answers can() TRUE for everything, and
        // Phase 2's filters would otherwise make this list a purchase-rate
        // archive searchable by vendor and date for anyone with
        // tally-sync.view. Everyone else gets the payload with the rate
        // keys OMITTED, not nulled — a null rate would read as "cost nothing".
        $showsRates = ($request->user()?->hasAnyPermission(['finance.view', 'finance.manage']) ?? false)
            || AgentIdentity::isAgent($request->user());

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
            'category' => $classifier->classify($this->resource)->describe(),
            // The voucher's own facts, lifted out of the payload so a list
            // can show and filter them without every client re-parsing the
            // raw payload: business_date is voucher_date, document_number is
            // voucher_number, party is the customer/vendor (null for
            // production and journal), item_summary is {first, count} over
            // the distinct item names the voucher moves.
            'business_date' => $classifier->businessDate($this->resource),
            'document_number' => $classifier->documentNumber($this->resource),
            'party' => $classifier->party($this->resource),
            'item_summary' => $classifier->itemSummary($this->resource),
            'payload' => $showsRates ? $this->payload : $this->payloadWithoutRates($this->payload),
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'error_message' => $this->error_message,
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
            'resolution_log' => $this->payload['resolution_log'] ?? [],
            'fix' => $this->fixSuggestion(),
            // The append-only history (tally_sync_events), oldest first —
            // ONLY when the caller loaded it, which is the show endpoint and
            // nothing else. The list stays as light as it was: a page of 200
            // vouchers must not drag 200 timelines with it, and every other
            // response of this resource (retry, dismiss, release, pending)
            // keeps its exact prior shape.
            'history' => TallySyncEventResource::collection($this->whenLoaded('events')),
        ];
    }

    /**
     * The payload as a reader without finance sees it: every rate-carrying
     * key removed — `rate` and `amount` off each of lines[], `total_amount`
     * off the top — and nothing else touched. Keys are unset, never set to
     * null (see the class note on LINE_RATE_KEYS). Anything that is not
     * the shape the builders write (a payload with no lines, a line that is
     * not an array) passes through as it is.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function payloadWithoutRates(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        foreach (self::TOTAL_RATE_KEYS as $key) {
            unset($payload[$key]);
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
