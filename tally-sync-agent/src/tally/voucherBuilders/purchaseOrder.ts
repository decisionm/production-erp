import { envelope, escapeXml, toTallyDate } from './xmlHelpers';

/**
 * Matches TallySyncService::enqueuePurchaseOrder()'s payload shape (Phase 6).
 * Every string figure is a plain positive decimal as the cloud writes it
 * ("100.0000") — the SIGNS Tally wants are this builder's job (see below).
 */
export interface PurchaseOrderSchedule {
    /**
     * ISO date (YYYY-MM-DD) — one Tally ORDERDUEDATE allocation. NULL on the
     * cloud's REMAINDER allocation of an under-scheduled line on an order
     * without an expected date: emitted with no ORDERDUEDATE at all.
     */
    due_date: string | null;
    quantity: string;
    /** This allocation's share of the line amount; the cloud makes them sum to the line exactly. */
    amount: string;
}

export interface PurchaseOrderLine {
    /** The exact Tally stock-item name (a Tally-sourced item's name IS the Tally name). */
    item: string;
    quantity: string;
    rate: string;
    amount: string;
    /**
     * The item's Tally unit SYMBOL — only if the ERP can vouch it is Tally's
     * (Q40). Absent today: the cloud sends no `unit`, and the bare-decimal
     * form is emitted (the form the live Stock Journals already post with).
     */
    unit?: string | null;
    /**
     * Zero or more due-date allocations; empty → one allocation, no
     * ORDERDUEDATE. When they promise LESS than the line, the cloud appends
     * the remainder as one more row (due_date = the order's expected date,
     * or null) — and this builder tops the line up itself if that row is
     * missing (see the docblock's remainder rule).
     */
    schedules: PurchaseOrderSchedule[];
}

export interface PurchaseOrderPayload {
    voucher_type: 'Purchase Order';
    /** ISO order date. */
    voucher_date: string;
    /** The ERP reference "PO-{id}" (Q35(c) pending — see the docblock). */
    voucher_number: string;
    /** 'erp' today; informational — the builder does not read it. */
    voucher_number_source?: string;
    /** The vendor's Tally ledger name (vendors.tally_ledger_name) — REQUIRED, never defaulted. */
    party_ledger: string | null;
    party_gstin: string | null;
    /** TallyLedgerRole::Purchase's mapped ledger — REQUIRED, never defaulted. */
    purchase_ledger: string | null;
    /**
     * The one Tally company (tally-sync.purchase_orders_allowed_company on
     * the cloud, fail-closed there if blank — surrounding whitespace is
     * trimmed there, once, before it is treated as configured or written
     * here) this voucher may be built and posted against — REQUIRED. This
     * agent does NOT repeat that trim or fold case: whatever arrives here is
     * checked verbatim, BYTE-FOR-BYTE, against this agent's own configured
     * `tallyCompanyName` before the XML is built at all — a blank value or
     * any mismatch is a PERMANENT failure for this voucher, never retried
     * automatically, because posting to the wrong Tally company is not a
     * transient error to recover from.
     */
    allowed_company: string | null;
    /** The one Tally godown every allocation sits under — REQUIRED. */
    godown: string | null;
    reference: string | null;
    narration: string | null;
    lines: PurchaseOrderLine[];
    /** The sum of the line amounts, as the cloud computed it. */
    total_amount?: string;
}

