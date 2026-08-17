import { describe, expect, it } from 'vitest';
import {
    ACTION_LABELS,
    PURCHASE_ORDER_STATUSES,
    amendFormDefaults,
    buildPurchaseOrderQuery,
    canLabels,
    consumptionRow,
    filtersFromSearchParams,
    flattenTrace,
    hasActiveFilters,
    lifecycleNote,
    loadWords,
    lotLoadSummary,
    poNumber,
    rateCell,
    reconcileReceipts,
    revisionLines,
    searchParamsFromFilters,
    statusOptions,
    statusTag,
    tallyReasonWords,
    tallyStateLine,
    unwrapTraceResponse,
} from './purchaseOrders';
import type { PurchaseOrder, PurchaseOrderLine, PurchaseOrderTrace, TraceConsumption, TraceLot } from './types';
import type { TallyLink } from '@/features/sales/types';

/**
 * The words the Purchase Orders page uses for an order's status, its Tally
 * state, its lifecycle buttons and its receipt reconciliation — pinned so
 * the table, the detail drawer and the trace drawer cannot drift. Every
 * fixture value is synthetic (Vendor Alpha, ITEM_A, rate 1.00 — FC-06).
 */

const link = (over: Partial<TallyLink> = {}): TallyLink => ({
    entry_id: 7,
    voucher_type: 'Purchase Order',
    status: 'pending',
    voucher_number: 'PO-3',
    synced_at: null,
    flags: {},
    link: '/tally-sync?entry=7',
    ...over,
});

const item = { id: 1, sku: 'ITEM_A', name: 'ITEM_A' } as PurchaseOrderLine['item'];

const line = (over: Partial<PurchaseOrderLine> = {}): PurchaseOrderLine => ({
    id: 10,
    item,
    quantity: '100.0000',
    quantity_received: '0.0000',
    ...over,
});

const po = (over: Partial<PurchaseOrder> = {}): PurchaseOrder => ({
    id: 3,
    status: 'sent',
    source: 'erp',
    tally_order_no: null,
    vendor: {
        id: 1,
        code: 'V-ALPHA',
        name: 'Vendor Alpha',
        email: null,
        phone: null,
        address: null,
        gstin: null,
        state_code: null,
        is_active: true,
        created_at: '2026-08-01T00:00:00Z',
    },
    purchase_requisition_id: null,
    order_date: '2026-08-10',
    expected_date: null,
    notes: null,
    lines: [line()],
    created_at: '2026-08-10T00:00:00Z',
    ...over,
});

describe('statusTag', () => {
    it('gives every one of the five statuses a colour and readable words', () => {
        expect(PURCHASE_ORDER_STATUSES).toEqual(['draft', 'sent', 'partially_received', 'closed', 'cancelled']);
        for (const status of PURCHASE_ORDER_STATUSES) {
            const tag = statusTag(status);
            expect(tag.color).not.toBe('');
            expect(tag.label).not.toContain('_');
        }
        expect(statusTag('partially_received')).toEqual({ color: 'gold', label: 'partially received' });
        expect(statusTag('cancelled')).toEqual({ color: 'red', label: 'cancelled' });
    });

    it('falls through with an unknown status rather than throwing — the row is still worth reading', () => {
        expect(statusTag('something_new' as never)).toEqual({ color: 'default', label: 'something new' });
    });

    it('offers all five statuses to the filter, spelled for people', () => {
        expect(statusOptions().map((o) => o.value)).toEqual(PURCHASE_ORDER_STATUSES);
        expect(statusOptions().find((o) => o.value === 'partially_received')?.label).toBe('partially received');
    });
});

describe('poNumber', () => {
    it('spells the ERP reference the way the staged voucher_number does ("PO-{id}")', () => {
        expect(poNumber(12)).toBe('PO-12');
        expect(poNumber(po({ id: 3 }))).toBe('PO-3');
    });
});

