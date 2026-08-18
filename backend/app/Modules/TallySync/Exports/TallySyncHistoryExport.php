<?php

namespace App\Modules\TallySync\Exports;

use App\Modules\Core\Exports\AbstractExportKind;
use App\Modules\TallySync\Http\Requests\ListTallySyncEntriesRequest;
use App\Modules\TallySync\Http\Resources\TallySyncEventResource;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\AgentIdentity;
use App\Modules\TallySync\Services\TallySyncQueryService;
use App\Modules\TallySync\Services\TransactionClassifier;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The sync queue's HISTORY as a file: one row per event (tally_sync_events)
 * of every entry GET /tally-sync/entries would list for the SAME filters
 * (ListTallySyncEntriesRequest's rules, delegated), entries in the list's
 * order, each entry's events oldest first — the append-only story of what
 * happened to every voucher on the page, when, and who did it.
 *
 * Every event row is built THROUGH TallySyncEventResource, exactly as the
 * show endpoint's `history` builds it, with the SAME FC-06 verdict the
 * entry resource passes in (a supplier-party voucher for a reader who may
 * not read purchase details — AgentIdentity::mayReadPurchaseDetails), so
 * the withholding is inherited, never re-judged here.
 *
 * DETAILS CARRY NO TEXT KEYS — for any reader. Tally's rejection text
 * (`error_message` on a failed / refused row, `previous_error` on a retry
 * or dismissal) arrives verbatim from the agent and can name the supplier;
 * the resource already omits both on a supplier-party voucher for a reader
 * without standing, and this file goes one step further and never writes
 * either into the `details` cell: this is the EVENT log (kind · when ·
 * actor · the structured facts), and Tally's words live in the entries
 * export's `error_message` column, gated per reader there. What remains of
 * details is written as JSON — the structured facts each event recorded
 * (attempt, voucher_number, payload_regenerated, joined_entry_ids, …).
 */
class TallySyncHistoryExport extends AbstractExportKind
{
    /** Tally's free text — never in this file's details cell (class docblock). */
    private const TEXT_DETAIL_KEYS = ['error_message', 'previous_error'];

    public function __construct(
        private readonly TallySyncQueryService $queries,
        private readonly TransactionClassifier $classifier,
    ) {}

    public function key(): string
    {
        return 'tally_sync_history';
    }

    public function label(): string
    {
        return 'Tally sync history';
    }

    public function module(): string
    {
        return 'tally-sync';
    }

    public function permissionAny(): array
    {
        return ['tally-sync.view', 'tally-sync.manage'];
    }

    /** The list endpoint's own grammar, not a copy of it. */
    public function filterRules(): array
    {
        return (new ListTallySyncEntriesRequest)->rules();
    }

    public function columns(?Authenticatable $reader): array
    {
        return [
            'entry_id' => 'entry_id',
            'voucher_type' => 'voucher_type',
            'voucher_number' => 'voucher_number',
            'event_id' => 'id',
            'event' => 'event',
            'direction' => 'direction',
            'occurred_at' => 'occurred_at',
            'actor_type' => 'actor.type',
            'actor_id' => 'actor.id',
            'actor' => 'actor.label',
            'details' => 'details',
            'backfilled' => 'backfilled',
        ];
    }

    /**
     * Entries in the list's order; within each, its events oldest first as
     * TallySyncEventResource emits them for this reader (resolve — the same
     * array the show endpoint's `history` carries), each prefixed with the
     * entry's identity: id, the raw Tally voucher type, and the voucher
     * number the classifier lifts from the payload (the list's
     * document_number).
     */
    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        $request = $this->requestFor($reader);
        $mayReadPurchaseDetails = AgentIdentity::mayReadPurchaseDetails($reader);

        foreach ($this->queries->historyCursor($filters, $reader) as $entry) {
            /** @var TallySyncEntry $entry */
            $withholdsSupplier = $this->classifier->classify($entry)->partyIsSupplier() && ! $mayReadPurchaseDetails;
            $identity = [
                'entry_id' => $entry->id,
                'voucher_type' => $entry->tally_voucher_type,
                'voucher_number' => $this->classifier->documentNumber($entry),
            ];

            $events = TallySyncEventResource::collectionWithholding($entry->events, $withholdsSupplier)->resolve($request);

            foreach ($events as $event) {
                $event['details'] = $this->detailsCell($event['details'] ?? null);

                yield $identity + $event;
            }
        }
    }

    public function count(array $filters, ?Authenticatable $reader): int
    {
        return $this->queries->historyCount($filters, $reader);
    }

    /**
     * The details as the cell carries them: the text keys off (class
     * docblock), an empty remainder as an empty cell rather than "[]" —
     * the streamer writes any other array as JSON.
     *
     * @return array<string, mixed>|null
     */
    private function detailsCell(mixed $details): ?array
    {
        if (! is_array($details)) {
            return null;
        }

        $kept = array_diff_key($details, array_flip(self::TEXT_DETAIL_KEYS));

        return $kept === [] ? null : $kept;
    }
}