/**
 * DERIVED FROM THE STRUCTURE OF 107 REAL PURCHASE ORDER EXPORTS — NOT YET POSTED TO A REAL TALLY (flag off; owner gate Q35)
 *
 * The shape below is measured, tag by tag, on the 107 'Purchase Order'
 * vouchers in the factory's own Day Book export of 12-Aug-2026 (read
 * locally, structure only; the raw exports are evidence outside the repo —
 * Q38 — and no value from them appears here or in the tests). It has never
 * been sent to a real Tally: the cloud stages a Purchase Order only while
 * `tally-sync.purchase_orders_enabled` is on, and that flag is OFF until the
 * owner opens the gate (Q35(d)). The first live post is the check on the
 * rules below that the exports could not settle (ORDERDUEDATE JD, unit
 * suffix). docs/tally-sync/PO-VOUCHER-CONTRACT.md is the structure-only
 * contract this file implements.
 *
 * WHY IT IS SAFE TO POST — BY TYPE, NOT BY OMISSION (DEC-20260812-002). A
 * Purchase Order is an ORDER voucher: Tally posts it to neither accounts nor
 * stock, and it does that BECAUSE OF THE VOUCHER TYPE — VCHTYPE and
 * VOUCHERTYPENAME 'Purchase Order' with ISINVOICE No (107/107 real vouchers;
 * no ISORDER, no ORDERTYPE, no DESTINATIONGODOWNNAME anywhere) — not
 * because any ledger block is left out. Every real order carries the party
 * ledger (LEDGERENTRIES.LIST) and the purchase ledger per line
 * (ACCOUNTINGALLOCATIONS.LIST) and still moves nothing, so this builder
 * carries them too. tests/purchaseOrder.test.js asserts the type and
 * ISINVOICE; the cloud half of the same proof (one queue row, no stock, no
 * lot, no journal) is PurchaseOrderTallyStagingTest.
 *
 * SIGNS — measured on the exports (all counts on the 105 non-cancelled
 * vouchers / 199 inventory lines / 232 allocations), emitted verbatim:
 *   ALLINVENTORYENTRIES.LIST      ISDEEMEDPOSITIVE Yes · AMOUNT NEGATIVE (−rate×qty)   199/199
 *   BATCHALLOCATIONS.LIST         AMOUNT NEGATIVE, = the line's (1 allocation) or
 *                                 summing to it (several)                            232/232
 *   ACCOUNTINGALLOCATIONS.LIST    ISDEEMEDPOSITIVE Yes · AMOUNT NEGATIVE = the line's  199/199
 *   party LEDGERENTRIES.LIST      ISDEEMEDPOSITIVE No · ISPARTYLEDGER Yes ·
 *                                 AMOUNT POSITIVE = −(sum of every other amount)     105/105
 * i.e. Tally's usual "debit negative, credit positive": the goods and the
 * purchase ledger are the debit side, the vendor the credit side. With no
 * tax and no rounding line (below) the party amount is exactly the sum of
 * the line amounts, and the voucher balances.
 *
 * WHAT IS EMITTED, IN THE EXPORTS' OWN ORDER (the tags whose values the ERP
 * holds; the rest of Tally's ~250 export tags are Tally's own bookkeeping —
 * GUIDs, ALTERIDs, GST status flags — and are never emitted):
 *   <VOUCHER VCHTYPE="Purchase Order" ACTION="Create" OBJVIEW="Invoice Voucher View">
 *     DATE · PARTYGSTIN? · VOUCHERTYPENAME · PARTYNAME · PARTYLEDGERNAME ·
 *     VOUCHERNUMBER · REFERENCE? · BASICBASEPARTYNAME · NARRATION ·
 *     PERSISTEDVIEW=Invoice Voucher View · EFFECTIVEDATE · ISCANCELLED=No ·
 *     USETRACKINGNUMBER=No · ISINVOICE=No
 *     ALLINVENTORYENTRIES.LIST × line:
 *       STOCKITEMNAME · ISDEEMEDPOSITIVE · RATE · AMOUNT · ACTUALQTY · BILLEDQTY
 *       BATCHALLOCATIONS.LIST × schedule (or ONE for the whole line):
 *         GODOWNNAME · BATCHNAME=Primary Batch · ORDERNO=VOUCHERNUMBER · AMOUNT ·
 *         ACTUALQTY · BILLEDQTY · ORDERDUEDATE JD="…" P="…">…</ORDERDUEDATE>?
 *       ACCOUNTINGALLOCATIONS.LIST: LEDGERNAME=purchase ledger · ISDEEMEDPOSITIVE · AMOUNT
 *     LEDGERENTRIES.LIST: LEDGERNAME=party ledger · ISDEEMEDPOSITIVE · ISPARTYLEDGER · AMOUNT
 *   PARTYNAME and BASICBASEPARTYNAME repeat PARTYLEDGERNAME (105/105 and 104/105
 *   of the live vouchers; the 2 cancelled ones name no party at all — none of
 *   the three tags, no PARTYGSTIN). NARRATION is the agent's convention (no
 *   real order has one).
 *
 * WHAT IS DELIBERATELY NOT EMITTED, AND WHY:
 *   tax ledger line     105/107 real orders carry one; the ERP computes no GST
 *                       on a purchase order and the ledger's NAME is the
 *                       owner's to give (Q35(e)) — never invented.
 *   rounding line       same: no computation, no name.
 *   TRACKINGNUMBER      present on every allocation, never equal to ORDERNO;
 *                       USETRACKINGNUMBER is No on all 107 — nothing to track by.
 *   INDENTNO            no indent in the ERP.
 *   PLACEOFSUPPLY, GST* the state NAME Tally wants is not something the ERP
 *                       holds as a Tally value.
 *   BASICDUEDATEOFPYMT  free text (" N Days") — not a date the ERP knows.
 *   unit suffix         emitted ONLY when the payload names `unit`; the cloud
 *                       sends none today (Q40 — Item.uom is not proven to be
 *                       the Tally symbol), so ACTUALQTY/BILLEDQTY are the bare
 *                       decimal and RATE has no "/unit" — the form the live
 *                       Stock Journals already post with.
 *
 * ORDERDUEDATE. Real allocations carry BOTH JD (an integer) and P (a
 * "d-Mmm-yy" date), with the element's text repeating P (232/232), and JD
 * and P do NOT agree consistently in the exports:
 * JD == Excel-serial(P) − 1 (days since 1899-12-31) on 130 of 232 (56%);
 * of the 102 that do not, 97 equal Excel-serial(the voucher's own DATE) − 1
 * — the ORDER date, not the due date — and 5 are neither; 13 JD values pair
 * with more than one P. They are therefore not a formatting pair, and this
 * builder derives BOTH from the ONE due date the ERP holds: JD =
 * excelSerial(due) − 1, P = "d-Mmm-yy" (no zero padding, 3-letter
 * Title-case month, 2-digit year). The first owner-gated live post is the
 * check (if Tally re-derives one from the other, either pattern in the
 * exports is explained; if it does not, the first live voucher's due dates
 * are read back before a second is sent). A line with no schedule at all
 * gets ONE allocation for the whole line and NO ORDERDUEDATE — a due date
 * is never made up.
 *
 * THE REMAINDER RULE (allocations always sum to the line). One allocation
 * per schedule at ITS OWN quantity and amount; when the schedules promise
 * LESS than the line, one more allocation for the rest — quantity = line −
 * Σ schedules, amount = line amount − Σ schedule amounts (so any rounding
 * lands on the remainder), and NO ORDERDUEDATE unless the cloud dated it
 * with the order's expected date. The cloud (TallySyncService::
 * enqueuePurchaseOrder) already appends that row; this builder appends it
 * only if it is missing — the second lock on the same door, so a payload
 * from before the rule cannot post allocations that do not add up to the
 * line. Nothing is invented: the same godown, the same order number, no
 * date. A line whose schedules cover it exactly gets no extra row.
 *
 * VOUCHERNUMBER. Q35(c) — whose number is authoritative — is PENDING; the
 * staged default is the ERP reference "PO-{id}" the cloud sends, and
 * ORDERNO on every allocation repeats it (221/232 real allocations do).
 * That may change BEFORE the first live write; nothing here assumes a
 * Tally numbering.
 *
 * NAMES ARE NEVER INVENTED. A payload without a party ledger, a purchase
 * ledger or a godown THROWS — there is no default ledger, no default
 * godown; the cloud already refuses to stage such an order, and this is
 * the second lock on the same door.
 */