describe('tallyReasonWords', () => {
    it('words each refusal code the cloud names', () => {
        expect(tallyReasonWords({ code: 'party_unmapped' })).toBe('vendor has no Tally ledger name');
        expect(tallyReasonWords({ code: 'item_unmapped', detail: 'ITEM_A' })).toBe('item ITEM_A has no Tally identity');
        expect(tallyReasonWords({ code: 'purchase_ledger_unmapped' })).toBe('purchase ledger not mapped');
        expect(tallyReasonWords({ code: 'no_lines' })).toBe('no lines');
        expect(tallyReasonWords({ code: 'purchase_orders_disabled' })).toBe('PO posting is disabled (owner gate Q35)');
    });

    it('names no item when the server sent none, and passes an unknown code through with its detail', () => {
        expect(tallyReasonWords({ code: 'item_unmapped' })).toBe('an item has no Tally identity');
        expect(tallyReasonWords({ code: 'godown_unmapped', detail: 'godown X' })).toBe('godown_unmapped: godown X');
        expect(tallyReasonWords({ code: 'godown_unmapped' })).toBe('godown_unmapped');
    });
});

describe('tallyStateLine', () => {
    it('disabled: says nothing was sent and names the owner gate', () => {
        const state = tallyStateLine(po({ tally: null, tally_staging: { state: 'disabled', reasons: [], at: '2026-08-10T10:00:00Z' } }));
        expect(state.kind).toBe('disabled');
        expect(state.text).toBe('Not sent to Tally — PO posting is disabled (owner gate Q35)');
        expect(state.link).toBeNull();
    });

    it('refused: says nothing was sent and lists every reason in the cloud\'s order', () => {
        const state = tallyStateLine(
            po({
                tally: null,
                tally_staging: {
                    state: 'refused',
                    reasons: [
                        { code: 'party_unmapped' },
                        { code: 'item_unmapped', detail: 'ITEM_A' },
                    ],
                },
            }),
        );
        expect(state.kind).toBe('refused');
        expect(state.text).toBe('Not sent to Tally — vendor has no Tally ledger name; item ITEM_A has no Tally identity');
        expect(state.color).toBe('orange');
    });

    it('refused with no reasons still refuses to read as sent', () => {
        expect(tallyStateLine(po({ tally_staging: { state: 'refused', reasons: [] } })).text).toBe('Not sent to Tally — refused (no reason recorded)');
    });

    it('enqueued: the tally link\'s status words, the SAME words the Sales pages and the Tally Sync page use', () => {
        const pending = tallyStateLine(po({ tally: link({ status: 'pending' }), tally_staging: { state: 'enqueued', entry_id: 7 } }));
        expect(pending).toMatchObject({ kind: 'link', text: 'Waiting for agent', color: 'default' });
        expect(pending.link?.entry_id).toBe(7);

        expect(tallyStateLine(po({ tally: link({ status: 'synced', synced_at: '2026-08-11T05:00:00Z' }) })).text).toBe('In Tally');
        expect(tallyStateLine(po({ tally: link({ status: 'failed' }) }))).toMatchObject({ text: 'FAILED', color: 'red' });
        expect(tallyStateLine(po({ tally: link({ status: 'dismissed' }) })).text).toBe('Dismissed — never sent');
    });

    it('a link outranks a stale staging record: the entry\'s status is the live fact', () => {
        const state = tallyStateLine(po({ tally: link({ status: 'synced' }), tally_staging: { state: 'disabled' } }));
        expect(state.kind).toBe('link');
        expect(state.text).toBe('In Tally');
    });

    it('enqueued but the link is missing: names the entry rather than inventing a status', () => {
        const state = tallyStateLine(po({ tally: null, tally_staging: { state: 'enqueued', entry_id: 41 } }));
        expect(state.kind).toBe('enqueued');
        expect(state.text).toBe('Queued for Tally — entry #41 (status not readable here)');
    });

    it('a Tally mirror lives in Tally already — the ERP never posts it', () => {
        const state = tallyStateLine(po({ source: 'tally', tally_order_no: 'PO/2026/041', tally: null }));
        expect(state.kind).toBe('mirror');
        expect(state.text).toBe('Lives in Tally — mirror of PO/2026/041');
        expect(tallyStateLine(po({ source: 'tally', tally_order_no: null })).text).toBe('Lives in Tally — mirror');
    });

    it('a draft has not been sent anywhere yet', () => {
        const state = tallyStateLine(po({ status: 'draft', tally: null, tally_staging: null }));
        expect(state.kind).toBe('draft');
        expect(state.text).toBe('Not sent yet — Tally staging happens on Send');
    });

    it('sent with no link and no staging record (sent before staging existed) reads as disabled — the flag has never been on', () => {
        for (const status of ['sent', 'partially_received', 'closed'] as const) {
            expect(tallyStateLine(po({ status, tally: null, tally_staging: null })).text).toBe(
                'Not sent to Tally — PO posting is disabled (owner gate Q35)',
            );
        }
        expect(tallyStateLine(po({ status: 'sent', tally: undefined, tally_staging: undefined })).kind).toBe('disabled');
    });

    it('cancelled with nothing staged says so plainly', () => {
        expect(tallyStateLine(po({ status: 'cancelled', tally: null, tally_staging: null })).text).toBe('Not sent to Tally — cancelled');
    });
});

