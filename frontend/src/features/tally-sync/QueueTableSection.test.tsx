import { isValidElement } from 'react';
import type { ReactElement, ReactNode } from 'react';
import { Table } from 'antd';
import { describe, expect, it } from 'vitest';
import { QueueTableSection, queueUnavailable } from '@/features/tally-sync/pages/TallySyncPage';

/**
 * WHAT THE PAGE SHOWS WHEN THE QUEUE CANNOT BE READ (review follow-up, PR #27).
 *
 * The defect being pinned is a LIE OF OMISSION: the list request failed,
 * the red "Queue unavailable" alert went up — and beneath it the table
 * rendered anyway, empty, with antd's "No data". An accountant reading
 * that has been told two different things at once, and the second one
 * ("nothing is queued") is a count nobody took. Failed vouchers waiting to
 * be resynced look exactly like a clean queue.
 *
 * Asserted against the tree the component actually returns, not against a
 * branch: the EntrySource.test.tsx precedent, and for the same reason —
 * the claim is a NEGATIVE one ("no table"), and a negative claim about
 * rendering is worth nothing asserted about a boolean. antd's `Table` is
 * matched BY REFERENCE, so it is found however deeply it is nested and a
 * renamed component cannot pass by accident. This repo's vitest runs in
 * node; no DOM is involved.
 */

const walk = (node: ReactNode, visit: (element: ReactElement) => void): void => {
    if (Array.isArray(node)) {
        node.forEach((child) => walk(child, visit));
        return;
    }
    if (!isValidElement(node)) return;
    visit(node);
    walk((node.props as { children?: ReactNode }).children, visit);
};

/** Whether an antd Table appears anywhere in the returned tree. */
const hasTable = (node: ReactNode): boolean => {
    let found = false;
    walk(node, (element) => {
        if (element.type === Table) found = true;
    });

    return found;
};

const table = <Table rowKey="id" dataSource={[]} columns={[]} />;

describe('QueueTableSection', () => {
    it('renders NO table when the queue is unavailable', () => {
        const tree = QueueTableSection({ unavailable: true, children: table });

        // Not "a table with no rows" — no table at all. The alert above it
        // is then the only thing on screen making a claim.
        expect(tree).toBeNull();
        expect(hasTable(tree)).toBe(false);
    });

    it('renders the table when the queue was read', () => {
        const tree = QueueTableSection({ unavailable: false, children: table });

        expect(hasTable(tree)).toBe(true);
    });

    it('passes the table through untouched — it decides only whether, never what', () => {
        // A guard that quietly re-rendered the table with different props
        // would be a second place the queue's rows are decided.
        const tree = QueueTableSection({ unavailable: false, children: table });
        let seen: ReactElement | null = null;
        walk(tree, (element) => {
            if (element.type === Table) seen = element;
        });

        expect(seen).toBe(table);
    });
});

describe('queueUnavailable', () => {
    it('is true only for a failed read with nothing to show', () => {
        expect(queueUnavailable(true, 0)).toBe(true);
    });

    it('keeps the rows a failed BACKGROUND refetch did not take away', () => {
        // TanStack leaves `data` in place when a refetch fails: those rows
        // are real, were really read, and every action on them is checked
        // by the server anyway. Hiding them would lose information over a
        // transient failure — the alert already says the read failed.
        expect(queueUnavailable(true, 1)).toBe(false);
        expect(queueUnavailable(true, 25)).toBe(false);
    });

    it('never hides the table on a healthy empty queue', () => {
        // A genuinely empty queue is a measurement the page DID make, and
        // the all-clear alert beside it depends on the table being there.
        expect(queueUnavailable(false, 0)).toBe(false);
        expect(queueUnavailable(false, 3)).toBe(false);
    });
});