export function buildPurchaseOrderXml(payload: PurchaseOrderPayload, companyName: string): string {
    requireAllowedCompany(payload.allowed_company, companyName);
    const partyLedger = required(payload.party_ledger, 'party_ledger (the vendor\'s Tally ledger name)');
    const purchaseLedger = required(payload.purchase_ledger, 'purchase_ledger (the Purchase ledger role mapping)');
    const godown = required(payload.godown, 'godown (the Tally godown the order allocates to)');
    if (!Array.isArray(payload.lines) || payload.lines.length === 0) {
        throw new Error('Purchase Order payload has no lines — nothing to order');
    }

    const orderNo = payload.voucher_number;
    const inventory = payload.lines.map((line) => inventoryEntry(line, godown, orderNo, purchaseLedger)).join('\n');

    // The vendor's side balances the goods exactly (no tax, no rounding):
    // the positive sum of every negative line amount, computed exactly on
    // decimal strings — never a float. The cloud's total_amount, when it
    // is there, must agree; a payload that disagrees with itself is not
    // posted.
    const total = sumDecimals(payload.lines.map((line) => line.amount));
    if (payload.total_amount !== undefined && payload.total_amount !== null && !sameDecimal(payload.total_amount, total)) {
        throw new Error(
            `Purchase Order payload total_amount (${payload.total_amount}) does not equal the sum of its line amounts (${total})`,
        );
    }

    const gstin = payload.party_gstin ? `\n            <PARTYGSTIN>${escapeXml(payload.party_gstin)}</PARTYGSTIN>` : '';
    const reference = payload.reference ? `\n            <REFERENCE>${escapeXml(payload.reference)}</REFERENCE>` : '';
    const date = toTallyDate(payload.voucher_date);

    const message = `          <VOUCHER VCHTYPE="Purchase Order" ACTION="Create" OBJVIEW="Invoice Voucher View">
            <DATE>${date}</DATE>${gstin}
            <VOUCHERTYPENAME>Purchase Order</VOUCHERTYPENAME>
            <PARTYNAME>${escapeXml(partyLedger)}</PARTYNAME>
            <PARTYLEDGERNAME>${escapeXml(partyLedger)}</PARTYLEDGERNAME>
            <VOUCHERNUMBER>${escapeXml(orderNo)}</VOUCHERNUMBER>${reference}
            <BASICBASEPARTYNAME>${escapeXml(partyLedger)}</BASICBASEPARTYNAME>
            <NARRATION>${escapeXml(payload.narration ?? '')}</NARRATION>
            <PERSISTEDVIEW>Invoice Voucher View</PERSISTEDVIEW>
            <EFFECTIVEDATE>${date}</EFFECTIVEDATE>
            <ISCANCELLED>No</ISCANCELLED>
            <USETRACKINGNUMBER>No</USETRACKINGNUMBER>
            <ISINVOICE>No</ISINVOICE>
${inventory}
            <LEDGERENTRIES.LIST>
              <LEDGERNAME>${escapeXml(partyLedger)}</LEDGERNAME>
              <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
              <ISPARTYLEDGER>Yes</ISPARTYLEDGER>
              <AMOUNT>${escapeXml(total)}</AMOUNT>
            </LEDGERENTRIES.LIST>
          </VOUCHER>`;

    return envelope(companyName, message);
}