describe('canLabels', () => {
    it('lists only the actions the server allows, in a fixed order, with their button words', () => {
        expect(canLabels({ send: true, amend: true, close: false, cancel: true })).toEqual([
            { action: 'send', label: ACTION_LABELS.send },
            { action: 'amend', label: ACTION_LABELS.amend },
            { action: 'cancel', label: ACTION_LABELS.cancel },
        ]);
        expect(canLabels({ send: false, amend: false, close: true, cancel: false })).toEqual([{ action: 'close', label: 'Close' }]);
    });

    it('offers nothing when the server has not said — the page never re-derives the state machine', () => {
        expect(canLabels(undefined)).toEqual([]);
        expect(canLabels(null)).toEqual([]);
    });
});

describe('amendFormDefaults', () => {
    it('prefills item, quantity, rate and schedules from the order as served', () => {
        const { lines, ratesNotPrefilled } = amendFormDefaults({
            lines: [
                line({
                    unit_price: '1.00',
                    schedules: [
                        { id: 1, due_date: '2026-09-01', quantity: '60.0000', quantity_received: '0.0000', remaining: '60.0000', tally_reference: null },
                        { id: 2, due_date: '2026-09-15', quantity: '40.0000', quantity_received: '0.0000', remaining: '40.0000', tally_reference: 'ref-2' },
                    ],
                }),
            ],
        });
        expect(ratesNotPrefilled).toBe(false);
        expect(lines).toEqual([
            {
                item_id: 1,
                quantity: 100,
                unit_price: 1,
                schedules: [
                    { due_date: '2026-09-01', quantity: 60 },
                    { due_date: '2026-09-15', quantity: 40, tally_reference: 'ref-2' },
                ],
            },
        ]);
    });

    it('leaves the rate EMPTY and raises the flag when the server withheld it (FC-06) — never a zero, never a guess', () => {
        const { lines, ratesNotPrefilled } = amendFormDefaults({ lines: [line(), line({ id: 11, unit_price: '1.00' })] });
        expect(ratesNotPrefilled).toBe(true);
        expect(lines[0].unit_price).toBeUndefined();
        expect(lines[1].unit_price).toBe(1);
        expect(lines[0]).not.toHaveProperty('schedules');
    });
});

describe('reconcileReceipts', () => {
    it('reads received against ordered per line and names the state', () => {
        const rows = reconcileReceipts([
            line({ id: 1, quantity: '100.0000', quantity_received: '0.0000' }),
            line({ id: 2, quantity: '100.0000', quantity_received: '40.0000' }),
            line({ id: 3, quantity: '100.0000', quantity_received: '100.0000' }),
            line({ id: 4, quantity: '100.0000', quantity_received: '120.0000' }),
        ]);
        expect(rows.map((r) => [r.line_id, r.ordered, r.received, r.remaining, r.state])).toEqual([
            [1, '100.0000', '0.0000', '100.0000', 'open'],
            [2, '100.0000', '40.0000', '60.0000', 'partial'],
            [3, '100.0000', '100.0000', '0.0000', 'complete'],
            [4, '100.0000', '120.0000', '0.0000', 'over'],
        ]);
        expect(rows[1].item).toBe('ITEM_A');
    });

    it('never produces NaN: an unreadable quantity is carried as the server sent it and the state is unknown', () => {
        const [row] = reconcileReceipts([line({ quantity: 'n/a' as string, quantity_received: '1.0000' })]);
        expect(row.remaining).toBe('—');
        expect(row.state).toBe('unknown');
    });

    it('sums the order: how many lines are complete, and totals at four places', () => {
        const rows = reconcileReceipts([
            line({ id: 1, quantity: '10.0000', quantity_received: '10.0000' }),
            line({ id: 2, quantity: '5.5000', quantity_received: '1.0000' }),
        ]);
        expect(rows.summary).toEqual({ lines: 2, complete: 1, ordered: '15.5000', received: '11.0000' });
    });
});

