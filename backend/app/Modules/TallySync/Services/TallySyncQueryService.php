<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\Enums\TallyTransactionCategory;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * The read side of the sync queue (TALLY-SYNC-CHAIN.md §3 "A query
 * service"): server-side filters over tally_sync_entries, and the summary
 * the Control Center's header reads. Reads only — nothing here changes an
 * entry, a payload, a status, or Tally. TallySyncService keeps every write.
 *
 * Every filter reads columns the entry already has plus its payload. There
 * are deliberately NO denormalised payload columns (business_date,
 * document_number): the queue grows ~3–10 rows a day, so a JSON-path WHERE
 * is cheap for years, and copying a payload fact into a column is a second
 * place for it to drift. If volume ever makes this slow, the answer is a
 * functional/virtual INDEX on the JSON path — not a copy (TALLY-SYNC-CHAIN.md
 * §3 "Does not").
 *
 * The category filter is built from TransactionClassifier::pairsFor(), the
 * ONE classification table, so a category filter can never disagree with
 * the category the resource shows on a row. The `held` filter evaluates the
 * REAL release gate in PHP over the small candidate set — a second, SQL
 * rendering of the gate's rules is a second gate to keep honest, and the
 * live one already reads shifts.end_time and the factory timezone.
 */
class TallySyncQueryService
{
    /** Optional server-side sort: failed → pending → synced → dismissed, newest first within each. */
    public const SORT_STATUS_RANK = 'status_rank';

    /**
     * The order the Tally Sync page has always read the queue in (its
     * client-side sort, TallySyncPage.tsx statusRank): a rejection is never
     * pushed below the fold by a day of successful posts, and a written-off
     * voucher — the one row nobody will act on — sinks to the bottom.
     */
    private const STATUS_RANK = [
        TallySyncStatus::Failed->value => 0,
        TallySyncStatus::Pending->value => 1,
        TallySyncStatus::Synced->value => 2,
        TallySyncStatus::Dismissed->value => 3,
    ];

    /**
     * The status keys the summary counts, in the order they are reported.
     *
     * @var list<string>
     */
    private const COUNTED_STATUSES = ['synced', 'pending', 'failed', 'dismissed'];

    public function __construct(
        private readonly TransactionClassifier $classifier,
        private readonly ShiftVoucherReleaseGate $releaseGate,
    ) {}

    /**
     * The list, filtered. Default order is unchanged from the queue's first
     * day (newest id first); `sort=status_rank` is opt-in so a future page
     * can drop its client-side sort without today's page changing under it.
     *
     * @param  array<string, mixed>  $filters  the validated ListTallySyncEntriesRequest input
     */
    public function paginate(array $filters, int $perPage = 20, ?Authenticatable $reader = null): LengthAwarePaginator
    {
        return $this->listQuery($filters, $reader)->paginate($perPage);
    }

    /**
     * Every matching entry, in the list's order, one model at a time — the
     * Export Center's read (TallySyncEntriesExport): the SAME filters and
     * the SAME ordering as paginate(), off the same builder, so a file can
     * never carry rows the screen would not, nor in another order.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, TallySyncEntry>
     */
    public function cursor(array $filters, ?Authenticatable $reader = null): LazyCollection
    {
        return $this->listQuery($filters, $reader)->cursor();
    }

    /**
     * How many entries the list would carry — one COUNT over the filtered
     * query (the export's cap check).
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters, ?Authenticatable $reader = null): int
    {
        return $this->apply(TallySyncEntry::query(), $filters, null, $reader)->count();
    }

    /**
     * Every matching entry WITH its history seated (`events`, oldest
     * first — the relation's own order), in the list's order, a page of
     * models at a time — the Export Center's history read
     * (TallySyncHistoryExport): the SAME filters and the SAME ordering as
     * paginate(), off the same builder, so a history file can never carry
     * an entry the screen would not. lazy() rather than cursor() because
     * cursor() cannot eager-load, and one events query per page beats one
     * per entry.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, TallySyncEntry>
     */
    public function historyCursor(array $filters, ?Authenticatable $reader = null): LazyCollection
    {
        return $this->listQuery($filters, $reader)->with('events')->lazy(200);
    }

    /**
     * How many EVENTS the matching entries carry between them — one COUNT
     * over tally_sync_events against the filtered entry query (the history
     * export's cap check).
     *
     * @param  array<string, mixed>  $filters
     */
    public function historyCount(array $filters, ?Authenticatable $reader = null): int
    {
        return TallySyncEvent::query()
            ->whereIn(
                'tally_sync_entry_id',
                $this->apply(TallySyncEntry::query()->select('id'), $filters, null, $reader),
            )
            ->count();
    }

    /**
     * The list's builder: every filter applied, then the list's order.
     *
     * @param  array<string, mixed>  $filters
     */
    private function listQuery(array $filters, ?Authenticatable $reader): Builder
    {
        $query = $this->apply(TallySyncEntry::query(), $filters, null, $reader);

        if (($filters['sort'] ?? null) === self::SORT_STATUS_RANK) {
            // Bound, not interpolated, even though the values are our own
            // enum's: the habit is what keeps the next CASE safe.
            $bindings = [];
            $case = 'case status';
            foreach (self::STATUS_RANK as $status => $rank) {
                $case .= ' when ? then '.(int) $rank;
                $bindings[] = $status;
            }
            $case .= ' else '.count(self::STATUS_RANK).' end';

            $query->orderByRaw($case, $bindings);
        }

        return $query->orderByDesc('id');
    }

    /**
     * One entry with its history AND its snapshots (what the agent sent to
     * Tally and what Tally answered — Phase 4), for the drawer. Both are
     * relations on the model; the resource keys off relationLoaded().
     *
     * THE SNAPSHOTS ARE CAPPED (Phase 7, P7-03 (b)): the newest
     * config('tally-sync.snapshot_show_cap') of them ride the response
     * (newest first — the relation's own order), never every row. An XML
     * body can run to 2 MB and a voucher retried through a long Tally
     * outage carries one snapshot per attempt; the drawer showed them all.
     * The total is loaded beside them (snapshots_count) so the resource can
     * say how many exist and whether the list is cut — additive: the
     * response gains `snapshots_total` and `snapshots_truncated`, and a
     * voucher with fewer snapshots than the cap answers exactly as before.
     * The retention prune (TallySyncSnapshotService) is a separate,
     * unchanged rule about what is KEPT; this is only about what is SHOWN.
     */
    public function show(TallySyncEntry $entry): TallySyncEntry
    {
        $cap = max(1, (int) config('tally-sync.snapshot_show_cap', 20));

        $entry->load(['events', 'snapshots' => fn ($query) => $query->limit($cap)])
            ->loadCount('snapshots');

        return $entry;
    }

    /**
     * The header of the Control Center: today's and all-time counts, one
     * count (or an honest null) per catalogue category, and the last time
     * anything was heard from the agent or Tally.
     *
     * The COUNTS follow the same filters as the list, so a page filtered to
     * Receipt Notes reads Receipt Note counts. The LIVENESS fields (agent
     * action, last synced, last masters pull) are global on purpose: "when
     * did we last hear from the factory PC" is not a question a list filter
     * should be able to change the answer to.
     *
     * `held_now` is the third kind: a STATE, not a window. today.held is
     * bucketed inside today's business date, so every night, while the
     * night shift's voucher — dated YESTERDAY — is actively held by the
     * gate, today.held reads 0 and the header said "0 held" over a voucher
     * it was holding. held_now is the gate's verdict right now over the
     * request's non-date filters (status, category, shift, …): a
     * business-date range is a question about a day, and "how many are
     * held at this moment" is not.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     today: array{date: string, total: int, synced: int, pending: int, failed: int, dismissed: int, held: int},
     *     all_time: array{total: int, synced: int, pending: int, failed: int, dismissed: int, held: int},
     *     held_now: int,
     *     by_category: list<array{key: string, label: string, source: string, erp_build: string, wire_voucher_type: ?string, count: ?int}>,
     *     agent: array{last_action_at: ?string, last_action_event: ?string, last_action_label: ?string},
     *     last_synced_at: ?string,
     *     last_masters_pull_at: ?string,
     * }
     */
    public function summary(array $filters, ?Authenticatable $reader = null): array
    {
        // "Today" is the FACTORY's day, judged against the voucher's
        // BUSINESS date (payload->voucher_date), never against created_at:
        // the app clock is UTC, and a voucher dated yesterday that is
        // enqueued after midnight IST — the C shift's voucher, approved at
        // 00:30 — is yesterday's business, whichever UTC date the row was
        // written on. now() is localised through the one configured factory
        // timezone (CLAUDE.md: never compare the app clock against a
        // wall-clock string un-localised).
        $today = now()->setTimezone(config('tally-sync.factory_timezone'))->toDateString();

        // The gate is evaluated ONCE per summary and the ids reused for both
        // windows and for the held filter, if the request carries one.
        $heldIds = $this->heldIds();

        $filtered = fn (): Builder => $this->apply(TallySyncEntry::query(), $filters, $heldIds, $reader);

        // held_now: the same filters minus the business-date range (see the
        // docblock) — a held voucher is held whatever day it is dated.
        $undated = array_diff_key($filters, ['from' => true, 'to' => true]);
        $heldNow = $heldIds === []
            ? 0
            : $this->apply(TallySyncEntry::query(), $undated, $heldIds, $reader)->whereIn('id', $heldIds)->count();

        return [
            'today' => ['date' => $today] + $this->counts(
                $filtered()->where('payload->voucher_date', $today),
                $heldIds,
            ),
            'all_time' => $this->counts($filtered(), $heldIds),
            'held_now' => $heldNow,
            'by_category' => $this->countsByCategory($filtered),
            // UNFILTERED on purpose, unlike the counts above: this feeds the
            // page's voucher-type dropdown, and a dropdown that shrinks to
            // the one type already selected cannot be used to change the
            // selection. Voucher TYPES only — the labels the queue
            // dispatches on. Nothing about a party, a rate or a payload is
            // enumerated here.
            'voucher_types' => $this->voucherTypesInQueue(),
            'agent' => $this->lastAgentAction(),
            'last_synced_at' => TallySyncEntry::query()
                ->whereNotNull('synced_at')
                ->orderByDesc('synced_at')
                ->value('synced_at')
                ?->toIso8601String(),
            'last_masters_pull_at' => TallySyncEvent::query()
                ->where('event', TallySyncEventKind::MastersReceived->value)
                ->orderByDesc('occurred_at')
                ->value('occurred_at')
                ?->toIso8601String(),
        ];
    }

    // ---- filters ----------------------------------------------------------

    /**
     * Every filter of ListTallySyncEntriesRequest, applied to $query.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $heldIds  the gate's verdict, when the caller already has it
     */
    private function apply(Builder $query, array $filters, ?array $heldIds = null, ?Authenticatable $reader = null): Builder
    {
        if (! empty($filters['status'])) {
            $query->whereIn('status', array_values($filters['status']));
        }

        if (! empty($filters['category'])) {
            $this->applyCategory($query, array_values($filters['category']));
        }

        if (! empty($filters['voucher_type'])) {
            $query->whereIn('tally_voucher_type', array_values($filters['voucher_type']));
        }

        // Business-date range on the payload's voucher_date, inclusive at
        // both ends. "Y-m-d" strings compare correctly as strings on both
        // drivers, and the JSON path syntax compiles on MySQL
        // (json_unquote(json_extract(...))) and SQLite (json_extract(...))
        // alike. See the class docblock for why this is not a column.
        //
        // The whereNotNull on the same path is NOT redundant, and the two
        // drivers are why: SQLite's json_extract returns SQL NULL for a JSON
        // null, so a `voucher_date: null` payload simply fails the
        // comparison — but MySQL's json_unquote(json_extract(...)) turns a
        // JSON null into the four-character STRING 'null', and 'null' >=
        // '2026-08-01' is TRUE as strings, so an entry whose builder wrote a
        // null date would satisfy every `from`. Laravel compiles a JSON-path
        // whereNotNull JSON-null-aware on MySQL (json_type(...) != 'NULL'),
        // which is what makes the guard hold on both.
        if (! empty($filters['from'])) {
            $query->whereNotNull('payload->voucher_date')
                ->where('payload->voucher_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereNotNull('payload->voucher_date')
                ->where('payload->voucher_date', '<=', $filters['to']);
        }

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $this->applySearch($query, trim((string) $filters['q']), AgentIdentity::mayReadPurchaseDetails($reader));
        }

        if (! empty($filters['shift_id'])) {
            $this->applyShift($query, (int) $filters['shift_id']);
        }

        if (! empty($filters['work_center_id'])) {
            $this->applyWorkCenter($query, (int) $filters['work_center_id']);
        }

        if (array_key_exists('held', $filters) && $filters['held'] !== null) {
            $held = filter_var($filters['held'], FILTER_VALIDATE_BOOLEAN);
            $ids = $heldIds ?? $this->heldIds();
            $held ? $query->whereIn('id', $ids) : $query->whereNotIn('id', $ids);
        }

        // Every row of tally_sync_entries is ERP→Tally by construction
        // (TALLY-SYNC-CHAIN.md §1 "Direction"): the Tally→ERP flows — a
        // masters pull, a company binding — never create an entry; they are
        // recorded on tally_sync_events with direction tally_to_erp. So
        // erp_to_tally is a no-op here and tally_to_erp honestly matches
        // nothing, rather than pretending an inbound entry list exists.
        if (($filters['direction'] ?? null) === TallySyncEvent::DIRECTION_TALLY_TO_ERP) {
            $this->matchNothing($query);
        }

        return $query;
    }

    /**
     * Category → the (tally_voucher_type, syncable_type) pairs of the ONE
     * classification table, OR'd. A key with no pairs — Tally-only, planned
     * — matches nothing: the ERP builds no such entry, and an empty list is
     * the true answer. `unknown` is the complement: rows that classify to no
     * ERP-built category, exactly the rows the resource labels Unknown.
     *
     * @param  list<string>  $keys
     */
    private function applyCategory(Builder $query, array $keys): void
    {
        $pairs = [];
        $includeUnknown = false;

        foreach ($keys as $key) {
            $category = TallyTransactionCategory::from($key);
            if ($category === TallyTransactionCategory::Unknown) {
                $includeUnknown = true;

                continue;
            }
            $pairs = [...$pairs, ...$this->classifier->pairsFor($category)];
        }

        if ($pairs === [] && ! $includeUnknown) {
            $this->matchNothing($query);

            return;
        }

        $query->where(function (Builder $outer) use ($pairs, $includeUnknown) {
            $this->wherePairs($outer, $pairs);

            if ($includeUnknown) {
                $outer->orWhereNot(fn (Builder $known) => $this->wherePairs(
                    $known,
                    array_merge(...array_values($this->classifier->pairs())),
                ));
            }
        });
    }

    /**
     * (label = ? AND morph = ?) OR (label = ? AND morph = ?) ... — an
     * empty list adds nothing, so the caller decides what "no pairs" means.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    private function wherePairs(Builder $query, array $pairs): void
    {
        foreach ($pairs as [$label, $morph]) {
            $query->orWhere(fn (Builder $pair) => $pair
                ->where('tally_voucher_type', $label)
                ->where('syncable_type', $morph));
        }
    }

    /**
     * Free text over the three payload keys staff actually search by —
     * voucher_number ("SPE-12", "SJ-20260723-S1", "GRN-7"), party_ledger
     * (the customer/vendor name) and batch_number. Case-insensitive
     * contains-LIKE, and nothing cleverer: no tokenising, no fuzziness, no
     * reaching into narration. Typing exactly what is on the voucher finds
     * it, typing its prefix finds it, and a search that finds nothing found
     * nothing.
     *
     * LOWER() on both sides on purpose: on MySQL a value pulled out of a
     * JSON column carries the binary collation, so a bare LIKE there is
     * case-sensitive even though the same LIKE on a varchar is not.
     * $grammar->wrap() compiles the JSON path per driver, so the raw SQL is
     * driver-neutral; the ESCAPE character is '!' rather than '\' because
     * a backslash literal is spelled differently in MySQL and SQLite and
     * '!' is spelled the same in both.
     *
     * Each LIKE is guarded by a whereNotNull on ITS OWN path, and again the
     * drivers diverge on a JSON null: SQLite's json_extract yields SQL NULL
     * (LIKE is NULL, no match) but MySQL's json_unquote(json_extract(...))
     * yields the STRING 'null' — and every shift voucher writes
     * `batch_number: null` (shiftVoucherPayload), so on MySQL q=ul (or
     * q=null) would match every shift voucher in the queue. The guard
     * compiles JSON-null-aware on MySQL and is a no-op on SQLite.
     */
    private function applySearch(Builder $query, string $term, bool $mayReadPurchaseDetails): void
    {
        $needle = '%'.$this->escapeLike(mb_strtolower($term)).'%';
        $grammar = $query->getQuery()->getGrammar();

        $query->where(function (Builder $any) use ($needle, $grammar, $mayReadPurchaseDetails) {
            foreach (['payload->voucher_number', 'payload->party_ledger', 'payload->batch_number'] as $path) {
                $any->orWhere(function (Builder $present) use ($path, $needle, $grammar, $mayReadPurchaseDetails) {
                    $present
                        ->whereNotNull($path)
                        ->whereRaw('lower('.$grammar->wrap($path).") like ? escape '!'", [$needle]);

                    // FC-06, second half: the SUPPLIER on a Receipt Note is
                    // Owner/Accounts only. The resource already withholds the
                    // name from a reader without that standing — but a LIKE
                    // over party_ledger would still answer "?q=Reliance →
                    // 3 rows", a yes/no oracle on who supplied what. So for
                    // such a reader the party clause simply does not apply to
                    // supplier-party categories (Receipt Note; Purchase Order
                    // since Phase 6; Purchase if it ever exists). A CUSTOMER on a
                    // Delivery Note or Sales invoice is not FC-06 and stays
                    // searchable for everyone.
                    if ($path === 'payload->party_ledger' && ! $mayReadPurchaseDetails) {
                        $present->whereNot(fn (Builder $supplier) => $this->whereSupplierPartyCategory($supplier));
                    }
                });
            }
        });
    }

    /**
     * Rows whose category names a SUPPLIER as its party — read from the one
     * classification table (TransactionClassifier::pairsFor), never from a
     * second list of voucher types kept here.
     */
    private function whereSupplierPartyCategory(Builder $query): void
    {
        $query->where(function (Builder $any) {
            foreach (TallyTransactionCategory::cases() as $category) {
                if (! $category->partyIsSupplier()) {
                    continue;
                }
                foreach ($this->classifier->pairsFor($category) as [$voucherType, $morph]) {
                    $any->orWhere(fn (Builder $pair) => $pair
                        ->where('tally_voucher_type', $voucherType)
                        ->where('syncable_type', $morph));
                }
            }
        });
    }

    /**
     * Entries of one shift: a shift voucher whose syncable IS the Shift, a
     * batch voucher whose entry ran on it, or a shift voucher any of whose
     * members (shift_production_entries.tally_sync_entry_id — the
     * authoritative membership, TallySyncService::shiftVoucherPayload) ran
     * on it. Sub-selects, not joins, so the paginator's count stays a count
     * of entries.
     */
    private function applyShift(Builder $query, int $shiftId): void
    {
        $query->where(function (Builder $any) use ($shiftId) {
            $any->where(fn (Builder $voucher) => $voucher
                ->where('syncable_type', (new Shift)->getMorphClass())
                ->where('syncable_id', $shiftId));

            $any->orWhere(fn (Builder $batch) => $batch
                ->where('syncable_type', (new ShiftProductionEntry)->getMorphClass())
                ->whereIn('syncable_id', ShiftProductionEntry::query()->select('id')->where('shift_id', $shiftId)));

            $any->orWhereIn('id', $this->memberVoucherIds()->where('shift_id', $shiftId));
        });
    }

    /**
     * Entries of one machine: a batch voucher whose entry ran on it, or a
     * shift voucher any of whose members did. A shift voucher itself names
     * no machine (FC-01 in spirit: the voucher belongs to the shift), so
     * only its members can answer.
     */
    private function applyWorkCenter(Builder $query, int $workCenterId): void
    {
        $query->where(function (Builder $any) use ($workCenterId) {
            $any->where(fn (Builder $batch) => $batch
                ->where('syncable_type', (new ShiftProductionEntry)->getMorphClass())
                ->whereIn('syncable_id', ShiftProductionEntry::query()->select('id')->where('work_center_id', $workCenterId)));

            $any->orWhereIn('id', $this->memberVoucherIds()->where('work_center_id', $workCenterId));
        });
    }

    /** The voucher ids named by any member entry — narrowed further by the caller. */
    private function memberVoucherIds(): Builder
    {
        return ShiftProductionEntry::query()
            ->select('tally_sync_entry_id')
            ->whereNotNull('tally_sync_entry_id');
    }

    /**
     * The ids the release gate withholds RIGHT NOW — the real
     * ShiftVoucherReleaseGate::withholds(), run in PHP over the only rows it
     * can ever hold: pending Shift-morph vouchers not yet delivered and not
     * manually released. That set is a handful at any moment (one voucher
     * per running shift), so evaluating it in PHP and whereIn-ing the ids
     * costs nothing and keeps exactly one definition of "held".
     *
     * @return list<int>
     */
    private function heldIds(): array
    {
        return TallySyncEntry::query()
            ->where('status', TallySyncStatus::Pending)
            ->whereNull('delivered_at')
            ->whereNull('released_at')
            ->where('syncable_type', (new Shift)->getMorphClass())
            ->orderBy('id')
            ->get()
            ->filter(fn (TallySyncEntry $entry) => $this->releaseGate->withholds($entry))
            ->pluck('id')
            ->all();
    }

    /** A WHERE that no row satisfies — the honest result for a question the table cannot have a row for. */
    private function matchNothing(Builder $query): void
    {
        $query->whereRaw('0 = 1');
    }

    /** `%` and `_` in the typed term are characters, not wildcards ('!' is the ESCAPE character above). */
    private function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }

    // ---- summary ------------------------------------------------------------

    /**
     * total + one count per status + held, over whatever $query already
     * selects. One grouped query for the statuses, one for held.
     *
     * @param  list<int>  $heldIds
     * @return array{total: int, synced: int, pending: int, failed: int, dismissed: int, held: int}
     */
    private function counts(Builder $query, array $heldIds): array
    {
        $byStatus = (clone $query)
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = ['total' => (int) $byStatus->sum()];
        foreach (self::COUNTED_STATUSES as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        $counts['held'] = $heldIds === [] ? 0 : (clone $query)->whereIn('id', $heldIds)->count();

        return $counts;
    }

    /**
     * The FULL catalogue, one row per category in its stable order, with a
     * measured count on every ERP-built row (and on Unknown, whose rows are
     * just as real) — and NULL, never 0, on a Tally-only or absent row.
     * Nothing was measured there because nothing was mirrored; a zero would
     * read as "measured, none", which is a claim about the accountant's
     * books this ERP cannot make without reading Tally. Purchase Order is
     * ERP-built since Phase 6 (staged, flag off) and so is MEASURED — an
     * honest 0 on live until the owner opens the gate, never the accountant's
     * 92. Sales Order (source 'absent') is null because there is nothing in
     * the books to have measured at all — the census figures themselves are
     * evidence, not a count this ERP took.
     *
     * @param  callable(): Builder  $filtered  a fresh filtered query per call
     * @return list<array{key: string, label: string, source: string, erp_build: string, wire_voucher_type: ?string, count: ?int}>
     */
    private function countsByCategory(callable $filtered): array
    {
        $rows = [];

        foreach (TallyTransactionCategory::cases() as $category) {
            $described = $category->describe();
            $measurable = $category->source() === 'erp' || $category === TallyTransactionCategory::Unknown;

            $count = null;
            if ($measurable) {
                $query = $filtered();
                $this->applyCategory($query, [$category->value]);
                $count = $query->count();
            }

            $rows[] = [
                'key' => $described['key'],
                'label' => $described['label'],
                'source' => $described['source'],
                'erp_build' => $described['erp_build'],
                'wire_voucher_type' => $described['wire_voucher_type'],
                'count' => $count,
            ];
        }

        return $rows;
    }

    /**
     * The event kinds only the agent originates — the ones
     * TallySyncAgentController's endpoints record. Any other kind on a row
     * whose actor happens to be an agent token (there is none today, but a
     * future path could pass the agent through) would not be the agent
     * ACTING on the sync, so the light reads only these.
     *
     * @var list<TallySyncEventKind>
     */
    private const AGENT_ACTION_EVENTS = [
        TallySyncEventKind::PendingDelivered,
        TallySyncEventKind::VoucherSynced,
        TallySyncEventKind::VoucherFailed,
        TallySyncEventKind::VoucherFailureRefused,
        TallySyncEventKind::MastersReceived,
        TallySyncEventKind::CompaniesReceived,
        TallySyncEventKind::CompanyBound,
        TallySyncEventKind::StockSummaryPreviewed,
    ];

    /**
     * The last thing the agent DID — a poll that delivered a voucher, an
     * ack, a failure report, a masters push — from tally_sync_events rows
     * of an agent-originated kind whose actor is the agent (AgentIdentity:
     * a real token carrying the agent's abilities; a person driving the
     * same endpoints with a session or a plain token is recorded as a user
     * and never lights this). Nulls when no agent has ever acted here.
     *
     * Named ACTION, not contact, on purpose: a heartbeat poll that finds
     * nothing to deliver records no event (pending() writes
     * pending.delivered only when a stamp lands), so the agent can be alive
     * and polling every 90 s while this stands still. What it answers is
     * "when did the factory PC last do something to the sync", not "when
     * was it last heard from" — the page label says the same.
     *
     * @return array{last_action_at: ?string, last_action_event: ?string, last_action_label: ?string}
     */
    private function lastAgentAction(): array
    {
        $last = TallySyncEvent::query()
            ->where('actor_type', TallySyncEvent::ACTOR_AGENT)
            ->whereIn('event', array_map(fn (TallySyncEventKind $kind) => $kind->value, self::AGENT_ACTION_EVENTS))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first(['occurred_at', 'event', 'actor_label']);

        return [
            'last_action_at' => $last?->occurred_at?->toIso8601String(),
            'last_action_event' => $last?->event,
            'last_action_label' => $last?->actor_label,
            // WHEN THE AGENT LAST CHECKED IN, which is a different question
            // from what it last DID. An idle poll delivers nothing and so
            // records no event — last_action_at can be hours old on an agent
            // that is polling every 90 seconds — but Sanctum stamps the
            // token's last_used_at on the poll itself. Null = no agent token
            // has ever been used, which is "never", not "down"; the page
            // keeps those apart. A timestamp only: no token id, no name, no
            // abilities (AgentIdentity::lastCheckedAt).
            'last_checked_at' => AgentIdentity::lastCheckedAt()?->toIso8601String(),
        ];
    }

    /**
     * The distinct tally_voucher_type labels the queue actually holds,
     * alphabetically.
     *
     * The RAW label, because that is what the filter matches
     * (apply(): whereIn('tally_voucher_type', ...)). It is deliberately not
     * derived from the category catalogue's wire_voucher_type: the two
     * differ by design on at least one category — a batch-mode production
     * voucher is labelled 'Manufacturing Journal' here and posts as a Stock
     * Journal on the wire (TallyTransactionCategory::ProductionStockJournalBatch)
     * — so wire types offered as filter values would silently match nothing.
     *
     * @return list<string>
     */
    private function voucherTypesInQueue(): array
    {
        return TallySyncEntry::query()
            ->whereNotNull('tally_voucher_type')
            ->where('tally_voucher_type', '!=', '')
            ->distinct()
            ->orderBy('tally_voucher_type')
            ->pluck('tally_voucher_type')
            ->all();
    }
}
