import { useEffect, useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Checkbox, DatePicker, Input, Modal, Select, Space, Table, Tag, Tooltip, Typography } from 'antd';
import dayjs from 'dayjs';
import { Link, useSearchParams } from 'react-router-dom';
import {
    dismissTallySyncEntry,
    getTallySyncSummary,
    listAllTallySyncEntries,
    releaseTallySyncEntry,
    requestTallySyncNow,
    retryTallySyncEntry,
} from '@/features/tally-sync/api';
import {
    holdCopy,
    instant,
    payloadText,
    statusColor,
    statusLabel,
    voucherDate,
    voucherNumber,
    showsFixedAfterFailures,
} from '@/features/tally-sync/drawer';
import EntryDrawer from '@/features/tally-sync/EntryDrawer';
import {
    businessDatePresets,
    catalogueNote,
    categoryFilterOptions,
    categoryLabel,
    hasActiveFilters,
    unclassifiedCount,
    voucherTypeFilterOptions,
} from '@/features/tally-sync/filters';
import {
    agentLiveness,
    agentLivenessLabel,
    canRequestSyncNow,
    SYNC_NOW_CONFIRM,
    syncNowMessage,
    type SyncNowMessage,
} from '@/features/tally-sync/syncNow';
import { useAuthStore } from '@/features/auth/store';
import type { TallySyncEntry, TallySyncEntryFilters, TallySyncStatus } from '@/features/tally-sync/types';

/** The status filter's choices — the same words the Status column uses. */
const statusOptions = (Object.keys(statusLabel) as TallySyncStatus[]).map((status) => ({
    value: status,
    label: statusLabel[status],
}));

/** The most recent of a set of ISO timestamps — all share the server's offset. */
function latest(values: (string | null | undefined)[]): string | null {
    const stamps = values.filter((value): value is string => typeof value === 'string' && value !== '');

    return stamps.length > 0 ? stamps.reduce((a, b) => (a > b ? a : b)) : null;
}

/**
 * What the API said when a resync was refused.
 *
 * The 422 from the retry guard is the useful answer, not an error to
 * genericise: "This voucher is already in Tally as SPE-42 — check Tally
 * before anything else." Shown word for word.
 */
function resyncMessage(error: unknown): string {
    const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        ?.response;

    return (
        response?.data?.errors?.entry?.[0]
        ?? response?.data?.message
        ?? 'Could not reach the ERP to resync. Check the connection and try again.'
    );
}

interface ResyncOutcome {
    ok: boolean;
    message: string;
}

