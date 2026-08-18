/**
 * The SNAPSHOT JOURNAL — a post-Tally snapshot the cloud would not take,
 * written down and re-sent later — as a pure module over an injected store.
 *
 * WHY THIS EXISTS (Phase 7 hardening). sendSnapshot() runs AFTER the post's
 * own ack/fail path and swallows every failure so a snapshot can never turn a
 * synced voucher into a failed one. The cost of that rule, until now, was
 * that a snapshot whose upload failed was simply gone: the agent posted to
 * Tally, Tally answered, and if the cloud was down at that moment (a deploy's
 * maintenance window answers 503 on every route — the same window that made
 * the post journal necessary, issue #168) the record of WHAT was sent and
 * WHAT Tally said never reached the drawer. The post itself was already
 * journalled so its ack/fail could be said again; the snapshot was not.
 *
 * WHAT IT DOES. A failed upload's exact body is queued (FIFO, on disk via
 * snapshotJournal.ts) and the queue is flushed at the start of a later sync
 * cycle — deliberately AFTER /pending has answered, so attempts are only
 * spent while the cloud is demonstrably up: an outage of any length costs
 * nothing, and the bound below guards against a snapshot the cloud keeps
 * refusing while it is otherwise fine. The queue itself is capped so a long
 * outage cannot fill the disk; over the cap the OLDEST record is dropped and
 * that is logged, like every give-up.
 *
 * WHAT IT DOES NOT DO. Nothing here reads the entry's status, touches the
 * post journal, or changes what is posted, acked or reported. A re-sent
 * snapshot is byte-identical to the one that failed — same sha256, same
 * `attempt` ordinal, same Tally block — so the cloud attributes it to the
 * post it describes (its idempotency is keyed on entry + sha256 + attempt).
 *
 * WHY ITS OWN MODULE WITH AN INJECTED STORE. sync.ts pulls in axios and
 * electron-store, so requiring it from a test downloads an Electron binary.
 * Every runtime import here is a node built-in and every other import is
 * type-only, so dist/snapshotQueue.js requires with zero node_modules — the
 * same reasoning as snapshot.ts and postDecision.ts — and the "never throws"
 * and "bounded" properties are provable without a disk or a network.
 */
import type { SnapshotBody } from './snapshot';

/**
 * Upload attempts per queued snapshot, the original included, before the
 * agent gives up on it. Attempts are only spent while the cloud is answering
 * (see the header), so this is "how many polls may a reachable cloud refuse
 * this one record" — not an outage budget. ~35 minutes at the default 90 s.
 */
export const SNAPSHOT_RETRY_MAX_ATTEMPTS = 24;

/** Records held at once. Over it the oldest is dropped (and logged). */
export const SNAPSHOT_QUEUE_CAP = 50;

export interface QueuedSnapshot {
    entryId: number;
    /** The exact wire body that failed to upload — re-sent verbatim. */
    body: SnapshotBody;
    /** Upload attempts made so far, the original one included (>= 1). */
    attempts: number;
    /** ISO time of the first failed upload. */
    queuedAt: string;
    lastError: string | null;
}

/** The persistence the app injects (snapshotJournal.ts) — a fake in tests. */
export interface SnapshotQueueStore {
    load(): QueuedSnapshot[];
    save(items: QueuedSnapshot[]): void;
}

export interface SnapshotQueueLog {
    warn: (message: string, meta?: Record<string, unknown>) => void;
    info?: (message: string, meta?: Record<string, unknown>) => void;
    error?: (message: string, meta?: Record<string, unknown>) => void;
}

export interface FlushDeps extends SnapshotQueueLog {
    /** The cloud call — cloudApi.uploadSnapshot in the app, a fake in tests. */
    upload: (entryId: number, body: SnapshotBody) => Promise<void>;
}

/** Entry ids by what happened to their queued snapshot this flush. */
export interface FlushResult {
    sent: number[];
    kept: number[];
    dropped: number[];
}

const errorText = (err: unknown): string => (err instanceof Error ? err.message : String(err));

/** A logger fault is never allowed to become the queue's fault. */
function quietly(fn: (() => void) | undefined): void {
    try {
        fn?.();
    } catch {
        // ignore — the log line did not land; the journal is what matters
    }
}

/** Never throws: an unreadable store reads as empty (and is warned about). */
function loadOrEmpty(store: SnapshotQueueStore, log: SnapshotQueueLog): QueuedSnapshot[] | null {
    try {
        const items = store.load();

        return Array.isArray(items) ? items : [];
    } catch (err) {
        quietly(() => log.warn('Snapshot journal could not be read — nothing re-sent this poll', { message: errorText(err) }));

        return null;
    }
}

/**
 * Write a failed upload down. Called by sendSnapshot on the failure path,
 * BEFORE it returns false. Returns whether the record is now in the store —
 * false when the store itself failed, which is warned about and swallowed:
 * a journal that cannot be written must not become a thrown error either.
 *
 * The same post queued twice (same entry, same sha256, same attempt) is one
 * record, refreshed; a different post of the same entry (a dashboard Retry →
 * a new attempt ordinal) is its own record.
 */
