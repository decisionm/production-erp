<?php

namespace App\Modules\TallySync\Http\Resources;

use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Services\ShiftVoucherReleaseGate;
use App\Modules\TallySync\Services\TransactionClassifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TallySyncEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Derived on read from the columns and payload already on the row —
        // no query, no Tally (TALLY-SYNC-CHAIN.md §3 "Classification").
        $classifier = app(TransactionClassifier::class);

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
            'payload' => $this->payload,
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
        ];
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
