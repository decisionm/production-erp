<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TallyGodownResolver;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use Illuminate\Database\Eloquent\Collection;

/**
 * THE one resolver of a voucher's NAMES to their mapping state — shared by
 * the pre-approval preview (VoucherPreviewService) and the Control Center's
 * show endpoint (EntryMappingSurface), so the two can never disagree about
 * whether "PET Resin" or "RM Store" is a thing Tally will recognise.
 *
 * Why names at all: every payload TallySyncService builds carries item,
 * godown and ledger NAMES (Tally's import keys on names, not ids), and the
 * masters that back those names — items.tally_stock_item_guid,
 * warehouses.tally_guid via TallyGodownResolver, tally_ledger_mappings —
 * are resolved at BUILD time and stored nowhere on the entry
 * (TALLY-SYNC-CHAIN.md §1 "Master mappings"). So "how did this line
 * resolve?" is a question answered by looking the name up again, now,
 * against the masters as they stand. That is what this class does, and it
 * is DERIVED on every read: there is deliberately no conflict table, no
 * stored verdict — a name that was unmapped yesterday and mapped today
 * reads as mapped today (MASTER-PLAN P3-04, audit §116/§62).
 *
 * THE STATES, and what each one is allowed to claim:
 *
 *   identity   — the name resolved to a row that carries a Tally GUID (or,
 *                for a ledger role, to a configured mapping). The strongest
 *                claim this ERP can make without reading Tally — and still
 *                not "confirmed present in Tally": the GUID was recorded
 *                when masters were last pulled, and Tally matches the
 *                voucher by NAME, so the line posts if that master still
 *                carries this name. The identity note says exactly that,
 *                so a green badge is never read as a Tally-side fact.
 *   name_only  — a row exists by that name but carries no Tally GUID and
 *                aliases to nothing Tally-known. Tally matches by name, so
 *                the line posts IF a master so named exists there — and this
 *                ERP cannot know that. Said as such; never rounded up to
 *                "exists" or down to "missing".
 *   unmapped   — no row by that name at all.
 *   fixture    — the row is a LOCAL- rehearsal product (Item::isLocalFixture),
 *                which is never postable whatever else it carries — the same
 *                conservative direction the item's own docblock chooses.
 *   ambiguous  — MORE THAN ONE row shares the name. items.name and
 *                warehouses.name carry no unique index (migrations
 *                2026_07_18_155120/155121), so this can happen; Tally would
 *                still match exactly one by name, and this ERP cannot say
 *                which — so it says that, with the counts, and picks none.
 *                Detected by count; no table needed. The count rides the
 *                state as `shared_count` (null in every other state) so a
 *                reader never has to parse it back out of the note; the
 *                candidate rows themselves are itemCandidates(), for the
 *                caller that must judge the whole set (the preview reads
 *                uom and packing kind from ALL of them, fail-closed).
 *   none       — the line carries no name for that dimension (a Sales line
 *                has no godown; a Journal line has no item).
 *
 * WHAT THIS CLASS NEVER DOES: match fuzzily (one `where name = ?` — equality
 * exactly as the database compares it, and nothing cleverer here: MySQL's
 * utf8mb4_unicode_ci folds case and trailing spaces, SQLite does not, and
 * this class neither adds to nor subtracts from that), query Tally, or reach
 * into the syncable model to fill a gap. And it is cheap on purpose — one
 * query per DISTINCT name, memoised for the instance's lifetime (one request
 * / one preview) — because the show endpoint calls it once per line of the
 * voucher and a shift voucher names the same resin and the same godown on
 * most of its lines. There is deliberately NO bulk whereIn pre-warm: the memo
 * is keyed by the name as asked, and regrouping a whereIn's rows under the
 * asked names would mean re-implementing the database's collation in PHP —
 * the one thing above this class refuses to do.
 */
class LineMappingResolver
{
    public const STATE_IDENTITY = 'identity';

    public const STATE_NAME_ONLY = 'name_only';

    public const STATE_UNMAPPED = 'unmapped';

    public const STATE_FIXTURE = 'fixture';

    public const STATE_AMBIGUOUS = 'ambiguous';

    public const STATE_NONE = 'none';

    /**
     * The states a mapping summary counts, in the order it reports them.
     * `none` is not a mapping outcome — nothing was there to map — so it
     * is not counted.
     *
     * @var list<string>
     */
    public const COUNTED_STATES = [
        self::STATE_IDENTITY,
        self::STATE_NAME_ONLY,
        self::STATE_UNMAPPED,
        self::STATE_FIXTURE,
        self::STATE_AMBIGUOUS,
    ];

