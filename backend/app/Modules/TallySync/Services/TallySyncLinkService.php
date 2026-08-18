<?php

namespace App\Modules\TallySync\Services;

use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * THE ONE DOOR ANOTHER MODULE OPENS INTO THE QUEUE (Phase 3.5). Sales asks
 * "which entry stands for this delivery / invoice, and how is it doing"
 * and is answered with a TallyLink — status, flags and a deep link into the
 * Tally Sync page — never the payload. So a sales page can say "Delivery
 * Note DN-12: pending, unvalidated builder" beside its own document without
 * ever reading tally_sync_entries itself (CLAUDE.md: cross-module reads go
 * through the other module's Service class).
 *
 * WHAT A LINK IS, AND IS NOT. entry_id · voucher_type · status ·
 * voucher_number · synced_at · flags (EntryPresenter::flags, verbatim — the
 * unvalidated_builder warning for Delivery Note and Sales rides it) ·
 * link ("/tally-sync?entry={id}"). No payload, no rate, no party, no godown,
 * no error text: those belong to TallySyncEntryResource and its FC-06 gate,
 * and a link that carried them would be that gate's back door. A reader
 * who wants the voucher follows the link and meets the gate there.
 *
 * WHICH ENTRY, when a syncable has several (history from before enqueue()
 * became idempotent, or a dismissed voucher re-issued): the LIVE one —
 * pending / synced / failed over dismissed — newest first among equals. A
 * lone dismissed entry still links (dismissed is a state a reader must see,
 * not a hole); a syncable with no entry at all is null, never a fabricated
 * status.
 *
 * Read-only. Touches no Tally, writes no row.
 */
class TallySyncLinkService
{
    public function __construct(private readonly EntryPresenter $presenter) {}

    /**
     * The link for one syncable, or null when no entry exists for it.
     *
     * @return array{entry_id: int, voucher_type: string, status: string, voucher_number: string, synced_at: ?string, flags: object, link: string}|null
     */
    public function for(Model $syncable): ?array
    {
        return $this->forMany($syncable->getMorphClass(), [$syncable->getKey()])[$syncable->getKey()] ?? null;
    }

    /**
     * The links for a whole page in ONE query, keyed by syncable_id. Ids
     * with no entry are simply absent from the result.
     *
     * @param  string  $morphClass  the syncable's morph class (Model::getMorphClass())
     * @param  list<int>  $ids
     * @return array<int, array{entry_id: int, voucher_type: string, status: string, voucher_number: string, synced_at: ?string, flags: object, link: string}>
     */
    public function forMany(string $morphClass, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $entries = TallySyncEntry::query()
            ->where('syncable_type', $morphClass)
            ->whereIn('syncable_id', $ids)
            ->orderByDesc('id')
            ->get();

        $links = [];
        foreach ($entries->groupBy('syncable_id') as $syncableId => $group) {
            // Newest-first already; the first non-dismissed row is the live
            // one, and only when none is live does the newest dismissed
            // row speak for the syncable.
            $chosen = $group->first(fn (TallySyncEntry $entry) => $entry->status !== TallySyncStatus::Dismissed)
                ?? $group->first();

            $links[(int) $syncableId] = $this->link($chosen);
        }

        // Callers iterate documents in their own order; the keys are what
        // matter. Kept in id order for a stable read all the same.
        ksort($links);

        return $links;
    }

    /**
     * The links for queue rows a caller already knows BY ID, in one query,
     * keyed by tally_sync_entries.id — for a document that carries its
     * voucher's foreign key itself rather than being the voucher's syncable
     * (a shift-granularity Stock Journal names the Shift as its syncable and
     * its member entries hang off shift_production_entries.tally_sync_entry_id;
     * the entry's own link IS that voucher's). Ids that name no row are
     * simply absent. Same shape, same read-only stance as forMany().
     *
     * @param  list<int>  $entryIds
     * @return array<int, array{entry_id: int, voucher_type: string, status: string, voucher_number: string, synced_at: ?string, flags: object, link: string}>
     */
    public function forEntryIds(array $entryIds): array
    {
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        if ($entryIds === []) {
            return [];
        }

        $links = [];
        foreach (TallySyncEntry::query()->whereIn('id', $entryIds)->orderBy('id')->get() as $entry) {
            $links[(int) $entry->id] = $this->link($entry);
        }

        return $links;
    }

    /**
     * @return array{entry_id: int, voucher_type: string, status: string, voucher_number: string, synced_at: ?string, flags: object, link: string}
     */
    private function link(TallySyncEntry $entry): array
    {
        return [
            'entry_id' => $entry->id,
            'voucher_type' => $entry->tally_voucher_type,
            'status' => $entry->status->value,
            'voucher_number' => $entry->voucherNumber(),
            'synced_at' => $entry->synced_at?->toIso8601String(),
            // An object, as TallySyncEntryResource emits it: an empty PHP
            // array wires as `[]`, a non-empty one as `{}`, and a client
            // typed to Record<string, …> must never meet a list.
            'flags' => (object) $this->presenter->flags($entry),
            'link' => "/tally-sync?entry={$entry->id}",
        ];
    }
}
