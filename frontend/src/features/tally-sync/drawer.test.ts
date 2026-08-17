import { describe, expect, it } from 'vitest';
import {
    eventLabel,
    formatXml,
    holdCopy,
    instant,
    mappingBadge,
    payloadColumns,
    showsFixedAfterFailures,
    snapshotAnswer,
    snapshotHeadline,
    snapshotXmlDecision,
    sourceLink,
    timelineItems,
    voucherStockLines,
} from './drawer';
import type { TallySyncEntry, TallySyncSnapshot, TimelineItem } from './types';

/**
 * The detail drawer's contract with GET /tally-sync/entries/{id}, pinned
 * on the pure helpers:
 *
 *  - every mapping state gets ONE colour and ONE wording — the ambiguous
 *    badge repeats the backend's count and never rounds it to "mapped" or
 *    "missing";
 *  - the payload table shows a Rate / Amount column ONLY when the server
 *    sent those keys (they are omitted, not nulled, for a non-finance
 *    reader) — no blank column advertising a number it will not show;
 *  - a reconstructed timeline row (a backfilled event, or a column read
 *    now) is muted and tagged, so nobody reads it as an observation.
 */

describe('mappingBadge', () => {
    it('gives each state its own colour and wording', () => {
        expect(mappingBadge('identity')).toMatchObject({ color: 'green', text: 'identity' });
        expect(mappingBadge('name_only')).toMatchObject({ color: 'gold', text: 'name only' });
        expect(mappingBadge('unmapped')).toMatchObject({ color: 'red', text: 'unmapped' });
        expect(mappingBadge('fixture')).toMatchObject({ color: 'red', text: 'local fixture — never posts' });
        expect(mappingBadge('none')).toMatchObject({ color: 'default', text: 'none' });
    });

    it('repeats the backend count on an ambiguous name — items and warehouses alike', () => {
        const items = mappingBadge(
            'ambiguous',
            '3 items in this ERP share the name "500ml PET Bottle" (1 with a Tally GUID, 0 local fixtures). '
                + 'Tally would match one of them by name; this ERP cannot say which.',
            3,
        );
        expect(items.color).toBe('orange');
        expect(items.text).toBe('3 items share this name — Tally would match one; the ERP cannot say which');

        const godowns = mappingBadge(
            'ambiguous',
            '2 warehouses in this ERP share the name "Store". Tally would match one godown by name; this ERP cannot say which.',
            2,
        );
        expect(godowns.text).toBe('2 warehouses share this name — Tally would match one; the ERP cannot say which');
    });

    it('takes the count from the structured shared_count, not the note, when both are present', () => {
        // The server's number wins over anything the prose happens to say —
        // the note is a sentence, shared_count is the count.
        const badge = mappingBadge('ambiguous', '3 items in this ERP share the name "x". Tally would match one of them by name; this ERP cannot say which.', 4);
        expect(badge.text).toBe('4 items share this name — Tally would match one; the ERP cannot say which');
        // With a count and no note at all, the noun is generic.
        expect(mappingBadge('ambiguous', null, 2).text).toBe('2 rows share this name — Tally would match one; the ERP cannot say which');
    });

    it('falls back to the note when no shared_count is sent, and still says ambiguous when neither carries a count', () => {
        expect(mappingBadge('ambiguous', '2 warehouses in this ERP share the name "Store".').text)
            .toBe('2 warehouses share this name — Tally would match one; the ERP cannot say which');
        expect(mappingBadge('ambiguous', null).text).toBe('ambiguous — Tally would match one; the ERP cannot say which');
        expect(mappingBadge('ambiguous', null, null).text).toBe('ambiguous — Tally would match one; the ERP cannot say which');
        expect(mappingBadge('ambiguous').color).toBe('orange');
    });

    it('says withheld (FC-06) for a supplier row this reader may not see, with the server note as the tooltip', () => {
        const badge = mappingBadge('withheld', 'The supplier on this voucher is withheld: supplier identity is Owner/Accounts only (FC-06).');
        expect(badge).toEqual({
            color: 'default',
            text: 'withheld (FC-06)',
            title: 'The supplier on this voucher is withheld: supplier identity is Owner/Accounts only (FC-06).',
        });
    });

    it('carries the backend note as the tooltip and never invents one', () => {
        expect(mappingBadge('identity', '"Day Bin" is an internal location; its lines post under its Tally-known ancestor "RM Store".').title)
            .toContain('Tally-known ancestor');
        // Every identity now carries a note from the server ("recorded when
        // masters were last pulled … this ERP cannot know that"); a row
        // without one still gets no invented tooltip.
        expect(mappingBadge('identity').title).toBeNull();
        expect(mappingBadge('name_only', 'x is carried as a name only').title).toBe('x is carried as a name only');
    });

    it('passes an unknown state through as grey text rather than throwing', () => {
        expect(mappingBadge('something_new')).toEqual({ color: 'default', text: 'something_new', title: null });
    });
});

