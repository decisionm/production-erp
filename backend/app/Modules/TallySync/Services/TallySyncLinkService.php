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
 * became idempotent, or a dismissed voucher re-issued): among the LIVE
 * (non-dismissed) entries, the one that stands for the document in Tally —
 * SYNCED first (it is in the books), then pending / delivered (on its way),
 * then failed — newest first among equals (Phase 7, P7-03 (c); it used to
 * be plainly the newest live row, so a legacy pair of a synced older
 * voucher and a pending newer one linked the pending one and the document
 * read "pending" while its voucher sat in Tally). When more than one
 * candidate was weighed the link's flags carry `superseded_count` — how
 * many OTHER candidates the chosen one outranked — so a reader can tell a
 * plain answer from a ranked one. A lone dismissed entry still links
 * (dismissed is a state a reader must see, not a hole), and only when NO
 * live entry exists do the dismissed ones compete, by the same rule; a
 * syncable with no entry at all is null, never a fabricated status.
 *
 * Read-only. Touches no Tally, writes no row.
 */
class TallySyncLinkService
{
    /**
     * The ranking among a document's live entries — lower wins. Delivered
     * is not a status of its own (a pending row with delivered_at stamped),
     * so it ranks with pending. Dismissed rows are never candidates while
     * a live one exists; among themselves (a document whose every voucher
     * was written off) they tie at the bottom and the newest speaks.
     */
    private const STATUS_RANK = [
        'synced' => 0,
        'pending' => 1,
        'failed' => 2,
        'dismissed' => 3,
    ];

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
     * HOW MANY queue rows name this syncable — every one of them, including
     * dismissed and superseded rows, because the question this answers is
     * not "which voucher speaks for the document" but "has this record ever
     * been in the Tally chain at all".
     *
     * Added for the Configuration Lifecycle Contract's dependency report: a
     * shift-granularity Stock Journal names the SHIFT as its syncable
     * through `syncable_type` + `syncable_id`, which is a plain pair of
     * columns with no foreign key and no cascade behind it, so nothing in
     * the schema would stop a shift being deleted out from under a posted
     * voucher. A module asks THIS, rather than reading tally_sync_entries
     * itself, and gets a COUNT — never a payload, never a rate, never a
     * party (the FC-06 gate lives on TallySyncEntryResource and this is not
     * a way around it).
     *
     * Read-only. Touches no Tally, writes no row, changes no status.
     *
     * @param  string  $morphClass  the syncable's morph class (Model::getMorphClass())
     */
    public function countFor(string $morphClass, int|string $syncableId): int
    {
        return (int) TallySyncEntry::query()
            ->where('syncable_type', $morphClass)
            ->where('syncable_id', $syncableId)
            ->count();
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
            // The live rows compete; only when none is live do the
            // dismissed ones. Newest-first already, so a stable sort by
            // rank keeps "newest among equals" without a second key.
            $live = $group->filter(fn (TallySyncEntry $entry) => $entry->status !== TallySyncStatus::Dismissed);
            $candidates = ($live->isNotEmpty() ? $live : $group)
                ->sortBy(fn (TallySyncEntry $entry) => self::STATUS_RANK[$entry->status->value] ?? count(self::STATUS_RANK))
                ->values();

            $links[(int) $syncableId] = $this->link($candidates->first(), superseded: $candidates->count() - 1);
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
     * @param  int  $superseded  how many OTHER candidates this entry outranked
     *                           for its document (forMany); 0 — the usual
     *                           case, and every forEntryIds() link — adds no
     *                           flag, so the shape is exactly what it was.
     * @return array{entry_id: int, voucher_type: string, status: string, voucher_number: string, synced_at: ?string, flags: object, link: string}
     */
    private function link(TallySyncEntry $entry, int $superseded = 0): array
    {
        $flags = $this->presenter->flags($entry);
        if ($superseded > 0) {
            // A ranked answer says so: the document has more than one
            // voucher row and this one was chosen over the others by the
            // class rule (synced > pending > failed, newest among equals).
            $flags['superseded_count'] = $superseded;
        }

        return [
            'entry_id' => $entry->id,
            'voucher_type' => $entry->tally_voucher_type,
            'status' => $entry->status->value,
            'voucher_number' => $entry->voucherNumber(),
            'synced_at' => $entry->synced_at?->toIso8601String(),
            // An object, as TallySyncEntryResource emits it: an empty PHP
            // array wires as `[]`, a non-empty one as `{}`, and a client
            // typed to Record<string, …> must never meet a list.
            'flags' => (object) $flags,
            'link' => "/tally-sync?entry={$entry->id}",
        ];
    }
}