export function enqueueSnapshot(
    store: SnapshotQueueStore,
    entryId: number,
    body: SnapshotBody,
    error: string,
    log: SnapshotQueueLog,
    now: () => Date = () => new Date(),
): boolean {
    try {
        const items = loadOrEmpty(store, log);
        if (items === null) {
            return false;
        }

        const samePost = (item: QueuedSnapshot): boolean =>
            item.entryId === entryId && item.body?.xml_sha256 === body.xml_sha256 && item.body?.attempt === body.attempt;
        const existing = items.find(samePost);

        const kept = items.filter((item) => !samePost(item));
        kept.push({
            entryId,
            body,
            attempts: (existing?.attempts ?? 0) + 1,
            queuedAt: existing?.queuedAt ?? now().toISOString(),
            lastError: error,
        });

        while (kept.length > SNAPSHOT_QUEUE_CAP) {
            const oldest = kept.shift() as QueuedSnapshot;
            quietly(() =>
                log.error?.(
                    `Snapshot journal is full (${SNAPSHOT_QUEUE_CAP}) — giving up on the oldest, entry #${oldest.entryId}: `
                        + 'the cloud will have no copy of the XML for that post. The post outcome itself is unaffected.',
                    {
                        sha256: oldest.body?.xml_sha256?.slice(0, 12) ?? null,
                        attempts: oldest.attempts,
                        queuedAt: oldest.queuedAt,
                        lastError: oldest.lastError,
                    },
                ),
            );
        }

        store.save(kept);

        return true;
    } catch (err) {
        quietly(() =>
            log.warn(`Snapshot for entry #${entryId} could not be journalled — the cloud will have no copy of the XML`, {
                message: errorText(err),
            }),
        );

        return false;
    }
}

/**
 * Re-send what is queued, oldest first, once per cycle. Never throws.
 *
 * Stops at the FIRST failure: if the cloud went away again between /pending
 * and now, every later record would only eat its own timeout, and none of
 * them is urgent — they wait for the next poll with their attempts intact.
 * The failed one is kept with attempts+1, or dropped with an error line once
 * it reaches SNAPSHOT_RETRY_MAX_ATTEMPTS. The store is saved after every
 * record so a crash mid-flush re-sends at most the one in flight.
 */
export async function flushSnapshotQueue(store: SnapshotQueueStore, deps: FlushDeps): Promise<FlushResult> {
    const result: FlushResult = { sent: [], kept: [], dropped: [] };

    const items = loadOrEmpty(store, deps);
    if (items === null || items.length === 0) {
        return result;
    }

    quietly(() => deps.info?.(`Re-sending ${items.length} queued snapshot(s) that the cloud did not take earlier`));

    const remaining = [...items];

    while (remaining.length > 0) {
        const item = remaining[0];

        try {
            await deps.upload(item.entryId, item.body);
        } catch (err) {
            const attempts = (Number.isInteger(item.attempts) ? item.attempts : 0) + 1;
            const message = errorText(err);
            const sha256 = item.body?.xml_sha256?.slice(0, 12) ?? null;

            if (attempts >= SNAPSHOT_RETRY_MAX_ATTEMPTS) {
                remaining.shift();
                result.dropped.push(item.entryId);
                quietly(() =>
                    deps.error?.(
                        `Giving up on the snapshot for entry #${item.entryId} after ${attempts} upload attempts — `
                            + 'the cloud never took it, so the drawer will show no XML for that post. '
                            + 'The post outcome itself is unaffected.',
                        { sha256, attempts, queuedAt: item.queuedAt, lastError: message },
                    ),
                );
            } else {
                remaining[0] = { ...item, attempts, lastError: message };
                quietly(() =>
                    deps.warn(
                        `Queued snapshot upload failed again for entry #${item.entryId} (attempt ${attempts}/${SNAPSHOT_RETRY_MAX_ATTEMPTS}) — kept for the next poll`,
                        { sha256, message },
                    ),
                );
            }

            for (const later of remaining) {
                result.kept.push(later.entryId);
            }

            saveQuietly(store, remaining, deps);

            return result;
        }

        remaining.shift();
        result.sent.push(item.entryId);
        saveQuietly(store, remaining, deps);
        quietly(() =>
            deps.info?.(`Queued snapshot uploaded for entry #${item.entryId} (attempt ${item.attempts + 1})`, {
                sha256: item.body?.xml_sha256?.slice(0, 12) ?? null,
                tallySuccess: item.body?.tally?.success ?? null,
            }),
        );
    }

    return result;
}

function saveQuietly(store: SnapshotQueueStore, items: QueuedSnapshot[], log: SnapshotQueueLog): void {
    try {
        store.save(items);
    } catch (err) {
        quietly(() => log.warn('Snapshot journal could not be written after a flush — records may be re-sent next poll', { message: errorText(err) }));
    }
}