describe('payloadColumns', () => {
    it('renders item and quantity only when the server omitted the rate keys (non-finance reader)', () => {
        expect(payloadColumns('receipt_note', { item: 'PET Resin', quantity: '200.0000' }).map((c) => c.key))
            .toEqual(['item', 'quantity']);
    });

    it('adds Rate and Amount columns only when those keys are present on the line', () => {
        expect(payloadColumns('receipt_note', { item: 'PET Resin', quantity: '200.0000', rate: '96.5000', amount: '19300.0000' })
            .map((c) => c.key)).toEqual(['item', 'quantity', 'rate', 'amount']);
        // A key present with a null value is still a column — the server sent it; absence is the only "no".
        expect(payloadColumns('sales_invoice', { item: 'x', quantity: '1', rate: null }).map((c) => c.key))
            .toEqual(['item', 'quantity', 'rate']);
    });

    it('a Delivery Note never had a price, so it never grows one', () => {
        expect(payloadColumns('delivery_note', { item: '500ml PET Bottle', quantity: '2000.0000' }).map((c) => c.key))
            .toEqual(['item', 'quantity']);
    });

    it('a Journal shows ledger lines with the sides the payload carries', () => {
        expect(payloadColumns('journal', { ledger: '4000 - Sales', debit: '0.00', credit: '100.00', memo: null }).map((c) => c.key))
            .toEqual(['ledger', 'debit', 'credit', 'memo']);
        expect(payloadColumns('journal', { ledger: '4000 - Sales' }).map((c) => c.key)).toEqual(['ledger']);
    });

    it('right-aligns every numeric column and titles them for a person', () => {
        const columns = payloadColumns('receipt_note', { item: 'x', quantity: '1', rate: '2', amount: '2' });
        expect(columns.map((c) => c.title)).toEqual(['Item', 'Quantity', 'Rate', 'Amount']);
        expect(columns.filter((c) => c.align === 'right').map((c) => c.key)).toEqual(['quantity', 'rate', 'amount']);
    });

    it('an unclassified row is described by its shape — ledger lines when it carries ledgers, stock lines otherwise', () => {
        expect(payloadColumns('unknown', { ledger: 'Cash', debit: '5' }).map((c) => c.key)).toEqual(['ledger', 'debit']);
        expect(payloadColumns('unknown', { item: 'Cash', quantity: '5' }).map((c) => c.key)).toEqual(['item', 'quantity']);
        expect(payloadColumns('unknown', null).map((c) => c.key)).toEqual(['item', 'quantity']);
    });
});

