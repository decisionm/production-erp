<?php

namespace App\Modules\TallySync\Services;

use App\Modules\TallySync\Models\Ledger;
use Illuminate\Support\Collection;

/**
 * THE READ DOOR ONTO THE LEDGER MIRROR for modules that are not TallySync.
 *
 * Procurement has to see what Tally holds about a party in order to review it
 * against the vendor master, and CLAUDE.md's module rule says it reaches that
 * through this module's service rather than through its Eloquent models. This
 * class is that service, and it is read-only on purpose: the mirror has
 * exactly one writer (LedgerSyncService, from an agent pull) and giving a
 * second module a write path onto it would be the rival-systems mistake.
 */
class LedgerDirectory
{
    /**
     * The census of Tally ledger groups present in the mirror, with a count
     * each — what the owner picks the vendor-source groups from.
     *
     * The pick has to be an OWNER ACT and cannot be a filter an agent writes:
     * this factory's Sundry Creditors group holds an INTEREST ledger whose
     * name differs from a real supplier's by two letters, and the company's
     * OWN second GST registration, both sitting among the parties. That is
     * measured (28-Aug voucher exports), not supposed, and it is why
     * ImportVendorsFromLedgers demands an explicit allow-list too.
     *
     * @return Collection<string, int> group name => ledger count
     */
    public function groupCensus(): Collection
    {
        return Ledger::query()
            ->whereNotNull('tally_group_name')
            ->selectRaw('tally_group_name as grp, COUNT(*) as n')
            ->groupBy('grp')
            ->orderByDesc('n')
            ->pluck('n', 'grp');
    }

    /**
     * Every mirrored ledger in the named groups, plus every ledger already
     * linked to a vendor whatever its group.
     *
     * THE SECOND HALF IS NOT A CONVENIENCE. A vendor that exists is a vendor
     * whose details changing matters, and a ledger can be moved between groups
     * in Tally. Selecting on the allow-list alone would make a party silently
     * stop being watched the moment somebody re-filed it — the difference
     * would not be "resolved", it would be unseen.
     *
     * @param  list<string>  $groups
     * @param  list<string>  $alsoGuids  ledger GUIDs to include regardless of group
     * @return Collection<int, Ledger>
     */
    public function partyLedgers(array $groups, array $alsoGuids = []): Collection
    {
        $groups = array_values(array_filter(array_map('trim', $groups), fn (string $g) => $g !== ''));
        $alsoGuids = array_values(array_filter($alsoGuids));

        if ($groups === [] && $alsoGuids === []) {
            return collect();
        }

        return Ledger::query()
            ->where(function ($query) use ($groups, $alsoGuids): void {
                if ($groups !== []) {
                    $query->whereIn('tally_group_name', $groups);
                }
                if ($alsoGuids !== []) {
                    $query->orWhereIn('tally_guid', $alsoGuids);
                }
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * How many mirrored ledgers carry each of these GSTINs.
     *
     * MEASURED AND NOT OPTIONAL. In the live company's All Masters export, 23
     * GSTINs appear on MORE THAN ONE ledger — including two Sundry Creditors
     * that share one ("Accurate Industries" and its purchase twin). A GSTIN is
     * therefore not a unique identity in these books, and any match made on
     * one has to be able to say so rather than pick a side.
     *
     * @param  list<string>  $gstins
     * @return Collection<string, int>
     */
    public function ledgerCountsByGstin(array $gstins): Collection
    {
        $gstins = array_values(array_filter(array_map('trim', $gstins), fn (string $g) => $g !== ''));

        if ($gstins === []) {
            return collect();
        }

        return Ledger::query()
            ->whereIn('gstin', $gstins)
            ->selectRaw('gstin, COUNT(*) as n')
            ->groupBy('gstin')
            ->pluck('n', 'gstin');
    }
}