describe('rateCell', () => {
    it('prints the rate when the server served it', () => {
        expect(rateCell({ unit_price: '1.00' }, 'unit_price')).toBe('1.00');
        expect(rateCell({ unit_cost: '1.0000' }, 'unit_cost')).toBe('1.0000');
    });

    it('says "withheld" — never blank — when the key is absent (the Procurement omit-not-null convention, FC-06)', () => {
        expect(rateCell({}, 'unit_price')).toBe('withheld');
        expect(rateCell({ quantity: '1' } as { unit_cost?: string }, 'unit_cost')).toBe('withheld');
    });

    it('says "withheld" when the server named it withheld beside a null', () => {
        expect(rateCell({ unit_price: null, rate_withheld: true }, 'unit_price')).toBe('withheld');
        expect(rateCell({ unit_cost: null, unit_cost_withheld: 'FC-06' }, 'unit_cost')).toBe('withheld');
    });

    it('a null with no withheld note is a rate genuinely not recorded — a different fact, said differently', () => {
        expect(rateCell({ unit_price: null }, 'unit_price')).toBe('not recorded');
    });
});

describe('lifecycleNote', () => {
    it('reads the close and cancel facts the server recorded', () => {
        expect(lifecycleNote(po({ status: 'closed', closed_reason: 'vendor short-shipped', closed_by: { id: 2, name: 'Buyer' }, closed_at: '2026-08-12T09:30:00Z' })))
            .toBe('Closed 12 Aug 2026 by Buyer — vendor short-shipped');
        expect(lifecycleNote(po({ status: 'cancelled', cancelled_reason: 'raised twice', cancelled_by: 'Buyer', cancelled_at: null })))
            .toBe('Cancelled by Buyer — raised twice');
        expect(lifecycleNote(po({ status: 'cancelled', cancelled_reason: null }))).toBe('Cancelled');
        expect(lifecycleNote(po({ status: 'closed', closed_by: 4, closed_reason: 'done' }))).toBe('Closed by user #4 — done');
    });

    it('is null for an order that is neither closed nor cancelled', () => {
        expect(lifecycleNote(po({ status: 'sent' }))).toBeNull();
        expect(lifecycleNote(po({ status: 'draft' }))).toBeNull();
    });
});

describe('buildPurchaseOrderQuery', () => {
    it('sends only what the server validates, empties dropped, dates cut to the day', () => {
        expect(
            buildPurchaseOrderQuery({
                vendor_id: 4,
                item_id: undefined,
                from: '2026-08-01T00:00:00Z',
                to: '2026-08-31',
                q: '  PO-12 ',
                sort: '-order_date',
                page: 2,
                per_page: 50,
            }),
        ).toEqual({ vendor_id: 4, from: '2026-08-01', to: '2026-08-31', q: 'PO-12', sort: '-order_date', page: 2, per_page: 50 });
        expect(buildPurchaseOrderQuery({ q: '   ' })).toEqual({});
        expect(buildPurchaseOrderQuery(undefined)).toEqual({});
    });

    it('one status goes as the bare enum (the pre-Phase-6 validator), several as an array', () => {
        expect(buildPurchaseOrderQuery({ status: ['sent'] })).toEqual({ status: 'sent' });
        expect(buildPurchaseOrderQuery({ status: ['sent', 'partially_received'] })).toEqual({ status: ['sent', 'partially_received'] });
        expect(buildPurchaseOrderQuery({ status: [] })).toEqual({});
    });

    it('drops a status the server does not know and a sort it would 422', () => {
        expect(buildPurchaseOrderQuery({ status: ['sent', 'bogus' as never] })).toEqual({ status: 'sent' });
        expect(buildPurchaseOrderQuery({ sort: 'vendor_name' })).toEqual({});
        expect(buildPurchaseOrderQuery({ sort: 'expected_date' })).toEqual({ sort: 'expected_date' });
    });

    it('a per_page above the list\'s ceiling (1000) is not sent', () => {
        expect(buildPurchaseOrderQuery({ per_page: 1000 })).toEqual({ per_page: 1000 });
        expect(buildPurchaseOrderQuery({ per_page: 1001 })).toEqual({});
    });
});