describe('timelineItems', () => {
    const rows: TimelineItem[] = [
        { at: '2026-08-10T04:00:00+00:00', event: 'voucher.enqueued', actor_type: 'system', actor_label: 'backfill 2026-08-16', detail: null, source: 'backfill', backfilled: true },
        { at: '2026-08-10T04:05:00+00:00', event: 'pending.delivered', actor_type: 'agent', actor_label: 'factory-pc', detail: 'Handed to the agent.', source: 'event', backfilled: false },
        { at: '2026-08-10T04:06:00+00:00', event: 'voucher.failed', actor_type: 'agent', actor_label: 'factory-pc', detail: 'The agent reported that Tally rejected it (attempt 1): Stock Item does not exist', source: 'event', backfilled: false },
        { at: '2026-08-10T05:00:00+00:00', event: 'voucher.retried', actor_type: 'user', actor_label: 'Priya Accounts', detail: 'Re-queued with the payload regenerated from current mappings — after: Stock Item does not exist', source: 'event', backfilled: false },
        { at: '2026-08-10T05:01:00+00:00', event: 'voucher.synced', actor_type: null, actor_label: null, detail: "From the entry's synced_at — no event was recorded for it, so who did it is not known.", source: 'timestamp', backfilled: true },
    ];

    it('mutes and tags every reconstructed row — backfilled events and bare timestamps alike — and only those', () => {
        const items = timelineItems(rows);
        expect(items.map((item) => item.muted)).toEqual([true, false, false, false, true]);
        expect(items.map((item) => item.tag)).toEqual(['reconstructed', null, null, null, 'reconstructed']);
        // A muted row is grey whatever its event: a reconstructed "synced" is not a green tick.
        expect(items[0].color).toBe('gray');
        expect(items[4].color).toBe('gray');
    });

    it('colours live rows by what happened — red for a rejection, green for an acceptance, blue otherwise', () => {
        const items = timelineItems(rows);
        expect(items[1].color).toBe('blue');
        expect(items[2].color).toBe('red');
        expect(items[3].color).toBe('blue');
        expect(timelineItems([{ ...rows[4], backfilled: false, source: 'event' }])[0].color).toBe('green');
    });

    it('keeps the order, the actor and the detail as the server gave them, with a stable key each', () => {
        const items = timelineItems(rows);
        expect(items.map((item) => item.at)).toEqual(rows.map((row) => row.at));
        expect(items.map((item) => item.actor)).toEqual(['backfill 2026-08-16', 'factory-pc', 'factory-pc', 'Priya Accounts', null]);
        expect(items[2].detail).toContain('Stock Item does not exist');
        expect(new Set(items.map((item) => item.key)).size).toBe(items.length);
    });

    it('is empty for a missing timeline rather than throwing', () => {
        expect(timelineItems(undefined)).toEqual([]);
        expect(timelineItems(null)).toEqual([]);
    });
});

describe('eventLabel', () => {
    it('names the known events for a person and passes an unknown one through', () => {
        expect(eventLabel('voucher.enqueued')).toBe('Queued');
        expect(eventLabel('pending.delivered')).toBe('Handed to the agent');
        expect(eventLabel('voucher.synced')).toBe('Accepted by Tally');
        expect(eventLabel('voucher.failed')).toBe('Rejected by Tally');
        expect(eventLabel('snapshot.stored')).toBe('Snapshot uploaded');
        expect(eventLabel('voucher.retried')).toBe('Resynced');
        expect(eventLabel('voucher.dismissed')).toBe('Dismissed');
        expect(eventLabel('voucher.released')).toBe('Released');
        expect(eventLabel('voucher.merged')).toBe('Entries merged');
        expect(eventLabel('voucher.rebuilt')).toBe('Payload rebuilt');
        expect(eventLabel('voucher.failure_refused')).toBe('Failure report refused');
        expect(eventLabel('something.else')).toBe('something.else');
    });
});

const entry = (over: Partial<TallySyncEntry> = {}): TallySyncEntry => ({
    id: 7,
    syncable_type: 'Shift',
    syncable_id: 3,
    tally_voucher_type: 'Stock Journal',
    category: {
        key: 'production_stock_journal_shift',
        label: 'Production — Stock Journal (per shift)',
        wire_voucher_type: 'Stock Journal',
        source: 'erp',
        erp_build: 'built',
        direction: 'erp_to_tally',
        source_module: 'production',
        erp_label_differs_from_wire: false,
    },
    business_date: '2026-08-10',
    document_number: 'SPE-9',
    party: null,
    item_summary: null,
    payload: {},
    status: 'pending',
    attempts: 0,
    error_message: null,
    synced_at: null,
    delivered_at: null,
    released_at: null,
    hold: null,
    created_at: '2026-08-10T04:00:00+00:00',
    ...over,
});