/**
 * One ordered line: the item, its allocations per due date, and its
 * purchase-ledger allocation. The purchase ledger is voucher-level in the
 * payload (one role — Q39, a ledger per rate, is pending) and is written
 * onto every line's ACCOUNTINGALLOCATIONS as the exports do; a per-line
 * ledger would be a payload change, not a shape change.
 */
function inventoryEntry(line: PurchaseOrderLine, godown: string, orderNo: string, purchaseLedger: string): string {
    const unit = line.unit && line.unit.trim() !== '' ? line.unit.trim() : null;
    const qty = quantity(line.quantity, unit);
    const rate = unit ? `${line.rate}/${unit}` : line.rate;
    const amount = negate(line.amount);

    const schedules = withRemainder(line);
    const allocations = (
        schedules.length > 0
            ? schedules.map((schedule) => allocation(godown, orderNo, negate(schedule.amount), quantity(schedule.quantity, unit), schedule.due_date ?? null))
            : [allocation(godown, orderNo, amount, qty, null)]
    ).join('');

    return `            <ALLINVENTORYENTRIES.LIST>
              <STOCKITEMNAME>${escapeXml(line.item)}</STOCKITEMNAME>
              <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>
              <RATE>${escapeXml(rate)}</RATE>
              <AMOUNT>${escapeXml(amount)}</AMOUNT>
              <ACTUALQTY>${escapeXml(qty)}</ACTUALQTY>
              <BILLEDQTY>${escapeXml(qty)}</BILLEDQTY>${allocations}
              <ACCOUNTINGALLOCATIONS.LIST>
                <LEDGERNAME>${escapeXml(purchaseLedger)}</LEDGERNAME>
                <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>
                <AMOUNT>${escapeXml(amount)}</AMOUNT>
              </ACCOUNTINGALLOCATIONS.LIST>
            </ALLINVENTORYENTRIES.LIST>`;
}