export default function TallySyncPage() {
    const queryClient = useQueryClient();

    // The filter bar's state IS the query key: change a filter, the server
    // is asked again. Nothing here filters client-side, so the truncation
    // honesty below compares against the server's count for the SAME filters.
    const [filters, setFilters] = useState<TallySyncEntryFilters>({});
    const filtersActive = hasActiveFilters(filters);

    const { data, isLoading, isError, isFetching, refetch } = useQuery({
        queryKey: ['tally-sync', 'entries', 'all', filters],
        queryFn: () => listAllTallySyncEntries(filters),
    });

    // Today's counts, the catalogue and last agent contact — UNFILTERED on
    // purpose: "today: 1 failed" is a fact about today, not about the rows
    // the table is narrowed to. Keyed under ['tally-sync', 'entries', …] so
    // the existing invalidations after Resync / Dismiss / Release refresh
    // it too, without touching those actions.
    const {
        data: summary,
        isError: summaryError,
        refetch: refetchSummary,
    } = useQuery({
        queryKey: ['tally-sync', 'entries', 'summary'],
        queryFn: () => getTallySyncSummary(),
    });

    // The search box's text as typed; it only becomes the `q` filter on
    // Enter / the search button, so a half-typed voucher number does not
    // fire a request per keystroke at the factory box.
    const [qDraft, setQDraft] = useState('');

    function setFilter<K extends keyof TallySyncEntryFilters>(key: K, value: TallySyncEntryFilters[K]) {
        setFilters((prev) => ({ ...prev, [key]: value }));
    }

    function clearFilters() {
        setFilters({});
        setQDraft('');
    }

    // Keyed by entry id so the answer stays on screen next to the row it
    // belongs to — a toast that vanishes in three seconds is no use to
    // someone who has to go and look something up in Tally.
    const [outcomes, setOutcomes] = useState<Record<number, ResyncOutcome>>({});
    const [retryingId, setRetryingId] = useState<number | null>(null);
    const [releasingId, setReleasingId] = useState<number | null>(null);
    const [dismissingId, setDismissingId] = useState<number | null>(null);
    const [resyncingAll, setResyncingAll] = useState(false);
    const [report, setReport] = useState<{ id: number; voucher: string; ok: boolean; message: string }[] | null>(null);
    // "Sync Now" (DEC-20260825-002): whether a press is in flight, and the
    // honest sentence about the last one. Kept on the page — not a toast —
    // for the same reason the per-row outcomes are: the answer names what
    // is waiting and on whom, and it has to still be there when the person
    // looks back up from Tally.
    const [syncingNow, setSyncingNow] = useState(false);
    const [syncNowOutcome, setSyncNowOutcome] = useState<SyncNowMessage | null>(null);

    // Whether to DRAW the button. The server enforces the same rule and
    // 403s regardless (SyncNowAuthority) — this only decides whether a
    // login who may not press it is shown a control that would refuse them.
    const user = useAuthStore((state) => state.user);
    const maySyncNow = canRequestSyncNow(user);

    // A slow tick so "checked in 4 min ago" ages on screen instead of
    // freezing at whatever it said when the page last rendered. 30s is well
    // under the 5-minute stale boundary, so the light cannot sit green over
    // an agent that crossed it a while ago.
    const [clock, setClock] = useState(() => Date.now());
    useEffect(() => {
        const tick = setInterval(() => setClock(Date.now()), 30_000);

        return () => clearInterval(tick);
    }, []);
    // ?entry=41 — a sales document's Tally column (or any other page)
    // linking straight to one voucher. Read once, on load: the drawer opens
    // on that id whether or not the row is on this page — EntryDrawer asks
    // the show endpoint for it itself, and the list row paints first when
    // it is here. Closing the drawer drops the param so a refresh does not
    // reopen it. Without the param the page is unchanged.
    const [searchParams, setSearchParams] = useSearchParams();
    const entryParam = Number(searchParams.get('entry')) || null;
    // The drawer holds an ID, not a row: the row it shows is looked up from
    // the live list on every render, so a Resync / Dismiss / Release done
    // from the drawer's footer updates what the drawer says as soon as the
    // list refetches — no stale copy of the entry.
    const [viewingId, setViewingId] = useState<number | null>(entryParam);

    // A later ?entry= (Back to a link, a second link while this page is
    // mounted) opens that voucher too; the param going away closes nothing —
    // that is the drawer's own Close.
    useEffect(() => {
        if (entryParam !== null) setViewingId(entryParam);
    }, [entryParam]);

    function closeDrawer() {
        setViewingId(null);
        if (searchParams.has('entry')) {
            const next = new URLSearchParams(searchParams);
            next.delete('entry');
            setSearchParams(next, { replace: true });
        }
    }

    // Failed first, always. Everything on this page — the count in the red
    // strip, what "Resync all failed" picks up — reads the same order, so a
    // rejection can never be pushed below the fold by a day of successful
    // posts; dismissed sinks BELOW synced, being the one kind of row nobody
    // will ever act on. That order is the SERVER's (`sort=status_rank`, asked
    // for by listAllTallySyncEntries) and is deliberately not re-sorted here:
    // with server-side filters the rows are whatever the server matched, and
    // two sorts — one each side — is how the table and the counts drift apart.
    const entries = useMemo(() => data?.entries ?? [], [data]);

    const failed = useMemo(() => entries.filter((entry) => entry.status === 'failed'), [entries]);
    const pendingCount = entries.filter((entry) => entry.status === 'pending').length;

    // From the summary when it answers (it sees every entry regardless of
    // the filter bar); derived from the rows only when the summary is not
    // available AND the rows are unfiltered — a filtered subset's newest
    // synced_at is not "the last voucher Tally accepted", it is the last one
    // that happened to match.
    const rowsSpeakForAll = !filtersActive;
    const lastSynced = useMemo(
        () => summary?.last_synced_at ?? (rowsSpeakForAll ? latest(entries.map((entry) => entry.synced_at)) : null),
        [summary, rowsSpeakForAll, entries],
    );
    const lastCollected = useMemo(
        () => summary?.agent.last_action_at
            ?? (rowsSpeakForAll ? latest(entries.map((entry) => entry.delivered_at)) : null),
        [summary, rowsSpeakForAll, entries],
    );

    // The honesty line. If we are holding fewer rows than the server has for
    // THESE filters, the failure count below is a floor, not a total, and the
    // page must say so rather than hand out an all-clear it cannot stand behind.
    const total = data?.total ?? 0;
    const truncated = total > entries.length;

    // Failed vouchers the filter bar is hiding. The summary counts every
    // failed entry; the rows only the matching ones — the difference is what
    // a green "no failed vouchers" over a filtered table would be lying about.
    // Only computed when the page holds every matching row: on a truncated
    // page `failed.length` is a floor, so the subtraction would count rows
    // the page simply did not fetch as "outside these filters" — the
    // truncation copy above already says the page is not looking at them.
    const failedElsewhere = filtersActive && summary && !truncated
        ? Math.max(0, summary.all_time.failed - failed.length)
        : 0;

    // The names the table can never contain — the accountant's transactions
    // that live only in Tally, what is planned, what does not exist in the
    // books at all. Empty until the summary answers; never a zero, never a
    // row.
    const notMirrored = catalogueNote(summary?.by_category);

    // Rows the classifier could not place — a real row, measured, that no
    // category filter reaches unless `unknown` is offered. Zero is the
    // ordinary case and says nothing; anything else is said out loud.
    const unclassified = unclassifiedCount(summary?.by_category);

    const busy = retryingId !== null || releasingId !== null || dismissingId !== null || resyncingAll;

    /**
     * The all-clear, worded to cover exactly what was actually looked at.
     * Never shown when the fetch failed — see the error alert below: an empty
     * `entries` because the request 403'd looks identical to an empty queue,
     * and a green tick over the first of those is a lie the floor would act on.
     *
     * With a filter on, the rows are a subset by construction, so the copy
     * says "among these" and never claims the whole — and an empty result is
     * "nothing matches", NOT "nothing has been sent to Tally".
     */
    const allClear = filtersActive
        ? entries.length === 0
            ? 'No vouchers match these filters.'
            : `No failed vouchers among the ${entries.length}${truncated ? ' held here' : ''} matching these filters.`
        : entries.length === 0
            ? 'No vouchers queued yet — nothing has been sent to Tally so far.'
            : truncated
                ? `No failed vouchers among the ${entries.length} held here`
                : 'No failed vouchers — everything the ERP has sent, Tally has taken.';

    async function resyncOne(entry: TallySyncEntry) {
        setRetryingId(entry.id);
        try {
            await retryTallySyncEntry(entry.id);
            setOutcomes((prev) => ({
                ...prev,
                [entry.id]: { ok: true, message: 'Queued again — the agent will pick it up on its next check.' },
            }));
        } catch (error) {
            setOutcomes((prev) => ({ ...prev, [entry.id]: { ok: false, message: resyncMessage(error) } }));
        } finally {
            setRetryingId(null);
            await queryClient.invalidateQueries({ queryKey: ['tally-sync', 'entries'] });
        }
    }

    /**
     * The accountant's override on a held shift voucher: skip the rest of
     * the shift-end/quiet-period wait and let the agent collect it on its
     * next check. The 422 for "not actually held any more" is shown word
     * for word, same as the resync refusals.
     */
    async function releaseOne(entry: TallySyncEntry) {
        setReleasingId(entry.id);
        try {
            await releaseTallySyncEntry(entry.id);
            setOutcomes((prev) => ({
                ...prev,
                [entry.id]: { ok: true, message: 'Released — the agent will collect it on its next check.' },
            }));
        } catch (error) {
            setOutcomes((prev) => ({ ...prev, [entry.id]: { ok: false, message: resyncMessage(error) } }));
        } finally {
            setReleasingId(null);
            await queryClient.invalidateQueries({ queryKey: ['tally-sync', 'entries'] });
        }
    }

    /**
     * Write a failed voucher off for good. Behind a confirm because it is
     * the one action on this page that is meant to be final: "never sent to
     * Tally" is a promise the backend then enforces (a dismissed voucher is
     * invisible to the agent and refused by Resync), so the click deserves
     * a plain-words pause rather than a silent state change.
     */
    function confirmDismiss(entry: TallySyncEntry) {
        Modal.confirm({
            title: `Dismiss ${voucherNumber(entry)}?`,
            content:
                'Are you sure? This voucher will NEVER be sent to Tally. It stays in this list as history, '
                + 'but the agent can never collect it and Resync will refuse it. Use this for dead vouchers '
                + '(demo data, duplicates) that must not reach the books.',
            okText: 'Dismiss — never send',
            okButtonProps: { danger: true },
            cancelText: 'Keep it',
            onOk: () => dismissOne(entry),
        });
    }

    async function dismissOne(entry: TallySyncEntry) {
        setDismissingId(entry.id);
        try {
            await dismissTallySyncEntry(entry.id);
            setOutcomes((prev) => ({
                ...prev,
                [entry.id]: { ok: true, message: 'Dismissed — this voucher will never be sent to Tally.' },
            }));
        } catch (error) {
            setOutcomes((prev) => ({ ...prev, [entry.id]: { ok: false, message: resyncMessage(error) } }));
        } finally {
            setDismissingId(null);
            await queryClient.invalidateQueries({ queryKey: ['tally-sync', 'entries'] });
        }
    }

    /**
     * How alive the factory PC is, from its last check-in. `unavailable`
     * — not "never" — while the summary has not answered or failed: the
     * page does not know, and a red light over a measurement nobody made
     * is as wrong as a green one.
     */
    const liveness = agentLiveness(summary?.agent.last_checked_at, clock, !!summary && !summaryError);

    /**
     * "Sync Now" — DEC-20260825-002. Behind a confirm because the press is
     * an OUTWARD act: within about ninety seconds it can put vouchers into
     * the accountant's live books. The dialog says what it does, says that
     * it reads nothing from Tally, and — the part nobody can guess — says
     * that a shift still running will be sent as it stands, with later
     * approvals landing in a follow-up voucher.
     */
    function confirmSyncNow() {
        Modal.confirm({
            title: SYNC_NOW_CONFIRM.title,
            content: <div style={{ whiteSpace: 'pre-line' }}>{SYNC_NOW_CONFIRM.body}</div>,
            okText: SYNC_NOW_CONFIRM.ok,
            cancelText: SYNC_NOW_CONFIRM.cancel,
            onOk: () => syncNow(),
        });
    }

    async function syncNow() {
        setSyncingNow(true);
        setSyncNowOutcome(null);
        try {
            const result = await requestTallySyncNow();
            // Worded from the SERVER's own read of the agent at the moment
            // of the request, not from this page's cached summary — the
            // difference is exactly the case where the factory PC went off
            // since the last refresh. Never says "sent": nothing has
            // reached Tally yet, and the Status column is what will say so.
            setSyncNowOutcome(syncNowMessage(result, agentLiveness(result.agent.last_checked_at, Date.now())));
        } catch (error) {
            setSyncNowOutcome({ tone: 'warning', text: resyncMessage(error) });
        } finally {
            setSyncingNow(false);
            await queryClient.invalidateQueries({ queryKey: ['tally-sync', 'entries'] });
        }
    }

    /**
     * Retry every failed voucher, ONE AT A TIME.
     *
     * Three things this deliberately does not do: fire them in parallel (the
     * agent and Tally are one desktop machine at the factory, not a farm);
     * stop at the first refusal (a 422 on one voucher is a normal answer and
     * must not strand the other nine); or refetch between vouchers (the list
     * would re-sort underneath the loop). The target list is snapshotted
     * first and the cache is invalidated once, at the end.
     */
    async function resyncAllFailed() {
        const targets = failed;
        if (targets.length === 0) return;

        setResyncingAll(true);
        const results: { id: number; voucher: string; ok: boolean; message: string }[] = [];
        const collected: Record<number, ResyncOutcome> = {};

        for (const entry of targets) {
            setRetryingId(entry.id);
            try {
                await retryTallySyncEntry(entry.id);
                const message = 'Queued again — the agent will pick it up on its next check.';
                collected[entry.id] = { ok: true, message };
                results.push({ id: entry.id, voucher: voucherNumber(entry), ok: true, message });
            } catch (error) {
                const message = resyncMessage(error);
                collected[entry.id] = { ok: false, message };
                results.push({ id: entry.id, voucher: voucherNumber(entry), ok: false, message });
            }
        }

        setRetryingId(null);
        setOutcomes((prev) => ({ ...prev, ...collected }));
        setReport(results);
        setResyncingAll(false);
        await queryClient.invalidateQueries({ queryKey: ['tally-sync', 'entries'] });
    }

    return (
        <>
            {/* Failed rows carry a red wash as well as a red tag — scanned from
                across the room, colour is what gets noticed, not wording. The
                hold and failed colours are the ones the floor already reads;
                nothing below changes them.

                Under 768px the header and the filter bar stop sitting side by
                side and stack full-width: on a phone in the office, controls
                squeezed into a 90px column are unusable, and this page is one
                an accountant does open on a phone to check whether a voucher
                went. */}
            <style>{`
                .tally-sync-failed-row > td { background: #fff1f0 !important; }
                .tally-sync-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
                .tally-sync-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
                @media (max-width: 768px) {
                    .tally-sync-header, .tally-sync-actions { flex-direction: column; align-items: stretch; }
                    .tally-sync-actions > * { width: 100%; }
                    .tally-sync-filters > .ant-space-item { width: 100%; }
                    .tally-sync-filters > .ant-space-item > * { width: 100% !important; }
                }
            `}</style>

            <div className="tally-sync-header">
                <Typography.Title level={3} style={{ marginBottom: 8 }}>
                    Tally Sync
                </Typography.Title>
                <div className="tally-sync-actions">
                    {/* The freshness of the factory PC, beside the control
                        that reloads this page — compact, because it is a
                        header, and worded so "never checked in" and "we
                        could not find out" never read as each other. Says
                        nothing about which token or what it may do. */}
                    <Tooltip
                        title={
                            liveness.state === 'stale'
                                ? 'Agent offline'
                                : liveness.state === 'never'
                                    ? 'Agent has never run'
                                    : liveness.state === 'unavailable'
                                        ? 'Agent status unknown'
                                        : ''
                        }
                    >
                        <Typography.Text
                            type={liveness.state === 'fresh' ? 'secondary' : liveness.state === 'stale' ? 'warning' : 'secondary'}
                            style={{ fontSize: 12, whiteSpace: 'nowrap' }}
                        >
                            {agentLivenessLabel(liveness)}
                        </Typography.Text>
                    </Tooltip>
                    <Button onClick={() => Promise.all([refetch(), refetchSummary()])} loading={isFetching && !busy}>
                        Refresh
                    </Button>
                    {/* Owner/Accounts only. Absent — not disabled — for
                        everyone else: a greyed control invites a question
                        nobody on the floor can answer. The server refuses
                        the request either way. */}
                    {maySyncNow && (
                        <Tooltip title="Post queued vouchers now">
                            <Button type="primary" loading={syncingNow} disabled={busy} onClick={confirmSyncNow}>
                                Sync Now
                            </Button>
                        </Tooltip>
                    )}
                </div>
            </div>

            {syncNowOutcome && (
                <Alert
                    type={syncNowOutcome.tone}
                    showIcon
                    closable
                    onClose={() => setSyncNowOutcome(null)}
                    style={{ marginBottom: 16 }}
                    message={syncNowOutcome.text}
                />
            )}

            {isError && (
                <Alert
                    type="error"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={<strong>Queue unavailable</strong>}
                    description={
                        <Space direction="vertical" size={8} style={{ width: '100%' }}>
                            <Button danger type="primary" loading={isFetching} onClick={() => refetch()}>
                                Retry
                            </Button>
                        </Space>
                    }
                />
            )}

            {failed.length > 0 && (
                <Alert
                    type="error"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={
                        <strong>
                            {failed.length === 1
                                ? '1 failed — not in Tally'
                                : `${failed.length} failed — not in Tally`}
                        </strong>
                    }
                    description={
                        <Space direction="vertical" size={8} style={{ width: '100%' }}>
                            <span>Open row → Fix → Resync.</span>
                            {/* Numbers, not prose, but they stay: without them
                                the count above reads as a total when it is a
                                floor. */}
                            {truncated && (
                                <span>
                                    Showing {entries.length} of {total}{filtersActive ? ' matching' : ''} — rest
                                    not fetched.
                                </span>
                            )}
                            {failedElsewhere > 0 && (
                                <span>
                                    {failedElsewhere} more failed outside filters.
                                </span>
                            )}
                            <Button danger type="primary" loading={resyncingAll} onClick={resyncAllFailed}>
                                Resync all {failed.length} failed
                            </Button>
                        </Space>
                    }
                />
            )}

            {failed.length === 0 && !isLoading && !isError && (
                <Alert
                    // Green only for a real all-clear: unfiltered AND
                    // complete. A filtered "no failures here" is information,
                    // not reassurance.
                    type={truncated || filtersActive ? 'info' : 'success'}
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={allClear}
                    description={
                        <>
                            {/* An all-clear is only honest if it covers everything.
                                When it doesn't, say so rather than let the green
                                tick vouch for vouchers nobody looked at. */}
                            {truncated && (
                                <div>
                                    This page is holding {entries.length} of {total}
                                    {filtersActive ? ' matching' : ''} vouchers (all failed and pending first) —
                                    the rest were not fetched.
                                </div>
                            )}
                            {failedElsewhere > 0 && (
                                <div>
                                    {failedElsewhere} failed voucher{failedElsewhere === 1 ? '' : 's'} exist outside
                                    these filters — clear them to see every failure.
                                </div>
                            )}
                            {pendingCount > 0 && (
                                <div>
                                    {pendingCount} voucher{pendingCount === 1 ? '' : 's'} still waiting for the agent to
                                    collect.
                                </div>
                            )}
                        </>
                    }
                />
            )}

            <Typography.Paragraph type="secondary">
                Vouchers reach Tally through the desktop agent at the factory — nothing here talks to Tally directly.
                If nothing is syncing, that machine needs to be switched on with Tally open on the right company.
                <br />
                {/* Today's picture, whatever the filter bar says — the one line
                    on this page that always covers the whole queue. Read
                    from /summary in the factory's day, not the browser's. */}
                {summary ? (
                    <span>
                        Today ({summary.today.date}): <strong>{summary.today.total}</strong> voucher
                        {summary.today.total === 1 ? '' : 's'}
                        {' · '}<strong>{summary.today.synced}</strong> in Tally
                        {' · '}<strong>{summary.today.pending}</strong> waiting
                        {' · '}
                        <Typography.Text type={summary.today.failed > 0 ? 'danger' : 'secondary'} strong>
                            {summary.today.failed} failed
                        </Typography.Text>
                        {/* held_now, not today.held: the night shift's voucher is
                            dated yesterday and held until 06:00 — a state, not a
                            window, and "0 held" over it every night was a lie. */}
                        {' · '}<strong>{summary.held_now}</strong> held now
                        {unclassified > 0 && (
                            <>
                                {' · '}
                                <Typography.Text type="warning" strong>
                                    {unclassified} unclassified
                                </Typography.Text>
                            </>
                        )}
                        <br />
                    </span>
                ) : summaryError ? (
                    // Said out loud rather than left blank: an absent line
                    // reads as "nothing today", which is a claim.
                    <span>
                        Today&apos;s counts could not be read.
                        <br />
                    </span>
                ) : null}
                {isError && !summary ? (
                    // "—" here would read as "the agent has never delivered
                    // anything", which is a different and much more alarming
                    // claim than "this page could not find out".
                    <span>Last delivery time unknown — the queue could not be read.</span>
                ) : (
                    <>
                        Last voucher accepted by Tally: <strong>{instant(lastSynced)}</strong>
                        {' · '}
                        {/* "Action", not "contact": a heartbeat poll that finds
                            nothing to deliver records no event, so an idle agent
                            can be alive and polling while this stands still. */}
                        {summary?.agent.last_action_at ? 'Agent last action' : 'Agent last collected work'}:{' '}
                        <strong>{instant(lastCollected)}</strong>
                        {summary?.agent.last_action_label && (
                            <Typography.Text type="secondary"> ({summary.agent.last_action_label})</Typography.Text>
                        )}
                    </>
                )}
            </Typography.Paragraph>

            {/* The filter bar. Every control writes straight into `filters`,
                which is the query key — the server does the narrowing. */}
            <Space wrap className="tally-sync-filters" style={{ marginBottom: 12 }}>
                <Select<TallySyncStatus[]>
                    mode="multiple"
                    allowClear
                    placeholder="Any status"
                    style={{ minWidth: 200 }}
                    options={statusOptions}
                    value={filters.status ?? []}
                    onChange={(value) => setFilter('status', value.length > 0 ? value : undefined)}
                />
                <Select<string[]>
                    mode="multiple"
                    allowClear
                    showSearch
                    optionFilterProp="label"
                    placeholder="Any category"
                    style={{ minWidth: 260 }}
                    // Only the categories that can have an entry — the ones
                    // the ERP builds, plus `unknown` (see categoryFilterOptions).
                    options={categoryFilterOptions(summary?.by_category)}
                    value={filters.category ?? []}
                    onChange={(value) => setFilter('category', value.length > 0 ? value : undefined)}
                />
                {/* The voucher type as the QUEUE labels it (the raw
                    tally_voucher_type the server filter matches), offered
                    from the types the queue actually holds. A type filter
                    and nothing else: no party, no supplier, no rate, no
                    amount and no payload is filterable or enumerable from
                    this bar — those are FC-06 questions. */}
                <Select<string[]>
                    mode="multiple"
                    allowClear
                    showSearch
                    optionFilterProp="label"
                    placeholder="Any voucher type"
                    style={{ minWidth: 220 }}
                    options={voucherTypeFilterOptions(summary?.voucher_types)}
                    value={filters.voucher_type ?? []}
                    onChange={(value) => setFilter('voucher_type', value.length > 0 ? value : undefined)}
                />
                <DatePicker.RangePicker
                    allowEmpty={[true, true]}
                    placeholder={['Voucher date from', 'to']}
                    // Today / Yesterday / Last 7 days, computed from the
                    // FACTORY's day (the same date the counts above are
                    // bucketed by), never the browser's — at 00:30 IST
                    // those are different dates and the presets would
                    // silently disagree with the header.
                    presets={businessDatePresets(summary?.today.date).map((preset) => ({
                        label: preset.label,
                        value: [dayjs(preset.from), dayjs(preset.to)] as [dayjs.Dayjs, dayjs.Dayjs],
                    }))}
                    value={[filters.from ? dayjs(filters.from) : null, filters.to ? dayjs(filters.to) : null]}
                    onChange={(_, dateStrings) => {
                        // Business dates (the voucher's date in Tally), not
                        // when the row was queued — that is what an
                        // accountant reconciling a day is asking about.
                        const [from, to] = dateStrings;
                        setFilters((prev) => ({ ...prev, from: from || undefined, to: to || undefined }));
                    }}
                />
                <Input.Search
                    allowClear
                    placeholder="Voucher no., party or batch"
                    style={{ width: 240 }}
                    value={qDraft}
                    onChange={(event) => setQDraft(event.target.value)}
                    onSearch={(value) => setFilter('q', value.trim() || undefined)}
                />
                <Checkbox
                    checked={filters.held === true}
                    onChange={(event) => setFilter('held', event.target.checked || undefined)}
                >
                    Held only
                </Checkbox>
                {filtersActive && (
                    <Button size="small" onClick={clearFilters}>
                        Clear filters
                    </Button>
                )}
            </Space>

            <Table<TallySyncEntry>
                // max-content so no column is squeezed to an unreadable
                // width; the header sticks so the column names stay put
                // while an accountant scrolls a long day's queue.
                scroll={{ x: 'max-content' }}
                sticky
                size="middle"
                rowKey="id"
                loading={isLoading}
                dataSource={entries}
                pagination={{ defaultPageSize: 25, showSizeChanger: true }}
                rowClassName={(row) => (row.status === 'failed' ? 'tally-sync-failed-row' : '')}
                columns={[
                    {
                        title: 'Voucher',
                        render: (_, row) => (
                            <Space direction="vertical" size={0}>
                                <strong>{voucherNumber(row)}</strong>
                                <Typography.Text type="secondary">{row.tally_voucher_type}</Typography.Text>
                                {/* The agent's builder for this type says, in
                                    its own docblock, that it is unvalidated
                                    (Sales, DEC-20260809-003) — said on the
                                    row, in the backend's words on hover. */}
                                {row.flags?.unvalidated_builder && (
                                    <Tooltip title={row.flags.unvalidated_builder.note}>
                                        <Tag color="warning" style={{ marginTop: 2 }}>unvalidated builder</Tag>
                                    </Tooltip>
                                )}
                            </Space>
                        ),
                    },
                    {
                        // THE VOUCHER'S OWN DATE — the day this document
                        // carries in Tally's books, which is the day an
                        // accountant reconciles by. Rendered from the
                        // string, never through a Date: "2026-07-23" is the
                        // 23rd everywhere, and parsing it as an instant
                        // shows a viewer west of Greenwich the 22nd.
                        title: 'Voucher date',
                        render: (_, row) => (
                            <span style={{ whiteSpace: 'nowrap' }}>{voucherDate(row.business_date)}</span>
                        ),
                    },
                    {
                        title: 'Category',
                        render: (_, row) => (
                            // categoryLabel() adds "· posts as Stock Journal"
                            // where the ERP's label is not what Tally receives
                            // — the row must never send anyone looking for a
                            // voucher type the books do not contain.
                            <div style={{ maxWidth: 260, whiteSpace: 'normal' }}>{categoryLabel(row.category)}</div>
                        ),
                    },
                    {
                        title: 'Batch / Source',
                        render: (_, row) => {
                            const batch = payloadText(row, 'batch_number');
                            const shift = payloadText(row, 'shift');

                            return (
                                <Space direction="vertical" size={0}>
                                    {batch && <span>Batch {batch}</span>}
                                    {!batch && shift && <span>{shift} shift</span>}
                                    <Typography.Text type="secondary">
                                        {row.syncable_type} #{row.syncable_id}
                                    </Typography.Text>
                                    <Link to="/production/shift-production">Open production entries</Link>
                                </Space>
                            );
                        },
                    },
                    {
                        title: 'Status',
                        render: (_, row) =>
                            row.hold ? (
                                // A held shift voucher is deliberately not with
                                // the agent yet (DEC-20260807-011) — different
                                // claim from "waiting for agent", which reads
                                // as "the factory machine should have taken
                                // this already".
                                <Space direction="vertical" size={0}>
                                    <Tag color="blue">{holdCopy(row.hold).tag}</Tag>
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        {holdCopy(row.hold).detail}
                                    </Typography.Text>
                                </Space>
                            ) : (
                                <Tag color={statusColor[row.status]}>{statusLabel[row.status]}</Tag>
                            ),
                    },
                    {
                        title: 'Tries',
                        dataIndex: 'attempts',
                        align: 'right',
                    },
                    {
                        title: 'What Tally said',
                        render: (_, row) => {
                            const outcome = outcomes[row.id];

                            return (
                                <div style={{ maxWidth: 380, whiteSpace: 'normal' }}>
                                    {row.error_message ? (
                                        <>
                                            <Typography.Text type="danger">{row.error_message}</Typography.Text>
                                            {row.fix && (
                                                <div style={{ marginTop: 4 }}>
                                                    <Typography.Text style={{ fontSize: 12 }}>
                                                        {row.fix.sentence}{' '}
                                                        <Link to={row.fix.path}>Open the fix</Link>
                                                    </Typography.Text>
                                                </div>
                                            )}
                                        </>
                                    ) : row.error_withheld ? (
                                        // Withheld, not absent — the row DID fail; a bare "—" here
                                        // would read as "no error" (FC-06, second half).
                                        <Typography.Text type="warning">{row.error_withheld}</Typography.Text>
                                    ) : (
                                        <Typography.Text type="secondary">—</Typography.Text>
                                    )}
                                    {showsFixedAfterFailures(row) && (
                                        <Typography.Text type="success" style={{ display: 'block', fontSize: 12 }}>
                                            Fixed after {row.resolution_log!.length} failed attempt{row.resolution_log!.length > 1 ? 's' : ''} — payload regenerated from current mappings.
                                        </Typography.Text>
                                    )}
                                    {outcome && (
                                        <div style={{ marginTop: 4 }}>
                                            <Typography.Text type={outcome.ok ? 'success' : 'warning'}>
                                                {outcome.message}
                                            </Typography.Text>
                                        </div>
                                    )}
                                </div>
                            );
                        },
                    },
                    {
                        // WHERE THIS VOUCHER HAS GOT TO, scannable down the
                        // column: the FURTHEST point it has reached reads
                        // first and in full weight, so an eye running down
                        // the page sorts "In Tally" from "Collected" from
                        // "Not collected" without reading a word twice.
                        //
                        // Nothing is hidden to achieve that. Queued is still
                        // there — smaller and muted, because it is the one
                        // stamp every row has and so the one that
                        // distinguishes nothing — and a voucher that reached
                        // Tally still shows when the agent collected it,
                        // which is the pair a person needs when a post took
                        // a suspiciously long time. Both stay on one line
                        // each so the column cannot reflow into a paragraph.
                        title: 'Last activity',
                        render: (_, row) => (
                            <Space direction="vertical" size={0} style={{ whiteSpace: 'nowrap' }}>
                                {row.synced_at && <span><strong>In Tally</strong> {instant(row.synced_at)}</span>}
                                {!row.synced_at && row.delivered_at && (
                                    <Tooltip title="The agent has taken this voucher but has not reported back yet.">
                                        <span><strong>Collected</strong> {instant(row.delivered_at)}</span>
                                    </Tooltip>
                                )}
                                {!row.synced_at && !row.delivered_at && (
                                    <Typography.Text type="secondary"><strong>Not collected yet</strong></Typography.Text>
                                )}
                                {row.synced_at && row.delivered_at && (
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        Collected {instant(row.delivered_at)}
                                    </Typography.Text>
                                )}
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    Queued {instant(row.created_at)}
                                </Typography.Text>
                            </Space>
                        ),
                    },
                    {
                        // Pinned right so the buttons are reachable on a
                        // 375px phone without scrolling the whole table
                        // across first — this column is the reason someone
                        // opens the page on a phone at all.
                        title: 'Actions',
                        fixed: 'right',
                        render: (_, row) => {
                            const view = (
                                <Button size="small" onClick={() => setViewingId(row.id)}>
                                    View
                                </Button>
                            );

                            if (row.hold) {
                                return (
                                    <Space>
                                        {view}
                                        <Tooltip title="Send shift now (skip hold)">
                                            <Button
                                                size="small"
                                                loading={releasingId === row.id}
                                                disabled={busy && releasingId !== row.id}
                                                onClick={() => releaseOne(row)}
                                            >
                                                Release now
                                            </Button>
                                        </Tooltip>
                                    </Space>
                                );
                            }

                            return (
                                <Space>
                                    {view}
                                    {row.status === 'failed' && (
                                        <Button
                                            danger
                                            size="small"
                                            loading={retryingId === row.id}
                                            disabled={busy && retryingId !== row.id}
                                            onClick={() => resyncOne(row)}
                                        >
                                            Resync
                                        </Button>
                                    )}
                                    {row.status === 'failed' && (
                                        <Tooltip title="Write this voucher off — it will never be sent to Tally. For dead vouchers (demo data) that must not reach the books.">
                                            <Button
                                                size="small"
                                                loading={dismissingId === row.id}
                                                disabled={busy && dismissingId !== row.id}
                                                onClick={() => confirmDismiss(row)}
                                            >
                                                Dismiss
                                            </Button>
                                        </Tooltip>
                                    )}
                                </Space>
                            );
                        },
                    },
                ]}
                expandable={{
                    expandedRowRender: (row) => (
                        <>
                            <Typography.Text type="secondary">
                                Exactly what the agent was given to post:
                            </Typography.Text>
                            <pre style={{ margin: 0, whiteSpace: 'pre-wrap' }}>{JSON.stringify(row.payload, null, 2)}</pre>
                        </>
                    ),
                }}
            />

            {/* The honesty line: what this table can NEVER show. These are
                the accountant's own transactions — never counted here, never
                mirrored, and an empty table above must not imply they do not
                exist. Text only; a zero would claim a measurement. */}
            {notMirrored.length > 0 && (
                <Typography.Paragraph type="secondary" style={{ marginTop: 12 }}>
                    {notMirrored.map((clause, index) => (
                        <span key={clause}>
                            {index > 0 && <br />}
                            {clause}
                        </span>
                    ))}
                </Typography.Paragraph>
            )}

            <EntryDrawer
                entryId={viewingId}
                listRow={viewingId === null ? null : entries.find((entry) => entry.id === viewingId) ?? null}
                outcome={viewingId === null ? null : outcomes[viewingId] ?? null}
                onClose={closeDrawer}
                busy={busy}
                retryingId={retryingId}
                releasingId={releasingId}
                dismissingId={dismissingId}
                onResync={resyncOne}
                onRelease={releaseOne}
                onDismiss={confirmDismiss}
            />

            <Modal
                open={report !== null}
                title="Resync results"
                onCancel={() => setReport(null)}
                onOk={() => setReport(null)}
                cancelButtonProps={{ style: { display: 'none' } }}
            >
                <Space direction="vertical" size={12} style={{ width: '100%' }}>
                    {(report ?? []).map((result) => (
                        <div key={result.id}>
                            <Tag color={result.ok ? 'green' : 'red'}>{result.voucher}</Tag>
                            <div>{result.message}</div>
                        </div>
                    ))}
                </Space>
            </Modal>
        </>
    );
}