describe('holdCopy', () => {
    it('says the same words the Status column says', () => {
        const collecting = holdCopy({ phase: 'collecting', shift_ends_at: '2026-08-10T08:30:00+00:00', last_merged_at: '2026-08-10T04:00:00+00:00', releasable_at: '2026-08-10T08:30:00+00:00' });
        expect(collecting.tag).toBe('Collecting the shift');
        expect(collecting.detail).toMatch(/^Collecting until /);

        const quiet = holdCopy({ phase: 'quiet-period', shift_ends_at: null, last_merged_at: '2026-08-10T04:00:00+00:00', releasable_at: '2026-08-10T04:10:00+00:00' });
        expect(quiet.tag).toBe('Quiet period');
        expect(quiet.detail).toMatch(/^Waiting: quiet period — last entry joined /);
    });
});

describe('sourceLink', () => {
    it('sends a production voucher to the production entries and nothing else anywhere', () => {
        expect(sourceLink(entry())).toEqual({ to: '/production/shift-production', label: 'Open production entries' });
        expect(sourceLink(entry({
            syncable_type: 'Invoice',
            category: { ...entry().category, key: 'sales_invoice', source_module: 'sales' },
        }))).toBeNull();
    });
});

describe('voucherStockLines', () => {
    it('reads produced/consumed only when every line is a stock line, else null', () => {
        expect(voucherStockLines(entry({ payload: { produced: [{ item: 'A', quantity: '1.0000' }] } }), 'produced'))
            .toEqual([{ item: 'A', quantity: '1.0000' }]);
        expect(voucherStockLines(entry({ payload: { produced: [{ item: 'A' }] } }), 'produced')).toBeNull();
        expect(voucherStockLines(entry({ payload: { lines: [] } }), 'consumed')).toBeNull();
    });
});

describe('showsFixedAfterFailures', () => {
    it('shows only once the voucher has left the failed state', () => {
        expect(showsFixedAfterFailures({ status: 'pending', error_message: null, resolution_log: [{}] })).toBe(true);
        expect(showsFixedAfterFailures({ status: 'synced', error_message: null, resolution_log: [{}] })).toBe(true);
    });

    it('never reads a withheld error on a still-failed row as "fixed" (FC-06)', () => {
        // The server nulls error_message for a reader without standing on a
        // supplier voucher; the row is still failed — no green banner.
        expect(showsFixedAfterFailures({ status: 'failed', error_message: null, resolution_log: [{}] })).toBe(false);
    });

    it('is quiet when there was never a repair, or the error is still on the row', () => {
        expect(showsFixedAfterFailures({ status: 'pending', error_message: null, resolution_log: [] })).toBe(false);
        expect(showsFixedAfterFailures({ status: 'pending', error_message: 'Stock Item does not exist', resolution_log: [{}] })).toBe(false);
    });
});

// ---- snapshots (Phase 4: what the agent sent / what Tally answered) --------