    /** How a godown reached identity: its own GUID, a Tally-linked ancestor, or the sole linked godown (TallyGodownResolver rules 1–3). */
    public const VIA_SELF = 'self';

    public const VIA_ANCESTOR = 'ancestor';

    public const VIA_SOLE_LINKED = 'sole_linked';

    /**
     * Bounded ancestor walk when classifying HOW a godown resolved — mirrors
     * TallyGodownResolver's own bound so a cyclic hierarchy cannot hang a
     * read either.
     */
    private const MAX_ANCESTOR_DEPTH = 32;

    /** @var array<string, Collection<int, Item>> the item rows sharing a name, once per name */
    private array $itemRows = [];

    /** @var array<string, array{state: string, warehouse_id: ?int, tally_guid: ?string, resolved_via: ?string, shared_count: ?int, note: ?string}> */
    private array $godownStates = [];

    /** @var array<string, ?string> role value => the mapped Tally ledger name (null = unmapped), once per role */
    private array $roleNames = [];

    public function __construct(
        private readonly TallyGodownResolver $godowns,
        private readonly TallyLedgerMappingService $ledgerMappings,
    ) {}

    // ---- items ---------------------------------------------------------------

    /**
     * The mapping state of one stock-item name.
     *
     * @return array{state: string, item_id: ?int, tally_stock_item_guid: ?string, shared_count: ?int, note: ?string}
     */
    public function item(?string $name): array
    {
        if ($name === null || $name === '') {
            return $this->itemState(self::STATE_NONE, note: 'The line names no stock item.');
        }

        $rows = $this->itemRowsNamed($name);

        if ($rows->isEmpty()) {
            return $this->itemState(self::STATE_UNMAPPED, note: "No item named \"{$name}\" exists in this ERP.");
        }

        if ($rows->count() > 1) {
            $withGuid = $rows->filter(fn (Item $item) => $item->tally_stock_item_guid !== null)->count();
            $fixtures = $rows->filter(fn (Item $item) => $item->isLocalFixture())->count();

            return $this->itemState(
                self::STATE_AMBIGUOUS,
                sharedCount: $rows->count(),
                note: "{$rows->count()} items in this ERP share the name \"{$name}\" ({$withGuid} with a Tally GUID, "
                    ."{$fixtures} local fixtures). Tally would match one of them by name; this ERP cannot say which.",
            );
        }

        /** @var Item $item */
        $item = $rows->first();

        if ($item->isLocalFixture()) {
            // Fixture wins over the GUID: a fixture carrying one is a data
            // contradiction the item already logs loudly, and the posting
            // paths (enqueueShiftProductionEntry) refuse a fixture whatever
            // it carries — the state must not read as postable.
            return $this->itemState(
                self::STATE_FIXTURE,
                $item,
                "\"{$name}\" is a local rehearsal product (a LOCAL- fixture): it exists in this ERP only and is never posted.",
            );
        }

        if ($item->tally_stock_item_guid !== null) {
            // Said even here: the GUID is what THIS ERP recorded at the last
            // masters pull, not a reading of Tally now — the voucher will
            // still be matched by name.
            return $this->itemState(
                self::STATE_IDENTITY,
                $item,
                note: "\"{$name}\" is linked to Tally stock item {$item->tally_stock_item_guid}, recorded when masters were "
                    .'last pulled; Tally matches by name, so this line posts if that master still carries this name — '
                    .'this ERP cannot know that.',
            );
        }

        return $this->itemState(
            self::STATE_NAME_ONLY,
            $item,
            "\"{$name}\" is in this ERP without a Tally GUID. Tally matches stock items by name, so this line posts "
                .'only if a stock item so named exists there — this ERP cannot know that.',
        );
    }

    /**
     * The ONE item row a name resolves to — null unless exactly one exists
     * (none, or several, is null: nothing is picked). For the caller that
     * needs a column of the row (the preview reads its uom and packing
     * kind), sharing the same memoised lookup rather than querying twice.
     */
    public function itemRow(?string $name): ?Item
    {
        if ($name === null || $name === '') {
            return null;
        }

        $rows = $this->itemRowsNamed($name);

        return $rows->count() === 1 ? $rows->first() : null;
    }

    /**
     * EVERY item row a name resolves to — empty for none, one for the
     * usual case, several when the name is ambiguous. For the caller that
     * must not let "no single row" turn into "no row": the preview reads
     * uom and packing kind from the whole set so an ambiguous packing
     * material still trips the packing-store blocker. Same memoised lookup
     * as item() / itemRow(); no second query.
     *
     * @return Collection<int, Item>
     */
    public function itemCandidates(?string $name): Collection
    {
        if ($name === null || $name === '') {
            return new Collection;
        }

        return $this->itemRowsNamed($name);
    }

