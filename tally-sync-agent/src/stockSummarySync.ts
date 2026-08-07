import { previewStockSummary, type StockSummaryPreview } from './cloudApi';
import { getConfig, isConfigured } from './config';
import logger from './logger';
import { exportItems, type ItemNode } from './tally/masters';
import {
    exportScope,
    nameFitsFilter,
    probeScope,
    type GroupScope,
    type StockSummaryLine,
    type StockSummaryPayload,
} from './tally/stockSummary';

/**
 * The Stock Summary preview run: read the godown-wise closing position out of
 * the local Tally — in scopes each PROVEN small before anything heavy is sent
 * — and send the complete set to the cloud to be REPORTED ON, never imported.
 *
 * Manual only, on purpose. There is no loop and no interval, unlike the
 * voucher and masters syncs. An opening-stock snapshot is a one-off act tied
 * to a cutover date that a person chose.
 *
 * Shaped by two field incidents on 07 Aug 2026 (see tally/stockSummary.ts for
 * the request-level story). The run works like this:
 *
 *   1. PLAN: pull the light masters item list (proven safe hourly), derive
 *      every group's item count, and LOG THE WHOLE PLAN before Tally is asked
 *      to compute anything.
 *   2. CANARY: light-probe the scoping mechanism(s) the plan will use. A
 *      probe that returns items outside its scope means Tally's filter is not
 *      filtering on this build — ABORT before any heavy request exists.
 *   3. EXECUTE, bounded, safest first: groups at or under the cap are read as
 *      one heavy chunk each; oversized groups are read ONE ITEM AT A TIME
 *      (each a trivially small request), and they run LAST, so the easy data
 *      is already secured if the long tail fails.
 *   4. HONESTY: the run ends by counting what arrived against what the
 *      masters list said exists, out loud.
 *
 * Single-flight; a second trigger is rejected out loud, and the tray narrates
 * every step. NO AUTOMATIC RETRY anywhere on this path — invariant. A failed
 * scope stops the run; what was already read is kept, and the next manual
 * trigger resumes where it stopped. Tally must be restarted FIRST if it was
 * left wedged — the tray hint says so now, because on 07 Aug the old wording
 * ("click again to resume") invited a resume against a hung Tally.
 */

export interface StockReadStatus {
    running: boolean;
    /** Human sentence for the tray label while running, e.g. "group 3/17 — Finished Goods". */
    progress: string | null;
    /** How the last run ended, shown in the tray so nobody has to dig in logs. */
    lastOutcome: string | null;
}

const state: { running: boolean; progress: string | null; lastOutcome: string | null } = {
    running: false,
    progress: null,
    lastOutcome: null,
};

export function getStockReadStatus(): StockReadStatus {
    return { ...state };
}

/**
 * A heavy (balance-carrying) request is never sent for a scope holding more
 * items than this. Groups over the cap are read one item at a time instead.
 */
const ITEMS_PER_HEAVY_CHUNK_CAP = 40;

/** Pause between heavy group chunks — Tally gets a beat to breathe. */
const CHUNK_DELAY_MS = 750;

/** Pause between per-item requests — smaller, the requests are tiny. */
const ITEM_DELAY_MS = 250;

/** Map key for the scope of items directly under Tally's root ("Primary"). */
const UNGROUPED = '';

interface PlanEntry {
    key: string;
    scope: GroupScope;
    label: string;
    items: ItemNode[];
    mode: 'chunk' | 'per-item';
}

/**
 * What an interrupted run already read, kept in memory so the next manual
 * trigger resumes instead of starting over — group-grained for chunk scopes,
 * item-grained inside per-item scopes. Also kept when the Tally read finished
 * but the cloud POST failed: the re-run then skips everything and goes
 * straight to the POST. Discarded when the company or as-of date changes, and
 * on success. In-memory only: an agent restart starts clean, which is the
 * safe default for a report meant to be read fresh.
 */
let partial: {
    company: string;
    asOf: string;
    linesByGroup: Map<string, StockSummaryLine[]>;
    completedGroups: Set<string>;
} | null = null;

const delay = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

function setProgress(progress: string | null, onProgress?: () => void): void {
    state.progress = progress;
    onProgress?.();
}

/** The corrected resume hint — Tally first, agent second. */
const RESUME_HINT = 'If Tally hung, restart Tally FIRST; then click "Read Stock Summary" again to resume where it stopped';

/**
 * Run one probed-and-bounded Stock Summary read end to end. `onProgress` lets
 * the tray repaint its label as the run advances instead of every 15s.
 */