describe('formatXml', () => {
    it('keeps a ">" inside a quoted attribute value inside the tag', () => {
        expect(formatXml('<A B="x>y"><C>1</C></A>').split('\n')).toEqual(['<A B="x>y">', '  <C>1</C>', '</A>']);
    });

    it('indents by tag depth and keeps a text-only element on one line', () => {
        const xml = '<ENVELOPE><HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER><BODY><DESC><STATICVARIABLES><SVCURRENTCOMPANY>X</SVCURRENTCOMPANY></STATICVARIABLES></DESC></BODY></ENVELOPE>';
        expect(formatXml(xml)).toBe([
            '<ENVELOPE>',
            '  <HEADER>',
            '    <TALLYREQUEST>Import Data</TALLYREQUEST>',
            '  </HEADER>',
            '  <BODY>',
            '    <DESC>',
            '      <STATICVARIABLES>',
            '        <SVCURRENTCOMPANY>X</SVCURRENTCOMPANY>',
            '      </STATICVARIABLES>',
            '    </DESC>',
            '  </BODY>',
            '</ENVELOPE>',
        ].join('\n'));
    });

    it('puts a self-closing tag on its own line without changing the depth of what follows', () => {
        expect(formatXml('<A><B/><C attr="1"/><D>x</D></A>')).toBe([
            '<A>',
            '  <B/>',
            '  <C attr="1"/>',
            '  <D>x</D>',
            '</A>',
        ].join('\n'));
    });

    it('keeps the declaration on the first line and an empty element on one line', () => {
        expect(formatXml('<?xml version="1.0" encoding="UTF-8"?><A><B></B><C>1</C></A>')).toBe([
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<A>',
            '  <B></B>',
            '  <C>1</C>',
            '</A>',
        ].join('\n'));
    });

    it('preserves attributes and text — including entities — untouched, and trims a text node', () => {
        const out = formatXml('<VOUCHER VCHTYPE="Stock Journal" ACTION="Create"><NARRATION> Batch B&amp;7 </NARRATION></VOUCHER>');
        expect(out).toBe([
            '<VOUCHER VCHTYPE="Stock Journal" ACTION="Create">',
            '  <NARRATION>Batch B&amp;7</NARRATION>',
            '</VOUCHER>',
        ].join('\n'));
    });

    it('is stable on input that is already pretty-printed (whitespace between tags is not text)', () => {
        const pretty = formatXml('<A><B>1</B><C><D/></C></A>');
        expect(formatXml(pretty)).toBe(pretty);
        expect(formatXml('<A>\n  <B>1</B>\n</A>\n')).toBe('<A>\n  <B>1</B>\n</A>');
    });

    it('never throws on malformed or empty input — depth clamps at zero and text stands alone', () => {
        expect(formatXml('')).toBe('');
        expect(formatXml('   ')).toBe('');
        expect(formatXml('</A></B><C>x</C>')).toBe('</A>\n</B>\n<C>x</C>');
        // Mixed content: text beside a child element gets its own indented line.
        expect(formatXml('<A>lead<B>1</B></A>')).toBe('<A>\n  lead\n  <B>1</B>\n</A>');
        expect(formatXml('<A><B>1</B>')).toBe('<A>\n  <B>1</B>');
        expect(formatXml('plain text only')).toBe('plain text only');
    });

    it('treats a comment as a line of its own at the current depth', () => {
        expect(formatXml('<A><!-- built by agent --><B>1</B></A>')).toBe('<A>\n  <!-- built by agent -->\n  <B>1</B>\n</A>');
    });
});

const snapshot = (over: Partial<TallySyncSnapshot> = {}): TallySyncSnapshot => ({
    id: 41,
    attempt: 2,
    created_at: '2026-08-16T04:34:00+00:00',
    agent_version: '0.3.8',
    xml_sha256: '3f2a9c1b7d0e4a5b6c7d8e9f0a1b2c3d4e5f60718293a4b5c6d7e8f9a0b1c2d3',
    xml_bytes: 1842,
    payload_matches: true,
    tally: { success: true, created: 1, errors: 0, message: 'Imported successfully' },
    xml: '<ENVELOPE><BODY/></ENVELOPE>',
    xml_withheld: null,
    ...over,
});

describe('snapshotHeadline', () => {
    it('reads attempt · agent version · when · sha256 (first 12) · bytes · payload verdict, in that order', () => {
        const parts = snapshotHeadline(snapshot()).split(' · ');
        expect(parts).toEqual([
            'attempt 2',
            'agent v0.3.8',
            instant('2026-08-16T04:34:00+00:00'),
            'sha256 3f2a9c1b7d0e',
            '1842 bytes',
            'payload matched at upload',
        ]);
    });

    it('says "size unknown" when neither a body nor a size arrived — never "null bytes"', () => {
        expect(snapshotHeadline(snapshot({ xml_bytes: null }))).toContain('size unknown');
        expect(snapshotHeadline(snapshot({ xml_bytes: null }))).not.toContain('null');
    });

    it('says the payload changed since when the cloud regenerated it after this XML was built', () => {
        expect(snapshotHeadline(snapshot({ payload_matches: false }))).toContain('payload had changed before upload');
        expect(snapshotHeadline(snapshot({ payload_matches: false }))).not.toContain('matched at upload');
    });

    it('does not invent what the snapshot cannot say — unknown attempt, version, time or comparison read as unknown', () => {
        const parts = snapshotHeadline(snapshot({ attempt: null, agent_version: null, created_at: null, payload_matches: null, xml_bytes: 1 })).split(' · ');
        expect(parts).toEqual(['attempt —', 'agent version unknown', '—', 'sha256 3f2a9c1b7d0e', '1 byte', 'payload not compared']);
    });

    it('does not repeat a "v" the agent already put in its version string', () => {
        expect(snapshotHeadline(snapshot({ agent_version: 'v0.3.8' }))).toContain('agent v0.3.8');
        expect(snapshotHeadline(snapshot({ agent_version: 'v0.3.8' }))).not.toContain('vv');
    });
});