/**
 * The line's schedules plus, when they promise LESS than the line and the
 * cloud did not already append it, ONE undated remainder allocation
 * (quantity = line − Σ, amount = line amount − Σ) — the remainder rule in
 * the docblock. Exact decimal strings throughout; a line with no schedule
 * is left empty (the caller emits the one whole-line allocation).
 */
export function withRemainder(line: PurchaseOrderLine): PurchaseOrderSchedule[] {
    const schedules = Array.isArray(line.schedules) ? line.schedules : [];
    if (schedules.length === 0) {
        return schedules;
    }

    const scheduledQuantity = sumDecimals(schedules.map((schedule) => schedule.quantity));
    const remainderQuantity = sumDecimals([line.quantity, negate(scheduledQuantity)]);
    if (!isPositive(remainderQuantity)) {
        return schedules;
    }

    const scheduledAmount = sumDecimals(schedules.map((schedule) => schedule.amount));
    const remainderAmount = sumDecimals([line.amount, negate(scheduledAmount)]);

    return [...schedules, { due_date: null, quantity: remainderQuantity, amount: remainderAmount }];
}

function isPositive(value: string): boolean {
    const trimmed = value.trim();

    return !trimmed.startsWith('-') && !/^0*(\.0*)?$/.test(trimmed);
}

/** One BATCHALLOCATIONS.LIST — a due-date window, or the whole line when there is none. */
function allocation(godown: string, orderNo: string, signedAmount: string, qty: string, dueDate: string | null): string {
    // The element's TEXT repeats P (232/232 real allocations) — three
    // spellings of one date, all derived from the one the ERP holds.
    const due = dueDate
        ? `\n                <ORDERDUEDATE JD="${orderDueJd(dueDate)}" P="${orderDueP(dueDate)}">${orderDueP(dueDate)}</ORDERDUEDATE>`
        : '';

    return `
              <BATCHALLOCATIONS.LIST>
                <GODOWNNAME>${escapeXml(godown)}</GODOWNNAME>
                <BATCHNAME>Primary Batch</BATCHNAME>
                <ORDERNO>${escapeXml(orderNo)}</ORDERNO>
                <AMOUNT>${escapeXml(signedAmount)}</AMOUNT>
                <ACTUALQTY>${escapeXml(qty)}</ACTUALQTY>
                <BILLEDQTY>${escapeXml(qty)}</BILLEDQTY>${due}
              </BATCHALLOCATIONS.LIST>`;
}

/**
 * " 100.0000 Kgs" (leading space, decimal, space, unit) when a unit is
 * known — the exports' form — else the bare decimal (Q40).
 */
export function quantity(value: string, unit: string | null): string {
    return unit ? ` ${value} ${unit}` : value;
}

/**
 * ORDERDUEDATE's JD: Excel serial of the date minus one — days since
 * 1899-12-31 — the modal rule in the exports (see the docblock). Pure
 * calendar arithmetic in UTC; no time zone can move a date-only value.
 */