    /** @return Collection<int, Item> */
    private function itemRowsNamed(string $name): Collection
    {
        // Exact name, the model's default scope (soft-deleted rows excluded —
        // a deleted item is not a master Tally would be handed) and NO
        // is_active filter: the payload builders write the name of whatever
        // item was consumed or produced, active or not, and the state must
        // describe that row.
        return $this->itemRows[$name] ??= Item::query()->where('name', $name)->get();
    }

    /**
     * @return array{state: string, item_id: ?int, tally_stock_item_guid: ?string, shared_count: ?int, note: ?string}
     */
    private function itemState(string $state, ?Item $item = null, ?string $note = null, ?int $sharedCount = null): array
    {
        return [
            'state' => $state,
            'item_id' => $item?->id,
            'tally_stock_item_guid' => $item?->tally_stock_item_guid,
            // How many rows share the name — only ever set on `ambiguous`,
            // structured so no reader parses it back out of the note.
            'shared_count' => $sharedCount,
            'note' => $note,
        ];
    }

    // ---- godowns -------------------------------------------------------------

    /**
     * The mapping state of one godown name, judged by the SAME resolver the
     * payload builders and the preview use (TallyGodownResolver): a
     * warehouse without its own tally_guid still reaches identity when it
     * aliases to a Tally-known godown — its ancestor, or the sole linked
     * godown in a one-godown system — and `resolved_via` says which rule
     * carried it there.
     *
     * @return array{state: string, warehouse_id: ?int, tally_guid: ?string, resolved_via: ?string, shared_count: ?int, note: ?string}
     */
    public function godown(?string $name): array
    {
        if ($name === null || $name === '') {
            return $this->godownState(self::STATE_NONE, note: 'The line names no godown.');
        }

        return $this->godownStates[$name] ??= $this->resolveGodown($name);
    }

    /**
     * @return array{state: string, warehouse_id: ?int, tally_guid: ?string, resolved_via: ?string, shared_count: ?int, note: ?string}
     */
    private function resolveGodown(string $name): array
    {
        $rows = Warehouse::query()->where('name', $name)->get();

        if ($rows->isEmpty()) {
            return $this->godownState(self::STATE_UNMAPPED, note: "No warehouse named \"{$name}\" exists in this ERP.");
        }

        if ($rows->count() > 1) {
            return $this->godownState(
                self::STATE_AMBIGUOUS,
                sharedCount: $rows->count(),
                note: "{$rows->count()} warehouses in this ERP share the name \"{$name}\". Tally would match one godown "
                    .'by name; this ERP cannot say which.',
            );
        }

        /** @var Warehouse $warehouse */
        $warehouse = $rows->first();
        $resolved = $this->godowns->resolve($warehouse);

        if ($resolved === null) {
            return $this->godownState(
                self::STATE_NAME_ONLY,
                $warehouse,
                note: "\"{$name}\" is in this ERP without a Tally GUID and aliases to no Tally-known godown. Tally "
                    .'matches godowns by name, so this line posts only if a godown so named exists there — this ERP '
                    .'cannot know that.',
            );
        }

        // The identity caveat, in every identity note: the GUID is what
        // this ERP recorded at the last masters pull, and Tally matches the
        // voucher's godown by NAME — so even a green godown "posts if".
        $recorded = "is linked to Tally godown {$resolved->tally_guid}, recorded when masters were last pulled; Tally "
            .'matches by name, so this line posts if that godown still carries this name — this ERP cannot know that.';

        if ($resolved->is($warehouse)) {
            return $this->godownState(self::STATE_IDENTITY, $warehouse, $resolved, self::VIA_SELF, note: "\"{$name}\" {$recorded}");
        }

        $via = $this->isAncestor($resolved, $warehouse) ? self::VIA_ANCESTOR : self::VIA_SOLE_LINKED;

        return $this->godownState(
            self::STATE_IDENTITY,
            $warehouse,
            $resolved,
            $via,
            note: ($via === self::VIA_ANCESTOR
                ? "\"{$name}\" is an internal location; its lines post under its Tally-known ancestor \"{$resolved->name}\"."
                : "\"{$name}\" is an internal location; its lines post under the sole Tally-linked godown \"{$resolved->name}\".")
                ." \"{$resolved->name}\" {$recorded}",
        );
    }