describe('snapshotXmlDecision', () => {
    it('shows the XML when the server sent it', () => {
        expect(snapshotXmlDecision(snapshot())).toEqual({ kind: 'xml', text: '<ENVELOPE><BODY/></ENVELOPE>' });
    });

    it('shows the server\'s withheld note — never a blank — when the XML is held back (FC-06)', () => {
        const note = "withheld — this voucher's XML carries rates or a party; readers with finance standing see it (FC-06)";
        expect(snapshotXmlDecision(snapshot({ xml: null, xml_withheld: note }))).toEqual({ kind: 'withheld', text: note });
    });

    it('says the agent uploaded no body when there is neither XML nor a withheld note', () => {
        const decision = snapshotXmlDecision(snapshot({ xml: null, xml_withheld: null }));
        expect(decision.kind).toBe('none');
        expect(decision.text).toMatch(/no XML body/);
        expect(decision.text).toMatch(/sha256/);
    });

    it('prefers the XML over a stray note if both were ever sent', () => {
        expect(snapshotXmlDecision(snapshot({ xml: '<A/>', xml_withheld: 'x' })).kind).toBe('xml');
    });
});

describe('snapshotAnswer', () => {
    it('reads an accepted post as green with its counts and message', () => {
        expect(snapshotAnswer(snapshot())).toEqual({
            kind: 'answer',
            tags: [
                { color: 'green', text: 'accepted' },
                { color: 'default', text: 'created 1' },
                { color: 'default', text: 'errors 0' },
            ],
            message: 'Imported successfully',
            messageWithheld: null,
            raw: null,
        });
    });

    it('reads a rejection as red, colours a non-zero error count red, and carries Tally\'s words', () => {
        const answer = snapshotAnswer(snapshot({ tally: { success: false, created: 0, errors: 1, message: 'Stock Item does not exist', raw: '<RESPONSE>…</RESPONSE>' } }));
        expect(answer.kind).toBe('answer');
        if (answer.kind !== 'answer') return;
        expect(answer.tags).toEqual([
            { color: 'red', text: 'rejected' },
            { color: 'default', text: 'created 0' },
            { color: 'red', text: 'errors 1' },
        ]);
        expect(answer.message).toBe('Stock Item does not exist');
        expect(answer.raw).toBe('<RESPONSE>…</RESPONSE>');
    });

    it('carries the withheld note in place of the message on a supplier voucher for a reader without standing (FC-06)', () => {
        const answer = snapshotAnswer(snapshot({ tally: { success: false, created: 0, errors: 1, message: null, message_withheld: 'withheld (FC-06)' } }));
        expect(answer.kind).toBe('answer');
        if (answer.kind !== 'answer') return;
        expect(answer.message).toBeNull();
        expect(answer.messageWithheld).toBe('withheld (FC-06)');
    });

    it('is "none" when the agent had no answer to report — a null tally, or one with every field null', () => {
        expect(snapshotAnswer(snapshot({ tally: null })).kind).toBe('none');
        expect(snapshotAnswer(snapshot({ tally: { success: null, created: null, errors: null, message: null } })).kind).toBe('none');
        // A verdict without counts is still an answer.
        expect(snapshotAnswer(snapshot({ tally: { success: false, created: null, errors: null, message: null } })).kind).toBe('answer');
    });

    it('marks an unknown verdict as such and skips a count it was not given', () => {
        const answer = snapshotAnswer(snapshot({ tally: { success: null, created: 0, errors: null, message: 'Tally did not confirm import' } }));
        expect(answer.kind).toBe('answer');
        if (answer.kind !== 'answer') return;
        expect(answer.tags).toEqual([
            { color: 'default', text: 'no verdict' },
            { color: 'default', text: 'created 0' },
        ]);
    });
});