describe('hasActiveFilters', () => {
    it('is true only when the list has been NARROWED — sort and paging are not filters', () => {
        expect(hasActiveFilters({})).toBe(false);
        expect(hasActiveFilters({ sort: '-id', page: 3, per_page: 50 })).toBe(false);
        expect(hasActiveFilters({ status: ['draft'] })).toBe(true);
        expect(hasActiveFilters({ q: 'alpha' })).toBe(true);
    });
});

describe('URL round trip', () => {
    it('writes the filters into the URL and reads them back unchanged; page 1 is not written', () => {
        const filters = { status: ['sent', 'closed'] as const, vendor_id: 4, from: '2026-08-01', q: 'alpha', page: 1 };
        const params = searchParamsFromFilters(filters as never);
        expect(params.getAll('status')).toEqual(['sent', 'closed']);
        expect(params.get('vendor_id')).toBe('4');
        expect(params.get('page')).toBeNull();
        expect(filtersFromSearchParams(params)).toEqual({ status: ['sent', 'closed'], vendor_id: 4, from: '2026-08-01', q: 'alpha' });
    });

    it('drops typos rather than sending them to a 422: bad numbers, unknown statuses, page 0', () => {
        const params = new URLSearchParams('vendor_id=abc&page=0&status=nope&status=draft&per_page=5000');
        expect(filtersFromSearchParams(params)).toEqual({ status: ['draft'] });
    });

    it('reads a comma-joined status too, since a hand-typed link may spell it that way', () => {
        expect(filtersFromSearchParams(new URLSearchParams('status=sent,closed'))).toEqual({ status: ['sent', 'closed'] });
    });
});

describe('unwrapTraceResponse / flattenTrace', () => {
    const trace: PurchaseOrderTrace = {
        receipts: [
            {
                id: 5,
                receipt_key: 'k-1',
                received_date: '2026-08-11 10:00',
                warehouse: { id: 1, name: 'Main Store' },
                lines: [{ id: 50, item: { id: 1, name: 'ITEM_A' }, quantity: '40.0000', lots: [{ id: 500, supplier_lot_no: 'L1' }] }],
                stock_movements: [{ id: 900, purpose: 'Receipt', quantity: '40.0000' }],
            },
        ],
        consumption: [{ shift_production_entry_id: 77, quantity: '10.0000' }],
    };

    it('reads the trace bare or wrapped in data', () => {
        expect(unwrapTraceResponse(trace)).toBe(trace);
        expect(unwrapTraceResponse({ data: trace })).toBe(trace);
    });

    it('flattens lots and movements nested under receipts or lines into one list each, keeping the receipt they came from', () => {
        const flat = flattenTrace(trace);
        expect(flat.receipts?.map((r) => r.id)).toEqual([5]);
        expect(flat.lots?.map((l) => [l.id, l.receipt_id])).toEqual([[500, 5]]);
        expect(flat.movements?.map((m) => [m.id, m.receipt_id])).toEqual([[900, 5]]);
        expect(flat.consumption?.map((c) => c.shift_production_entry_id)).toEqual([77]);
    });

    it('prefers top-level collections when the server sends them, without duplicating nested ones by id', () => {
        const flat = flattenTrace({
            ...trace,
            material_lots: [{ id: 500, supplier_lot_no: 'L1', goods_receipt_note_id: 5 }, { id: 501, supplier_lot_no: 'L2' }],
            movements: [{ id: 900, purpose: 'Receipt', quantity: '40.0000' }, { id: 901, purpose: 'Consumption', quantity: '10.0000' }],
        });
        expect(flat.lots?.map((l) => l.id)).toEqual([500, 501]);
        expect(flat.movements?.map((m) => m.id)).toEqual([900, 901]);
    });

    it('an absent collection stays undefined (could not be read) — an empty one is [] (measured, none)', () => {
        const flat = flattenTrace({ receipts: [] });
        expect(flat.receipts).toEqual([]);
        expect(flat.lots).toEqual([]);
        expect(flat.consumption).toBeUndefined();
        expect(flattenTrace({}).receipts).toBeUndefined();
    });
});