    /**
     * Whether $candidate sits above $warehouse in the parent chain. Walks
     * the relations TallyGodownResolver::resolve() already loaded on the
     * way up, so this classifies its answer without another query.
     */
    private function isAncestor(Warehouse $candidate, Warehouse $warehouse): bool
    {
        $current = $warehouse;
        for ($depth = 0; $depth < self::MAX_ANCESTOR_DEPTH && $current->parent_id !== null; $depth++) {
            $parent = $current->parent;
            if ($parent === null) {
                return false;
            }
            if ($parent->is($candidate)) {
                return true;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * @return array{state: string, warehouse_id: ?int, tally_guid: ?string, resolved_via: ?string, shared_count: ?int, note: ?string}
     */
    private function godownState(
        string $state,
        ?Warehouse $warehouse = null,
        ?Warehouse $resolved = null,
        ?string $via = null,
        ?string $note = null,
        ?int $sharedCount = null,
    ): array {
        return [
            'state' => $state,
            'warehouse_id' => $warehouse?->id,
            // The GUID Tally will match — the resolved godown's, which is the
            // warehouse's own under rule 1 and its stand-in's otherwise.
            'tally_guid' => $resolved?->tally_guid,
            'resolved_via' => $via,
            // Set on `ambiguous` only — see itemState().
            'shared_count' => $sharedCount,
            'note' => $note,
        ];
    }

    // ---- ledgers -------------------------------------------------------------

    /**
     * The mapping state of one ledger reference — a posting ROLE (the
     * Sales payload's sales_ledger comes from TallyLedgerMapping) or a plain
     * NAME (a party ledger, a Journal line's GL account). Dispatches on
     * whether the string is a TallyLedgerRole value; a caller that already
     * knows which it holds should call ledgerRole()/ledgerName() directly —
     * EntryMappingSurface does — so a customer who happens to be named
     * "sales" is never mistaken for the role.
     *
     * @return array{state: string, note: ?string}
     */
    public function ledger(?string $roleOrName): array
    {
        $role = $roleOrName !== null ? TallyLedgerRole::tryFrom($roleOrName) : null;

        return $role !== null ? $this->ledgerRole($role) : $this->ledgerName($roleOrName);
    }

    /**
     * A posting role, against tally_ledger_mappings AS IT STANDS NOW:
     * identity when a Tally ledger name is configured for it, unmapped
     * otherwise. $queuedName is the name the voucher's payload actually
     * carries (written at enqueue) so the note can say when the two have
     * drifted apart — the queued voucher posts what it carries; a
     * regenerated payload would carry the current mapping.
     *
     * @return array{state: string, note: ?string}
     */
    public function ledgerRole(TallyLedgerRole $role, ?string $queuedName = null): array
    {
        if (! array_key_exists($role->value, $this->roleNames)) {
            $this->roleNames[$role->value] = $this->ledgerMappings->get($role);
        }
        $mapped = $this->roleNames[$role->value];

        if ($mapped === null) {
            return [
                'state' => self::STATE_UNMAPPED,
                'note' => "No Tally ledger is mapped to the \"{$role->label()}\" role (Settings → Ledger Mappings)."
                    .($queuedName !== null ? " The queued voucher names \"{$queuedName}\", written when it was queued." : ''),
            ];
        }

        return [
            'state' => self::STATE_IDENTITY,
            // The same caveat every identity carries: a mapping is a name
            // this ERP holds, not a reading of Tally's chart of accounts.
            'note' => "The \"{$role->label()}\" role is mapped to Tally ledger \"{$mapped}\" (Settings → Ledger Mappings)."
                .($queuedName !== null && $queuedName !== $mapped
                    ? " The queued voucher still names \"{$queuedName}\"; a regenerated payload would carry \"{$mapped}\"."
                    : '')
                .' The mapping is a name this ERP holds; Tally matches by name, so this line posts if a ledger so named '
                .'exists there — this ERP cannot know that.',
        ];
    }

    /**
     * A ledger carried as a bare NAME — the party (customer / vendor name)
     * on a Sales, Receipt Note or Delivery Note payload, or a Journal line's
     * "code - name" GL account. The ERP holds NO link from a customer,
     * vendor or GL account to a Tally ledger (there is no ledger FK on any
     * of them — audit §1.4), so the honest state is name_only and the note
     * says so; nothing here looks the name up anywhere, because there is
     * nowhere authoritative to look.
     *
     * @return array{state: string, note: ?string}
     */
    public function ledgerName(?string $name): array
    {
        if ($name === null || $name === '') {
            return ['state' => self::STATE_NONE, 'note' => 'The line names no ledger.'];
        }

        return [
            'state' => self::STATE_NAME_ONLY,
            'note' => "\"{$name}\" is carried as a name only: this ERP holds no link from its parties or accounts to a "
                .'Tally ledger, so Tally matches it by name if a ledger so named exists there — this ERP cannot know that.',
        ];
    }
}
