<?php

namespace App\Modules\Procurement\Services;

use App\Models\User;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\TallySync\Models\Ledger;
use App\Modules\TallySync\Models\TallyVendorReviewDismissal;
use App\Modules\TallySync\Services\LedgerDirectory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE REVIEW BETWEEN TALLY AND THE VENDOR MASTER — what Tally now says about a
 * party, what the ERP currently records, and the difference a person is being
 * asked to confirm.
 *
 * NOTHING HERE HAPPENS BY ITSELF. The masters pull mirrors ledgers; it does
 * not create or change a single vendor. Every write in this file is the direct
 * result of an Owner/Accounts login pressing confirm on a difference it can
 * see. That is the whole point of the screen: a background job that quietly
 * invented hundreds of vendors, or silently corrected one, is exactly what
 * nobody notices until an order goes to the wrong party.
 *
 * THE QUEUE IS COMPUTED, NEVER STORED. Asked for, it is derived from the
 * ledger mirror against the vendor master there and then, so it cannot go
 * stale behind a re-sync and there is no second copy of the truth to
 * reconcile. The only thing persisted is what a person set ASIDE, and that is
 * scoped to the value dismissed — see TallyVendorReviewDismissal.
 *
 * MATCHING, AND WHY IT REFUSES TO GUESS. The owner named two identities: the
 * exact Tally identity, and the GSTIN. The first is exact and safe — a GUID is
 * Tally's own stable key. The second is NOT unique in these books: measured on
 * the live company's All Masters export, 23 GSTINs appear on more than one
 * ledger, two Sundry Creditors among them sharing one. So a GSTIN that could
 * mean two parties produces an AMBIGUOUS row that names the candidates and
 * refuses to apply anything until a person picks. This repository has already
 * paid for the other behaviour once, when a first-name-only identity map put
 * one person's employee number on another.
 *
 * TALLY NEVER CLEARS A FIELD. A difference is only ever raised where Tally has
 * a value; where Tally has nothing, the ERP's own value stands untouched. The
 * mirror carries an absent contact for almost every party (4 emails across
 * 1742 ledgers), and letting that absence overwrite a phone number somebody
 * typed into the vendor form would be a data loss dressed up as a sync.
 */
class TallyVendorReviewService
{
    /**
     * The owner-named Tally groups whose ledgers are candidate vendors.
     * Defaults to NOTHING: until the owner names them, the screen proposes no
     * new party at all. Deciding which creditor is a supplier is the owner's
     * call, not a filter an agent writes.
     */
    public const KEY_VENDOR_GROUPS = 'tally_vendor_ledger_groups';

    /** The vendor fields Tally can speak to, in the order a person reads them. */
    public const REVIEWABLE_FIELDS = ['name', 'email', 'phone', 'gstin', 'state_code', 'tally_ledger_name'];

    public function __construct(
        private readonly LedgerDirectory $ledgers,
        private readonly AppSettingService $settings,
        private readonly VendorService $vendors,
    ) {}

    /** @return list<string> */
    public function vendorGroups(): array
    {
        $groups = $this->settings->get(self::KEY_VENDOR_GROUPS, []);

        return is_array($groups) ? array_values(array_filter(array_map('strval', $groups))) : [];
    }

    /** @param  list<string>  $groups */
    public function setVendorGroups(array $groups): void
    {
        $census = $this->ledgers->groupCensus();

        $clean = collect($groups)->map(fn ($g) => trim((string) $g))->filter()->unique()->values();

        // A group that is not in the mirror would silently watch nothing. The
        // import command refuses the same way, and for the same reason: a typo
        // that quietly halves the vendor list is worse than a stopped action.
        $unknown = $clean->reject(fn (string $g) => $census->has($g));

        if ($unknown->isNotEmpty()) {
            throw new RuntimeException('Not a Tally ledger group in this database: '.$unknown->implode(', '));
        }

        $this->settings->set(self::KEY_VENDOR_GROUPS, $clean->all());
    }