export function orderDueJd(isoDate: string): number {
    const [y, m, d] = isoDate.slice(0, 10).split('-').map((part) => Number(part));
    if (!y || !m || !d) {
        throw new Error(`ORDERDUEDATE needs an ISO date, got "${isoDate}"`);
    }
    const msPerDay = 86_400_000;
    const excelEpoch = Date.UTC(1899, 11, 30); // Excel serial 0
    const serial = Math.round((Date.UTC(y, m - 1, d) - excelEpoch) / msPerDay);

    return serial - 1;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** ORDERDUEDATE's P: "d-Mmm-yy" — no zero padding, Title-case month, two-digit year. */
export function orderDueP(isoDate: string): string {
    const [y, m, d] = isoDate.slice(0, 10).split('-').map((part) => Number(part));
    if (!y || !m || !d || m < 1 || m > 12) {
        throw new Error(`ORDERDUEDATE needs an ISO date, got "${isoDate}"`);
    }

    return `${d}-${MONTHS[m - 1]}-${String(y % 100).padStart(2, '0')}`;
}

/** "100.0000" → "-100.0000"; a zero stays unsigned; an already-negative string is left alone. */
export function negate(value: string): string {
    const trimmed = String(value).trim();
    if (trimmed.startsWith('-')) {
        return trimmed;
    }
    if (/^0*(\.0*)?$/.test(trimmed)) {
        return trimmed;
    }

    return `-${trimmed}`;
}

/**
 * Exact decimal-string addition (BigInt over the widest scale) — the party
 * amount must equal the goods to the last digit or Tally rejects the
 * voucher as unbalanced, and floats cannot promise that.
 */
export function sumDecimals(values: string[]): string {
    const scale = Math.max(0, ...values.map((v) => (v.includes('.') ? v.trim().split('.')[1].length : 0)));
    let total = 0n;
    for (const value of values) {
        total += toScaled(value, scale);
    }
    const negative = total < 0n;
    const digits = (negative ? -total : total).toString().padStart(scale + 1, '0');
    const whole = digits.slice(0, digits.length - scale);
    const frac = scale > 0 ? `.${digits.slice(digits.length - scale)}` : '';

    return `${negative ? '-' : ''}${whole}${frac}`;
}

function toScaled(value: string, scale: number): bigint {
    const trimmed = String(value).trim();
    if (!/^-?\d+(\.\d+)?$/.test(trimmed)) {
        throw new Error(`Not a decimal string: "${value}"`);
    }
    const negative = trimmed.startsWith('-');
    const [whole, frac = ''] = (negative ? trimmed.slice(1) : trimmed).split('.');
    const scaled = BigInt(whole + frac.padEnd(scale, '0').slice(0, scale));

    return negative ? -scaled : scaled;
}

function sameDecimal(a: string, b: string): boolean {
    const scale = Math.max(
        a.includes('.') ? a.trim().split('.')[1].length : 0,
        b.includes('.') ? b.trim().split('.')[1].length : 0,
    );

    return toScaled(a, scale) === toScaled(b, scale);
}

/**
 * The last lock before a Purchase Order voucher is built at all: this
 * agent's `tallyCompanyName` (its own local settings — the company its
 * local Tally is actually open on) must equal, BYTE-FOR-BYTE, the
 * `allowed_company` the cloud staged on this voucher.
 *
 * The cloud trims SURROUNDING WHITESPACE from `purchase_orders_allowed_company`
 * exactly once, before treating it as configured/blank and before writing it
 * to the payload (config-authoring convenience, not a relaxed match) — this
 * function does not repeat that trim, and does no case-folding either: what
 * arrives HERE is compared verbatim against `companyName`. So a difference
 * that survives the cloud's one trim — internal whitespace, a case
 * difference, or (if either config value happens to carry one) leading/
 * trailing whitespace this agent did not itself add — is still the wrong
 * name to build a voucher against, never a "close enough" match. This is not
 * a claim that raw, un-normalized env-file bytes are what reach here; it is
 * that once the cloud's one normalization has run, nothing softens the
 * comparison further.
 *
 * Blank/missing `allowed_company` and any mismatch are BOTH permanent
 * failures for this voucher: the sync loop reports them as a rejection
 * (never "unverified"), so nothing here is retried automatically — a person
 * has to fix the cloud config, the agent config, or both.
 */
export function requireAllowedCompany(allowedCompany: string | null | undefined, companyName: string): void {
    if (typeof allowedCompany !== 'string' || allowedCompany.trim() === '') {
        throw new Error(
            'Purchase Order payload has no allowed_company (the allowed Testing Tally company) — refusing to build: '
            + 'this agent never posts a Purchase Order without the cloud naming the one Tally company it may post to',
        );
    }
    if (allowedCompany !== companyName) {
        throw new Error(
            `Purchase Order payload's allowed_company ("${allowedCompany}") does not match this agent's configured `
            + `Tally company ("${companyName}") — refusing to build: never posting a Purchase Order to the wrong Tally company`,
        );
    }
}

function required(value: string | null | undefined, what: string): string {
    if (typeof value !== 'string' || value.trim() === '') {
        throw new Error(`Purchase Order payload has no ${what} — refusing to build: Tally names are never invented`);
    }

    return value.trim();
}
