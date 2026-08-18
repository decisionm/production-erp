<?php

namespace App\Modules\TallySync\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of an entry's history, shaped for the wire. Deliberately carries
 * nothing of the entry itself (no payload, no status): the entry resource
 * already says what the voucher IS; this says what happened to it, when,
 * and who did it.
 */
class TallySyncEventResource extends JsonResource
{
    /**
     * Tally's rejection text — `details.error_message` / `previous_error` on
     * the failed / refused / retried / dismissed rows — arrives verbatim from
     * the agent and can NAME the supplier ("Ledger does not exist :
     * <vendor>"). The events table is readable by anyone with
     * tally-sync.view, so on a supplier-party voucher a reader without
     * standing gets those two keys OMITTED (FC-06, second half); the row
     * still says the voucher failed, when, and who reported it. The ENTRY
     * resource decides — it knows the category and the reader — and passes
     * the verdict in; this resource never judges permissions itself.
     */
    private const SUPPLIER_BEARING_DETAIL_KEYS = ['error_message', 'previous_error'];

    public function __construct($resource, private readonly bool $withholdsSupplier = false)
    {
        parent::__construct($resource);
    }

    /** @return AnonymousResourceCollection */
    public static function collectionWithholding(mixed $events, bool $withholdsSupplier)
    {
        return static::collection(collect($events)->map(fn ($event) => new static($event, $withholdsSupplier)));
    }

    public function toArray(Request $request): array
    {
        $details = is_array($this->details) ? $this->details : $this->details;
        if ($this->withholdsSupplier && is_array($details)) {
            $details = array_diff_key($details, array_flip(self::SUPPLIER_BEARING_DETAIL_KEYS));
        }

        return [
            'id' => $this->id,
            'event' => $this->event,
            'direction' => $this->direction,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'actor' => [
                'type' => $this->actor_type,
                'id' => $this->actor_id,
                'label' => $this->actor_label,
            ],
            'details' => $details,
            // A reconstruction from the entry's timestamps (the backfill
            // migration), never an observation — said out loud so nobody
            // reads a guess as a record.
            'backfilled' => $this->isBackfilled(),
        ];
    }
}