describe('flattenTrace — the server\'s nested shape (PurchaseOrderTraceService)', () => {
    // receipts[].lines[].stock_movements and .material_lots, movements and
    // lots carrying no item of their own — the item is the line's.
    const nested: PurchaseOrderTrace = {
        purchase_order: { id: 3, document_number: 'PO-3', status: 'partially_received' },
        lines: [{ id: 10, item: { id: 1, name: 'ITEM_A' }, quantity: '100.0000', quantity_received: '40.0000', remaining: '60.0000', rate_withheld: 'FC-06' }],
        receipts: [
            {
                id: 5,
                document_number: 'GRN-5',
                receipt_key: 'k-1',
                received_date: '2026-08-11T10:00:00+05:30',
                warehouse: { id: 1, code: 'MAIN', name: 'Main Store' },
                lines: [
                    {
                        id: 50,
                        purchase_order_line_id: 10,
                        item: { id: 1, name: 'ITEM_A' },
                        quantity: '40.0000',
                        rate_withheld: 'FC-06',
                        stock_movements: [{ id: 900, type: 'in', purpose: 'Receipt', quantity: '40.0000', reference: 'GRN for PO #3', warehouse_id: 1, movement_date: '2026-08-11T10:00:00+05:30', rate_withheld: 'FC-06' }],
                        material_lots: [{ id: 500, supplier_lot_no: 'L1', bag_count: 2, total_received_kg: '40.0000', rate_withheld: 'FC-06', bags: [] }],
                    },
                ],
            },
        ],
        consumption: [],
    };

    it('reads movements nested under each receipt LINE, stamping the line\'s item, the receipt and its store on each', () => {
        const flat = flattenTrace(nested);
        expect(flat.movements?.map((m) => [m.id, m.receipt_id, m.item?.name, m.warehouse?.name])).toEqual([[900, 5, 'ITEM_A', 'Main Store']]);
        expect(flat.lots?.map((l) => [l.id, l.receipt_id, l.item?.name])).toEqual([[500, 5, 'ITEM_A']]);
        expect(flat.consumption).toEqual([]);
    });

    it('keeps an item a lot or movement names for itself', () => {
        const flat = flattenTrace({
            receipts: [
                {
                    id: 5,
                    received_date: null,
                    warehouse: null,
                    lines: [{ id: 50, item: { id: 1, name: 'ITEM_A' }, quantity: '1', stock_movements: [{ id: 900, purpose: 'Receipt', quantity: '1', item: { id: 2, name: 'ITEM_B' } }] }],
                },
            ],
        });
        expect(flat.movements?.[0].item?.name).toBe('ITEM_B');
    });
});