    /**
     * The review queue: every party ledger that is either not yet a vendor, or
     * is one whose recorded details Tally now disagrees with.
     *
     * A ledger that matches a vendor exactly, field for field, produces no row
     * — the screen is a list of decisions owed, not a directory.
     *
     * @return array{
     *     groups: list<string>,
     *     group_census: array<string, int>,
     *     rows: list<array<string, mixed>>,
     *     last_synced_at: string|null
     * }
     */
    public function queue(): array
    {
        $groups = $this->vendorGroups();

        $linkedGuids = Vendor::withTrashed()
            ->whereNotNull('tally_ledger_guid')
            ->pluck('tally_ledger_guid')
            ->all();

        $ledgers = $this->ledgers->partyLedgers($groups, $linkedGuids);

        $dismissals = TallyVendorReviewDismissal::query()
            ->whereIn('tally_ledger_guid', $ledgers->pluck('tally_guid')->all())
            ->get()
            ->groupBy('tally_ledger_guid');

        // Every vendor that could be matched, loaded once. withTrashed on
        // purpose: a vendor somebody retired still owns its name, its code and
        // its GSTIN, and proposing a fresh duplicate of it is not a helpful
        // suggestion — it is the beginning of two rows for one supplier.
        $vendors = Vendor::withTrashed()->get();
        $byGuid = $vendors->whereNotNull('tally_ledger_guid')->keyBy('tally_ledger_guid');
        $byGstin = $vendors->filter(fn (Vendor $v) => VendorFromLedger::clean($v->gstin) !== null)
            ->groupBy(fn (Vendor $v) => strtoupper(trim((string) $v->gstin)));
        // Keyed from the collection already in memory rather than queried per
        // row: a freshly-named group is 620 unlinked ledgers on this
        // factory's books, and a query each to ask "is this name taken" is 620
        // round trips on shared hosting for an answer we are already holding.
        $byName = $vendors->keyBy(fn (Vendor $v) => self::nameKey((string) $v->name));

        $ledgerCountsByGstin = $this->ledgers->ledgerCountsByGstin(
            $ledgers->pluck('gstin')->filter()->unique()->values()->all()
        );

        $rows = [];

        foreach ($ledgers as $ledger) {
            $row = $this->rowFor($ledger, $byGuid, $byGstin, $byName, $ledgerCountsByGstin, $dismissals->get($ledger->tally_guid) ?? collect());

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return [
            'groups' => $groups,
            'group_census' => $this->ledgers->groupCensus()->all(),
            'rows' => $rows,
            // The provenance line every screen shows. The newest stamp across
            // the ledgers actually under review, not `now()` and not the
            // newest row in the whole mirror.
            'last_synced_at' => optional($ledgers->max('tally_synced_at'))?->toIso8601String(),
        ];
    }

    /**
     * One ledger's row, or null when there is nothing to decide.
     *
     * @param  Collection<string, Vendor>  $byGuid
     * @param  Collection<string, Collection<int, Vendor>>  $byGstin
     * @param  Collection<string, Vendor>  $byName
     * @param  Collection<string, int>  $ledgerCountsByGstin
     * @param  Collection<int, TallyVendorReviewDismissal>  $dismissals
     * @return array<string, mixed>|null
     */
    private function rowFor(
        Ledger $ledger,
        Collection $byGuid,
        Collection $byGstin,
        Collection $byName,
        Collection $ledgerCountsByGstin,
        Collection $dismissals,
    ): ?array {
        $proposed = VendorFromLedger::attributes($ledger);
        $gstin = $proposed['gstin'] !== null ? strtoupper($proposed['gstin']) : null;

        $matched = $byGuid->get($ledger->tally_guid);
        $basis = $matched !== null ? 'ledger_guid' : 'none';
        $ambiguousWith = [];

        if ($matched === null && $gstin !== null) {
            $candidates = $byGstin->get($gstin, collect());
            // Ambiguity from EITHER side counts: two ERP vendors carrying this
            // GSTIN, or two Tally ledgers carrying it. Both mean "this number
            // does not identify one party", and the second is the case the
            // live books actually contain.
            $ledgersWithGstin = (int) $ledgerCountsByGstin->get($ledger->gstin, 1);

            if ($candidates->count() === 1 && $ledgersWithGstin <= 1) {
                $matched = $candidates->first();
                $basis = 'gstin';
            } elseif ($candidates->count() >= 1) {
                $basis = 'gstin_ambiguous';
                $ambiguousWith = $candidates->map(fn (Vendor $v) => [
                    'vendor_id' => $v->id,
                    'code' => $v->code,
                    'name' => $v->name,
                ])->values()->all();
            }
        }

        $base = [
            'tally_ledger_guid' => $ledger->tally_guid,
            'ledger_name' => $ledger->name,
            'ledger_group' => $ledger->tally_group_name,
            'match_basis' => $basis,
            'source' => 'tally',
            'tally_synced_at' => optional($ledger->tally_synced_at)?->toIso8601String(),
        ];

        if ($basis === 'gstin_ambiguous') {
            // Not applicable, deliberately. The row exists so a person SEES
            // the collision; it carries no confirm action because there is no
            // safe one to offer. Dismissible as a whole, like any other
            // "this is not a new vendor" judgement.
            if ($this->isDismissed($dismissals, TallyVendorReviewDismissal::FIELD_ALL, null)) {
                return null;
            }

            return [...$base, 'kind' => 'ambiguous', 'proposed' => $proposed, 'ambiguous_with' => $ambiguousWith, 'differences' => []];
        }

        if ($matched === null) {
            if ($this->isDismissed($dismissals, TallyVendorReviewDismissal::FIELD_ALL, null)) {
                return null;
            }

            // A vendor of the same name from another source is NOT the same
            // row and is NOT merged silently — the person is told, and decides.
            $nameClash = $proposed['name'] !== null ? $byName->get(self::nameKey($proposed['name'])) : null;

            return [
                ...$base,
                'kind' => 'new',
                'proposed' => $proposed,
                'name_clash' => $nameClash !== null ? ['vendor_id' => $nameClash->id, 'code' => $nameClash->code, 'name' => $nameClash->name] : null,
                'differences' => [],
            ];
        }

        $differences = [];

        foreach (self::REVIEWABLE_FIELDS as $field) {
            $tallyValue = $proposed[$field] ?? null;

            // Tally silence is not an instruction. Where it carries nothing,
            // whatever the ERP holds stands.
            if ($tallyValue === null) {
                continue;
            }

            $current = VendorFromLedger::clean($matched->{$field});

            if ($current !== null && strcasecmp($current, $tallyValue) === 0) {
                continue;
            }

            if ($this->isDismissed($dismissals, $field, $tallyValue)) {
                continue;
            }

            $differences[] = ['field' => $field, 'current' => $current, 'proposed' => $tallyValue];
        }

        // A vendor matched by GSTIN that carries no Tally identity yet is
        // itself something to confirm — linking it is what makes every later
        // sync exact instead of a GSTIN guess.
        $linkable = $basis === 'gstin' && VendorFromLedger::clean($matched->tally_ledger_guid) === null;

        if ($differences === [] && ! $linkable) {
            return null;
        }

        return [
            ...$base,
            'kind' => 'conflict',
            'vendor' => ['vendor_id' => $matched->id, 'code' => $matched->code, 'name' => $matched->name],
            'proposed' => $proposed,
            'differences' => $differences,
            'links_identity' => $linkable,
        ];
    }

    /**
     * @param  Collection<int, TallyVendorReviewDismissal>  $dismissals
     */
    private function isDismissed(Collection $dismissals, string $field, ?string $value): bool
    {
        $row = $dismissals->firstWhere('field', $field);

        if ($row === null) {
            return false;
        }

        // Value-scoped: a dismissal covers the value it was made against and
        // nothing else, so a later, different value from Tally is raised again.
        return VendorFromLedger::clean($row->dismissed_value) === $value;
    }

    /**
     * Create the vendor a "new" row proposes.
     *
     * ONE SET OF RULES FOR THE ACT. The fields come from VendorFromLedger, the
     * same mapping ImportVendorsFromLedgers reads, and the code is minted by
     * VendorService exactly as it is for a person filling in the form. What
     * differs from the command is only WHO decided: there, an operator running
     * a dry run and then --write; here, an Owner/Accounts login pressing
     * confirm on a row it can see.
     *
     * NO ACTOR IS PASSED, and that is not an omission. Vendor carries
     * RecordsConfigurationAudit, which stamps the authenticated causer onto
     * the row and into the configuration activity log by itself. Taking an
     * actor here as well would invite the two to disagree about who did this.
     */
    public function confirmNew(string $ledgerGuid): Vendor
    {
        return DB::transaction(function () use ($ledgerGuid): Vendor {
            $ledger = $this->ledgerOrFail($ledgerGuid);

            $existing = Vendor::withTrashed()->where('tally_ledger_guid', $ledger->tally_guid)->first();

            if ($existing !== null) {
                throw new RuntimeException(sprintf('%s is already vendor %s.', $ledger->name, $existing->code));
            }

            $attributes = VendorFromLedger::attributes($ledger);

            if ($attributes['name'] === null) {
                throw new RuntimeException('This ledger has no usable name, so it cannot become a vendor.');
            }

            if (Vendor::withTrashed()->where('name', $attributes['name'])->exists()) {
                throw new RuntimeException(sprintf('A vendor named "%s" already exists. Resolve that first — two rows for one supplier is worse than a delay.', $attributes['name']));
            }

            $vendor = $this->vendors->create([...$attributes, 'address' => null, 'is_active' => true]);

            $this->linkIdentity($vendor, $ledger->tally_guid);

            return $vendor->refresh();
        });
    }

    /**
     * Apply the named differences to the matched vendor — and only those.
     *
     * A field not named is not touched, so a person may take the GSTIN and
     * leave the name alone. Each value is re-read from the mirror rather than
     * taken from the request, so what is written is what Tally says NOW, not
     * what the screen said when it was rendered.
     *
     * Who did it is recorded by RecordsConfigurationAudit on the vendor, from
     * the authenticated causer — see confirmNew().
     *
     * @param  list<string>  $fields
     */
    public function confirmFields(string $ledgerGuid, int $vendorId, array $fields): Vendor
    {
        return DB::transaction(function () use ($ledgerGuid, $vendorId, $fields): Vendor {
            $ledger = $this->ledgerOrFail($ledgerGuid);
            $vendor = Vendor::findOrFail($vendorId);

            $proposed = VendorFromLedger::attributes($ledger);
            $updates = [];

            foreach ($fields as $field) {
                if (! in_array($field, self::REVIEWABLE_FIELDS, true)) {
                    throw new RuntimeException(sprintf('"%s" is not a field this review can set.', $field));
                }

                $value = $proposed[$field] ?? null;

                // Refuses rather than clears. If Tally no longer carries what
                // the screen showed, the honest answer is to re-read the
                // queue, not to blank the vendor's field.
                if ($value === null) {
                    throw new RuntimeException(sprintf('Tally no longer carries a %s for %s. Reload the review before confirming.', str_replace('_', ' ', $field), $ledger->name));
                }

                // THE NAME IS THE ONE FIELD THAT CAN CREATE A DUPLICATE, and
                // the create path already refuses to. Without the same check
                // here the two halves of this screen disagree about the same
                // act: Tally renames a party onto a name the ERP already uses
                // — the live books hold exactly that shape, "Accurate
                // Industries" beside "Accurate Industries -Purchase" — and
                // confirming the rename would quietly make the second row for
                // one supplier that confirmNew() exists to prevent.
                // `vendors.name` is not unique, so nothing below would stop it.
                if ($field === 'name') {
                    $clash = Vendor::withTrashed()
                        ->whereRaw('LOWER(TRIM(name)) = ?', [self::nameKey($value)])
                        ->where('id', '!=', $vendor->id)
                        ->first();

                    if ($clash !== null) {
                        throw new RuntimeException(sprintf(
                            'Tally now calls this party "%s", which is already vendor %s. Resolve that first — two rows for one supplier is worse than a delay.',
                            $value,
                            $clash->code,
                        ));
                    }
                }

                $updates[$field] = $value;
            }

            if ($updates !== []) {
                $this->vendors->update($vendor, $updates);
            }

            // Confirming ANY difference on a GSTIN-matched vendor also records
            // which ledger it is, so the next sync matches exactly instead of
            // re-deriving the same guess.
            if (VendorFromLedger::clean($vendor->tally_ledger_guid) === null) {
                $this->linkIdentity($vendor, $ledger->tally_guid);
            }

            return $vendor->refresh();
        });
    }

    /** Set a difference (or a whole ledger) aside, against the value seen. */
    public function dismiss(string $ledgerGuid, string $field, User $actor): TallyVendorReviewDismissal
    {
        $ledger = $this->ledgerOrFail($ledgerGuid);

        if ($field !== TallyVendorReviewDismissal::FIELD_ALL && ! in_array($field, self::REVIEWABLE_FIELDS, true)) {
            throw new RuntimeException(sprintf('"%s" is not a field this review can dismiss.', $field));
        }

        $value = $field === TallyVendorReviewDismissal::FIELD_ALL
            ? null
            : (VendorFromLedger::attributes($ledger)[$field] ?? null);

        return TallyVendorReviewDismissal::updateOrCreate(
            ['tally_ledger_guid' => $ledger->tally_guid, 'field' => $field],
            ['dismissed_value' => $value, 'dismissed_by' => $actor->id, 'dismissed_at' => Carbon::now()],
        );
    }

    /**
     * What counts as the same vendor name — trimmed and case-folded.
     *
     * Spelled once because two places ask it: the queue, which warns that a
     * name is taken, and confirmFields(), which refuses to take it. A queue
     * that warned on a stricter rule than the writer enforced would show a
     * clash the confirm then allowed, or worse, the other way round.
     */
    private static function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function ledgerOrFail(string $guid): Ledger
    {
        $ledger = Ledger::where('tally_guid', $guid)->first();

        if ($ledger === null) {
            throw new RuntimeException('That Tally ledger is no longer in this database. Run a masters sync and reload the review.');
        }

        return $ledger;
    }

    /**
     * Record WHICH Tally ledger a vendor is.
     *
     * forceFill, not a mass assign: `tally_ledger_guid` is deliberately absent
     * from Vendor's #[Fillable] so no request, form or future
     * `Vendor::create([...$input])` can point a vendor at a different ledger.
     * That protection applies here too, hence the explicit fill — the same
     * reasoning, and the same mechanism, as ImportVendorsFromLedgers::writeLink.
     */
    private function linkIdentity(Vendor $vendor, string $guid): void
    {
        $vendor->forceFill(['tally_ledger_guid' => $guid])->save();
    }
}
