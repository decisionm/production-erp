import { previewStockSummary, type StockSummaryPreview } from './cloudApi';
import { getConfig, isConfigured } from './config';
import logger from './logger';
import { exportItems } from './tally/masters';
import { exportStockSummaryChunk, type StockSummaryLine, type StockSummaryPayload } from './tally/stockSummary';

/**
 * The Stock Summary preview run: read the godown-wise closing position out of
 * the local Tally — one stock group at a time — and send the complete set to
 * the cloud to be REPORTED ON, never imported.
 *
 * Manual only, on purpose. There is no loop and no interval, unlike the voucher
 * and masters syncs. An opening-stock snapshot is a one-off act tied to a
 * cutover date that a person chose; putting it on a timer would mean the
 * factory's opening position could silently change under a running shift.
 *
 * Single-flight, also on purpose: one read at a time, a second trigger is
 * rejected out loud. The 07 Aug field incident proved a full-catalogue read
 * kills Tally on its own; the old runner also had no in-progress guard and no
 * feedback, so an operator staring at a silent tray had every reason to click
 * again. Now the tray label narrates every step and the button is disabled
 * while a read runs.
 *
 * NO AUTOMATIC RETRY anywhere on this path — invariant. A failed chunk stops
 * the run; what was already read is kept, and the next manual trigger resumes
 * from the group that failed instead of re-asking Tally for work it already
 * did.
 */

export interface StockReadStatus {
    running: boolean;
    /** Human sentence for the tray label while running, e.g. "group 3/12 — Finished Goods". */
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

/** Pause between chunk requests — Tally gets a beat to breathe between reads. */
const CHUNK_DELAY_MS = 750;

/** Map key for the chunk of items that sit directly under Tally's root ("Primary"). */
const UNGROUPED = '';

/**
 * What a failed run already read, kept in memory so the next manual trigger
 * resumes instead of starting over. Also kept when the Tally read finished but
 * the cloud POST failed — the re-run then skips every chunk and goes straight
 * to the POST. Discarded whenever the company or as-of date changes, and on
 * success. In-memory only: an agent restart starts clean, which is the safe
 * default for a report meant to be read fresh.
 */
let partial: {
    company: string;
    asOf: string;
    linesByGroup: Map<string, StockSummaryLine[]>;
} | null = null;

const delay = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

function setProgress(progress: string | null, onProgress?: () => void): void {
    state.progress = progress;
    onProgress?.();
}

/**
 * Run one chunked Stock Summary read end to end. `onProgress` lets the tray
 * repaint its label as the run advances instead of every 15s.
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
        state.lastOutcome = `⚠ Last stock read failed: ${message.slice(0, 80)} — click again to resume`;
        throw err;
    } finally {
        state.running = false;
        setProgress(null, onProgress);
    }
}

async function readAndPreview(asOf: string, onProgress?: () => void): Promise<StockSummaryPreview> {
    const cfg = getConfig();
    const target = { host: cfg.tallyHost, port: cfg.tallyPort, company: cfg.tallyCompanyName };

    logger.info('Stock Summary: read starting (read-only, chunked, no auto-retry)', {
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

    // The item list (names, parents, GUIDs — no balances) is the same light
    // request the masters loop already runs hourly against this Tally without
    // trouble. It gives us two things the chunked read cannot do without:
    // which groups actually hold items (so empty groups cost no request), and
    // the ground truth to count the chunked result against at the end.
    setProgress('listing stock items…', onProgress);
    const items = await exportItems(target);

    const expectedByGroup = new Map<string, number>();
    for (const item of items) {
        const key = item.parent ?? UNGROUPED;
        expectedByGroup.set(key, (expectedByGroup.get(key) ?? 0) + 1);
    }

    // Ungrouped items first, then groups alphabetically — a deterministic
    // order so a resumed run walks the same list the failed one did.
    const chunkKeys = [...expectedByGroup.keys()].sort((a, b) => {
        if (a === UNGROUPED) return -1;
        if (b === UNGROUPED) return 1;
        return a.localeCompare(b);
    });

    const done = partial?.linesByGroup ?? new Map<string, StockSummaryLine[]>();
    partial = { company: target.company, asOf, linesByGroup: done };

    // Item GUIDs already collected, rebuilt from the kept chunks on resume.
    // Guards against CHILDOF semantics differing on a real Tally (the same
    // item surfacing in two chunks must not become two opening balances).
    const seenGuids = new Set<string>();
    for (const lines of done.values()) {
        for (const line of lines) seenGuids.add(line.item_guid);
    }

    const total = chunkKeys.length;
    logger.info(
        `Stock Summary: ${items.length} item(s) in ${total} chunk(s) — `
        + `${done.size ? `${done.size} chunk(s) kept from the last failed run will be skipped` : 'fresh run'}`,
    );

    for (let i = 0; i < total; i += 1) {
        const key = chunkKeys[i];
        const label = key === UNGROUPED ? 'ungrouped items' : key;
        const position = `group ${i + 1}/${total} — ${label}`;

        if (done.has(key)) {
            logger.info(`Stock Summary: ${position}: kept from previous run, skipped`);
            continue;
        }

        setProgress(position, onProgress);

        let lines: StockSummaryLine[];
        try {
            lines = await exportStockSummaryChunk(target, asOf, key === UNGROUPED ? null : key);
        } catch (err) {
            const message = err instanceof Error ? err.message : String(err);
            logger.error(
                `Stock Summary: ${position} FAILED — stopping here, NO automatic retry. `
                + `${done.size}/${total} chunk(s) already read are kept; `
                + 'run "Read Stock Summary" again to resume from this group. '
                + 'If Tally itself became sluggish, close and reopen it first and read again in a quiet window.',
                { message },
            );
            throw new Error(`stock group "${label}" (${i + 1}/${total}) failed: ${message}`);
        }

        // Split the chunk into new items and items another chunk already
        // delivered. Within one chunk an item legitimately spans several lines
        // (one per godown/batch), so dedupe by GUID across chunks, never within.
        const duplicated = new Set<string>();
        const kept: StockSummaryLine[] = [];
        const fresh = new Set<string>();
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
                `Stock Summary: ${position}: ${duplicated.size} item(s) had already arrived in an earlier chunk `
                + 'and were dropped from this one — Tally\'s group scoping overlapped where it should not. '
                + 'The snapshot stays correct (each item counted once), but tell the developers.',
            );
        }

        done.set(key, kept);

        const expected = expectedByGroup.get(key) ?? 0;
        logger.info(
            `Stock Summary: ${position}: ${kept.length} line(s), ${fresh.size} item(s)`
            + (fresh.size === expected ? '' : ` — EXPECTED ${expected} item(s) from the masters list`),
        );

        if (i < total - 1) {
            await delay(CHUNK_DELAY_MS);
        }
    }

    const allLines = chunkKeys.flatMap((key) => done.get(key) ?? []);
    const itemsRead = seenGuids.size;

    // The honesty check the chunked shape owes the operator: the masters list
    // said how many items exist; say plainly whether the chunks delivered them
    // all. A shortfall usually means a group name that did not round-trip
    // through CHILDOF — a developers problem, never something to interpolate.
    if (itemsRead === items.length) {
        logger.info(`Stock Summary: read complete — ${allLines.length} line(s) covering all ${items.length} item(s), in ${total} chunk(s)`);
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

    // Only now is the run truly over — a failed POST above keeps `partial`, so
    // the next trigger skips every chunk and just re-sends what was read.
    partial = null;

    logger.info('Stock Summary: preview returned by ERP — NOTHING was imported', preview.totals);

    return preview;
}
