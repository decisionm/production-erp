<?php

namespace App\Modules\TallySync\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TallySyncEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'syncable_type' => class_basename($this->syncable_type),
            'syncable_id' => $this->syncable_id,
            'tally_voucher_type' => $this->tally_voucher_type,
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
     * The exact place a known Tally refusal is fixed — derived from the
     * error text Tally actually returned, never a guess at a mapping. An
     * unrecognised error carries no fix block rather than a wrong one.
     *
     * @return array{sentence: string, path: string}|null
     */
    private function fixSuggestion(): ?array
    {
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