export async function runStockSummaryPreview(asOf: string, onProgress?: () => void): Promise<StockSummaryPreview | null> {
    if (state.running) {
        logger.warn('Stock Summary: a read is ALREADY RUNNING — this trigger was rejected, nothing new was sent to Tally');
        return null;
    }

    if (!isConfigured()) {
        logger.warn('Stock Summary: agent not configured yet');
        return null;
    }

    const cfg = getConfig();

    if (!cfg.tallyCompanyName) {
        logger.warn('Stock Summary: no Tally company selected — choose one in Settings first');
        return null;
    }

    state.running = true;
    setProgress('starting…', onProgress);

    try {
        const preview = await readAndPreview(asOf, onProgress);
        state.lastOutcome = `✓ Last stock read: ${preview.totals.lines} line(s) sent for preview — nothing imported`;
        return preview;
    } catch (err) {
        const message = err instanceof Error ? err.message : String(err);
        state.lastOutcome = `⚠ Last stock read stopped: ${message.slice(0, 90)}`;
        throw err;
    } finally {
        state.running = false;
        setProgress(null, onProgress);
    }
}

async function readAndPreview(asOf: string, onProgress?: () => void): Promise<StockSummaryPreview> {
    const cfg = getConfig();
    const target = { host: cfg.tallyHost, port: cfg.tallyPort, company: cfg.tallyCompanyName };

    logger.info('Stock Summary: read starting (read-only, probed scopes, no auto-retry)', {
        tally: `${cfg.tallyHost}:${cfg.tallyPort}`,
        company: cfg.tallyCompanyName,
        asOf,
    });

    // A leftover from a different company or date is not a resume, it's a
    // contamination — drop it.
    if (partial && (partial.company !== target.company || partial.asOf !== asOf)) {
        logger.info('Stock Summary: discarding leftover partial read for a different company/date', {
            had: { company: partial.company, asOf: partial.asOf },
        });
        partial = null;
    }

    // ---- 1. PLAN ---------------------------------------------------------
    setProgress('listing stock items…', onProgress);
    const items = await exportItems(target);

    const byGroup = new Map<string, ItemNode[]>();
    for (const item of items) {
        const key = item.parent ?? UNGROUPED;
        const list = byGroup.get(key);
        if (list) list.push(item);
        else byGroup.set(key, [item]);
    }

    // Bounded chunks first (alphabetical, ungrouped leading), oversized
    // per-item scopes LAST and smallest-first — the risky, slow work runs
    // after the easy data is already secured.
    const keys = [...byGroup.keys()].sort((a, b) => {
        if (a === UNGROUPED) return -1;
        if (b === UNGROUPED) return 1;
        return a.localeCompare(b);
    });

    const plan: PlanEntry[] = keys.map((key) => {
        const groupItems = byGroup.get(key) ?? [];
        return {
            key,
            scope: key === UNGROUPED ? null : key,
            label: key === UNGROUPED ? 'ungrouped items' : key,
            items: groupItems,
            mode: groupItems.length <= ITEMS_PER_HEAVY_CHUNK_CAP ? 'chunk' : 'per-item',
        };
    });
    plan.sort((a, b) => {
        if (a.mode !== b.mode) return a.mode === 'chunk' ? -1 : 1;
        if (a.mode === 'per-item') return a.items.length - b.items.length;
        return 0;
    });

    // The whole plan, out loud, BEFORE Tally is asked to compute anything —
    // on 07 Aug the failed runs left no record of what they had intended.
    logger.info(
        `Stock Summary: plan — ${items.length} item(s) in ${plan.length} scope(s), cap ${ITEMS_PER_HEAVY_CHUNK_CAP} item(s) per heavy request`,
        Object.fromEntries(plan.map((p) => [p.label, `${p.items.length} item(s), ${p.mode}`])),
    );

    const done = partial?.linesByGroup ?? new Map<string, StockSummaryLine[]>();
    const completed = partial?.completedGroups ?? new Set<string>();
    partial = { company: target.company, asOf, linesByGroup: done, completedGroups: completed };

    // Item GUIDs already collected, rebuilt from the kept lines on resume.
    // Also the cross-scope dedupe: the same item surfacing in two scopes must
    // not become two opening balances.
    const seenGuids = new Set<string>();
    for (const lines of done.values()) {
        for (const line of lines) seenGuids.add(line.item_guid);
    }
    if (completed.size > 0 || seenGuids.size > 0) {
        logger.info(`Stock Summary: resuming — ${completed.size} scope(s) and ${seenGuids.size} item(s) kept from the stopped run`);
    }

    // ---- 2. CANARIES -----------------------------------------------------
    // Probe the group-scoping mechanism on the SMALLEST named group (fall
    // back to the root scope only when no named group exists). Light request:
    // safe even if the filter fails wide open — that failure is the finding.
    const namedForCanary = plan
        .filter((p) => p.key !== UNGROUPED && p.items.length > 0)
        .sort((a, b) => a.items.length - b.items.length)[0];
    const canaryScope: PlanEntry | undefined = namedForCanary ?? plan.find((p) => p.items.length > 0);

    if (canaryScope) {
        setProgress(`checking Tally's group scoping (canary: ${canaryScope.label})…`, onProgress);

        const expected = new Set(canaryScope.items.map((i) => i.guid));
        const probed = await probeScope(target, asOf, canaryScope.scope);
        const strangers = probed.filter((guid) => !expected.has(guid));

        if (strangers.length > 0) {
            logger.error(
                `Stock Summary: ABORTED BEFORE ANY HEAVY REQUEST — the group-scoping canary failed. `
                + `Scope "${canaryScope.label}" should hold ${expected.size} item(s) but the probe returned `
                + `${probed.length}, including ${strangers.length} from outside the scope. CHILDOF does not `
                + 'filter on this Tally build; a heavy request would have been the full catalogue again. '
                + 'Nothing was read. This needs a developer, not a retry.',
            );
            throw new Error(`group-scoping canary failed on "${canaryScope.label}" — read aborted before any heavy request; tell the developers`);
        }
        if (probed.length < expected.size) {
            logger.warn(
                `Stock Summary: canary scope "${canaryScope.label}" returned ${probed.length} of ${expected.size} expected item(s) `
                + '— group names may not round-trip exactly; the run continues and the final count will say what is missing.',
            );
        }
        logger.info(`Stock Summary: group-scoping canary passed on "${canaryScope.label}" (${probed.length}/${expected.size} item(s))`);
    }

    // Probe the item-name filter only if the plan needs it, on one known item
    // of the first per-item scope. It must return exactly that item.
    const firstPerItem = plan.find((p) => p.mode === 'per-item');
    if (firstPerItem) {
        const probeItem = firstPerItem.items.find((i) => nameFitsFilter(i.name));
        if (!probeItem) {
            throw new Error(`every item name in oversized group "${firstPerItem.label}" contains a double-quote — per-item reads cannot run; tell the developers`);
        }

        setProgress(`checking Tally's item filter (canary: ${probeItem.name.slice(0, 40)})…`, onProgress);
        const probed = await probeScope(target, asOf, firstPerItem.scope, probeItem.name);

        if (probed.length !== 1 || probed[0] !== probeItem.guid) {
            logger.error(
                `Stock Summary: ABORTED BEFORE ANY HEAVY REQUEST — the item-filter canary failed. `
                + `Filtering scope "${firstPerItem.label}" to item "${probeItem.name}" should return exactly that item; `
                + `the probe returned ${probed.length} item(s). The oversized group(s) cannot be read safely on this `
                + 'Tally build. Nothing heavy was sent. This needs a developer, not a retry.',
            );
            throw new Error('item-filter canary failed — read aborted before any heavy request; tell the developers');
        }
        logger.info('Stock Summary: item-filter canary passed');
    }

    // ---- 3. EXECUTE, bounded, safest first --------------------------------
    const total = plan.length;

    for (let i = 0; i < total; i += 1) {
        const entry = plan[i];
        const position = `group ${i + 1}/${total} — ${entry.label}`;

        if (completed.has(entry.key)) {
            logger.info(`Stock Summary: ${position}: kept from previous run, skipped`);
            continue;
        }

        if (entry.mode === 'chunk') {
            setProgress(`${position} (${entry.items.length} items)`, onProgress);

            let lines: StockSummaryLine[];
            try {
                lines = await exportScope(target, asOf, entry.scope);
            } catch (err) {
                const message = err instanceof Error ? err.message : String(err);
                logger.error(
                    `Stock Summary: ${position} FAILED — stopping here, NO automatic retry. `
                    + `${completed.size}/${total} scope(s) already read are kept. ${RESUME_HINT}.`,
                    { message },
                );
                throw new Error(`scope "${entry.label}" (${i + 1}/${total}) failed: ${message} — ${RESUME_HINT}`);
            }

            const kept = keepFresh(lines, seenGuids, position);
            done.set(entry.key, kept.lines);
            completed.add(entry.key);
            logger.info(
                `Stock Summary: ${position}: ${kept.lines.length} line(s), ${kept.freshItems} item(s)`
                + (kept.freshItems === entry.items.length ? '' : ` — EXPECTED ${entry.items.length} item(s) from the masters list`),
            );

            if (i < total - 1) await delay(CHUNK_DELAY_MS);
            continue;
        }

        // Per-item scope: every request is one item, trivially small.
        const groupLines = done.get(entry.key) ?? [];
        done.set(entry.key, groupLines);
        let skippedQuoted = 0;

        for (let j = 0; j < entry.items.length; j += 1) {
            const item = entry.items[j];

            if (seenGuids.has(item.guid)) continue;

            if (!nameFitsFilter(item.name)) {
                skippedQuoted += 1;
                logger.warn(`Stock Summary: ${position}: item "${item.name}" contains a double-quote and cannot ride the name filter — SKIPPED, it will be missing from the snapshot`);
                continue;
            }

            setProgress(`${position}: item ${j + 1}/${entry.items.length}`, onProgress);

            let lines: StockSummaryLine[];
            try {
                lines = await exportScope(target, asOf, entry.scope, item.name);
            } catch (err) {
                const message = err instanceof Error ? err.message : String(err);
                logger.error(
                    `Stock Summary: ${position} FAILED at item ${j + 1}/${entry.items.length} ("${item.name}") — stopping, NO automatic retry. `
                    + `Everything read so far is kept, including ${groupLines.length} line(s) of this group. ${RESUME_HINT}.`,
                    { message },
                );
                throw new Error(`scope "${entry.label}", item ${j + 1}/${entry.items.length} failed: ${message} — ${RESUME_HINT}`);
            }

            // Keep only this item's lines — a filter that quietly matched
            // wider would otherwise smuggle strangers in past the canary.
            const own = lines.filter((l) => l.item_guid === item.guid);
            if (own.length < lines.length) {
                logger.warn(`Stock Summary: ${position}: item "${item.name}" returned ${lines.length - own.length} line(s) of OTHER items — dropped them; tell the developers`);
            }
            groupLines.push(...own);
            seenGuids.add(item.guid);

            await delay(ITEM_DELAY_MS);
        }

        completed.add(entry.key);
        logger.info(
            `Stock Summary: ${position}: ${groupLines.length} line(s) across ${entry.items.length - skippedQuoted} item(s)`
            + (skippedQuoted > 0 ? ` — ${skippedQuoted} item(s) SKIPPED (double-quote in name)` : ''),
        );
    }

    // ---- 4. HONESTY ------------------------------------------------------
    const allLines = plan.flatMap((p) => done.get(p.key) ?? []);
    const itemsRead = seenGuids.size;

    if (itemsRead === items.length) {
        logger.info(`Stock Summary: read complete — ${allLines.length} line(s) covering all ${items.length} item(s), in ${total} scope(s)`);
    } else {
        logger.warn(
            `Stock Summary: read complete but INCOMPLETE COVERAGE — ${allLines.length} line(s) covering `
            + `${itemsRead} of ${items.length} item(s). The missing items were NOT invented; `
            + 'the preview on the ERP will simply not show them. Tell the developers before trusting this snapshot.',
        );
    }

    const payload: StockSummaryPayload = {
        company: target.company,
        as_of: asOf,
        lines: allLines,
    };

    setProgress('sending to ERP for preview…', onProgress);
    const preview = await previewStockSummary(payload);

    // Only now is the run truly over — a failed POST above keeps `partial`,
    // so the next trigger skips every scope and just re-sends what was read.
    partial = null;

    logger.info('Stock Summary: preview returned by ERP — NOTHING was imported', preview.totals);

    return preview;
}

/**
 * Split a chunk's lines into new items and items another scope already
 * delivered. Within one chunk an item legitimately spans several lines (one
 * per godown/batch), so dedupe by GUID across scopes, never within.
 */
function keepFresh(
    lines: StockSummaryLine[],
    seenGuids: Set<string>,
    position: string,
): { lines: StockSummaryLine[]; freshItems: number } {
    const duplicated = new Set<string>();
    const fresh = new Set<string>();
    const kept: StockSummaryLine[] = [];

    for (const line of lines) {
        if (seenGuids.has(line.item_guid)) {
            duplicated.add(line.item_guid);
            continue;
        }
        fresh.add(line.item_guid);
        kept.push(line);
    }
    for (const guid of fresh) seenGuids.add(guid);

    if (duplicated.size > 0) {
        logger.warn(
            `Stock Summary: ${position}: ${duplicated.size} item(s) had already arrived in an earlier scope `
            + 'and were dropped from this one — Tally\'s scoping overlapped where it should not. '
            + 'The snapshot stays correct (each item counted once), but tell the developers.',
        );
    }

    return { lines: kept, freshItems: fresh.size };
}