describe('consumptionRow', () => {
    it('reads the server\'s (segment, item) row: entry, batch, machine, loaded-from-this-order, the day-bin figure and the issues', () => {
        const row: TraceConsumption = {
            shift_production_entry: { id: 77, batch_number: 'B-77', batch_status: 'completed', production_date: '2026-08-12', work_center: { id: 2, code: 'M2', name: 'Machine 2' } },
            item: { id: 1, name: 'ITEM_A' },
            loaded_kg_from_this_order: '25.0000',
            day_bin: { opening_kg: '0.0000', loaded_kg: '25.0000', returned_kg: '0.0000', closing_kg: '5.0000', consumed_kg: '20.0000' },
            stock_issues: [{ id: 901, purpose: 'Consumption', quantity: '20.0000' }],
        };
        expect(consumptionRow(row)).toEqual({
            entry_id: 77,
            batch: 'B-77',
            batch_status: 'completed',
            production_date: '2026-08-12',
            machine: 'Machine 2',
            item: 'ITEM_A',
            loaded_kg: '25.0000',
            consumed_kg: '20.0000',
            consumed_words: '20.0000',
            day_bin: row.day_bin,
            issues: row.stock_issues,
            issued_qty: '20.0000',
        });
    });

    it('says when the day-bin figure is not computable yet — a null consumed_kg is "no closing count yet", never 0', () => {
        const normalised = consumptionRow({
            shift_production_entry: { id: 78, batch_number: null },
            day_bin: { opening_kg: '0.0000', loaded_kg: '25.0000', returned_kg: '0.0000', closing_kg: null, consumed_kg: null },
            stock_issues: [],
        });
        expect(normalised.consumed_kg).toBeNull();
        expect(normalised.consumed_words).toBe('not computable yet (no closing count)');
        expect(normalised.batch).toBeNull();
        expect(normalised.machine).toBeNull();
        expect(normalised.issued_qty).toBe('0.0000');
    });

    it('reads a narrower backend\'s flat spelling too', () => {
        const normalised = consumptionRow({ shift_production_entry_id: 9, batch_no: 'B-9', quantity: '3.5000', machine: 'M1', item: { id: 1, name: 'ITEM_A' } });
        expect(normalised).toMatchObject({ entry_id: 9, batch: 'B-9', machine: 'M1', consumed_kg: '3.5000', consumed_words: '3.5000', item: 'ITEM_A' });
    });

    it('sums the issues at four places without float drift', () => {
        const normalised = consumptionRow({ stock_issues: [{ id: 1, purpose: 'Consumption', quantity: '0.1000' }, { id: 2, purpose: 'Consumption', quantity: '0.2000' }] });
        expect(normalised.issued_qty).toBe('0.3000');
    });
});

describe('loadWords / lotLoadSummary', () => {
    it('words one load: where it was poured (machine or the common input), under which batch, how much', () => {
        expect(loadWords({ id: 1, work_center: { id: 2, name: 'Machine 2' }, shift_production_entry_id: 77, batch_number: 'B-77', quantity_kg: '25.0000' }))
            .toBe('25.0000 kg → Machine 2 · batch B-77');
        expect(loadWords({ id: 2, work_center: null, shift_production_entry_id: null, quantity_kg: '10.0000' }))
            .toBe('10.0000 kg → common input · outside any batch');
        expect(loadWords({ id: 3, work_center: null, shift_production_entry_id: 78, batch_number: null, quantity_kg: '1.0000' }))
            .toBe('1.0000 kg → common input · entry #78');
    });

    it('sums a lot\'s loads across its bags — how much of this lot has left the store, and into how many pours', () => {
        const lot: TraceLot = {
            id: 500,
            bag_count: 2,
            bags: [
                { id: 1, loads: [{ id: 1, shift_production_entry_id: 77, quantity_kg: '25.0000' }] },
                { id: 2, loads: [{ id: 2, shift_production_entry_id: 77, quantity_kg: '10.0000' }, { id: 3, shift_production_entry_id: null, quantity_kg: '5.0000' }] },
            ],
        };
        expect(lotLoadSummary(lot)).toEqual({ bags: 2, loads: 3, loaded_kg: '40.0000' });
        expect(lotLoadSummary({ id: 501, bag_count: 3 })).toEqual({ bags: 3, loads: 0, loaded_kg: '0.0000' });
        expect(lotLoadSummary({ id: 502 })).toEqual({ bags: 0, loads: 0, loaded_kg: '0.0000' });
    });
});

describe('revisionLines', () => {
    it('reads the snapshot rows whether the server calls them lines or lines_json', () => {
        const rows = [{ item_id: 1, quantity: '1.0000' }];
        expect(revisionLines({ id: 1, revision_no: 1, reason: null, created_at: null, lines: rows })).toBe(rows);
        expect(revisionLines({ id: 1, revision_no: 1, reason: null, created_at: null, lines_json: rows })).toBe(rows);
        expect(revisionLines({ id: 1, revision_no: 1, reason: null, created_at: null })).toEqual([]);
    });
});
