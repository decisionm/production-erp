import axios from 'axios';
import { XMLParser } from 'fast-xml-parser';
import { withTallyGate } from './gate';
import { escapeXml } from './voucherBuilders/xmlHelpers';

/**
 * Reads Tally masters (the inbound half of the sync) by exporting collections
 * from Tally's XML gateway and normalising them into the clean JSON shapes the
 * cloud's POST /tally-sync/masters endpoint expects.
 *
 * Deliberately dependency-free of electron config/logger so it can be run and
 * tested standalone against a real Tally instance. Also deliberately DEFENSIVE:
 * real client Tally data varies by version and setup, so every field is treated
 * as optional, values are sanitised, and matching is on the stable GUID — never
 * on names or an assumed structure. See docs/archive/TALLY-SYNC-MASTER-PLAN.md section 3.
 */

export interface TallyTarget {
    host: string;
    port: number;
    company: string;
}

export interface MasterNode {
    guid: string;
    name: string;
    parent: string | null;
    alter_id: number | null;
}

export interface ItemNode extends MasterNode {
    base_unit: string | null;
}

export interface LedgerNode {
    guid: string;
    name: string;
    group: string | null;
    alter_id: number | null;
    /**
     * The party's GSTIN and state, present ONLY when Tally returned them.
     * Absent (not null) when it did not — the cloud reads an absent key as
     * "leave the column alone" and an explicit null as "Tally says there is
     * none", so a wrong guess at a Tally field name costs an empty column
     * rather than wiping a GSTIN that is already right.
     */
    gstin?: string | null;
    state_name?: string | null;
    /**
     * The party's email and phone, on the SAME absent-means-leave-alone
     * contract as the two above. Measured on the live company's own
     * "All Masters" export (1742 ledgers): 78 carry a phone and 4 carry an
     * email, and of 620 Sundry Creditors exactly 1 has an email and 8 a
     * phone. These keys will therefore be absent from almost every row —
     * that is the state of the books, not a gap in this pull.
     */
    email?: string | null;
    phone?: string | null;
}

export interface MastersPayload {
    item_groups: MasterNode[];
    godowns: MasterNode[];
    ledger_groups: MasterNode[];
    ledgers: LedgerNode[];
    items: ItemNode[];
}

const parser = new XMLParser({ ignoreAttributes: false, parseTagValue: false, trimValues: true });

/**
 * Strip Tally's control-char language markers (its reserved root arrives as
 * a " Primary" byte + "Primary") and trim. Done by char code rather than
 * a control-char regex — sanitise rather than assume a clean string, since real
 * client data varies.
 */
/**
 * Decode Tally's NUMERIC CHARACTER REFERENCES before anything else looks at
 * the string.
 *
 * Added after a live outage on 31-Aug-2026. Tally exports `&#13;&#10;` on a
 * value someone pressed Enter in and `&#4;` before its reserved words, and
 * `fast-xml-parser` with `parseTagValue: false` DOES NOT DECODE THEM — so what
 * arrives is those ten characters literally, every one of them printable, and
 * clean()'s char-code strip sailed straight past it. Three of the factory's
 * 1742 ledgers carry a good GSTIN with exactly that on the end (25 characters
 * into a 15-character column) and they took the whole masters pull down with a
 * 422.
 *
 * Decoding RECOVERS the real value rather than discarding a fact the factory
 * holds.
 */
function decodeEntities(raw: string): string {
    return raw.replace(/&#(x[0-9a-f]{1,6}|\d{1,7});/gi, (_match, code: string) => {
        const point = code.toLowerCase().startsWith('x') ? parseInt(code.slice(1), 16) : parseInt(code, 10);

        // Out of range, or NUL, is dropped rather than becoming a replacement
        // character that would then read as content.
        return Number.isFinite(point) && point > 0 && point <= 0x10ffff ? String.fromCodePoint(point) : '';
    });
}

function clean(value: unknown): string {
    const raw = decodeEntities(String(value ?? ''));
    let out = '';
    for (const ch of raw) {
        if (ch.charCodeAt(0) >= 0x20) out += ch;
    }
    return out.trim();
}

/** A fetched field arrives as { '@_TYPE': ..., '#text': value } or a bare string. */
function textOf(field: unknown): string {
    if (field == null) return '';
    if (typeof field === 'object') return clean((field as Record<string, unknown>)['#text']);
    return clean(field);
}

/** Tally's reserved root ("Primary", incl. its control-char form) and blanks → no parent. */
function normalizeParent(raw: unknown): string | null {
    const p = textOf(raw);
    return p === '' || p === 'Primary' ? null : p;
}

function numberOrNull(field: unknown): number | null {
    const text = textOf(field);
    const n = Number(text);
    return text !== '' && Number.isFinite(n) ? n : null;
}

function buildExportXml(type: string, fetchFields: string, company: string): string {
    return (
        '<ENVELOPE><HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST>' +
        '<TYPE>Collection</TYPE><ID>AgentMasters</ID></HEADER><BODY><DESC>' +
        '<STATICVARIABLES><SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>' +
        `<SVCURRENTCOMPANY>${escapeXml(company)}</SVCURRENTCOMPANY></STATICVARIABLES>` +
        '<TDL><TDLMESSAGE><COLLECTION NAME="AgentMasters" ISMODIFY="No" ISFIXED="No" ' +
        `ISINITIALIZE="No" ISOPTION="No" ISINTERNAL="No"><TYPE>${type}</TYPE>` +
        `<FETCH>${fetchFields}</FETCH></COLLECTION></TDLMESSAGE></TDL></DESC></BODY></ENVELOPE>`
    );
}

async function exportCollection(target: TallyTarget, tag: string, type: string, fetchFields: string): Promise<any[]> {
    const url = `http://${target.host}:${target.port}`;
    const xml = buildExportXml(type, fetchFields, target.company);

    const { data } = await withTallyGate(() =>
        axios.post<string>(url, xml, {
            headers: { 'Content-Type': 'text/xml' },
            timeout: 60000,
            responseType: 'text',
        }),
    );

    const parsed = parser.parse(data);
    const collection = parsed?.ENVELOPE?.BODY?.DATA?.COLLECTION;
    const nodes = collection ? collection[tag] : undefined;

    if (!nodes) return [];
    return Array.isArray(nodes) ? nodes : [nodes];
}

function toMasterNode(n: any): MasterNode {
    return {
        guid: textOf(n.GUID),
        name: clean(n['@_NAME'] ?? textOf(n.NAME)),
        parent: normalizeParent(n.PARENT),
        alter_id: numberOrNull(n.ALTERID),
    };
}

export async function exportItemGroups(t: TallyTarget): Promise<MasterNode[]> {
    return (await exportCollection(t, 'STOCKGROUP', 'StockGroup', 'Name, Parent, GUID, AlterID'))
        .map(toMasterNode)
        .filter((n) => n.guid && n.name);
}

export async function exportGodowns(t: TallyTarget): Promise<MasterNode[]> {
    return (await exportCollection(t, 'GODOWN', 'Godown', 'Name, Parent, GUID, AlterID'))
        .map(toMasterNode)
        .filter((n) => n.guid && n.name);
}

export async function exportLedgerGroups(t: TallyTarget): Promise<MasterNode[]> {
    return (await exportCollection(t, 'GROUP', 'Group', 'Name, Parent, GUID, AlterID'))
        .map(toMasterNode)
        .filter((n) => n.guid && n.name);
}

/**
 * Candidate Tally spellings for a ledger's GSTIN and its state, tried in order.
 *
 * WHY A LIST AND NOT ONE NAME. No ledger master export exists anywhere in this
 * repository — the 26-Aug evidence report records that absence explicitly — so
 * the field a given Tally build returns is NOT proven. Rather than assert one
 * spelling and silently pull nothing, each candidate is read and the first
 * non-empty one wins. A field the build does not have simply yields nothing.
 *
 * The failure mode is deliberate and one-directional: guess wrong and the
 * column stays EMPTY. Nothing is ever invented, and the cloud treats an absent
 * key as "leave alone", so a bad guess cannot wipe a GSTIN already recorded.
 * The first real pull is the check on these names.
 */
const LEDGER_GSTIN_FIELDS = ['PARTYGSTIN', 'GSTIN', 'GSTREGISTRATIONNUMBER'] as const;

/**
 * MEASURED, no longer guessed. The 28-Aug ledger master export ("All Masters",
 * 1742 ledgers from the live company) settles the order: PRIORSTATENAME
 * appears 176 times, STATENAME 38, and LEDSTATENAME NEVER. The first draft of
 * this list led with LEDSTATENAME, which was wrong and cost nothing only
 * because the reader takes the first candidate that carries a value.
 *
 * The same export shows why the state is a weak source anyway: of 620 Sundry
 * Creditors, only 22 carry a state at all, while 307 carry a GSTIN. The GSTIN
 * is therefore the reliable route to the state, and the cloud derives it —
 * see ImportVendorsFromLedgers.
 */
const LEDGER_STATE_FIELDS = ['PRIORSTATENAME', 'STATENAME', 'LEDSTATENAME'] as const;

/**
 * MEASURED on the same 1742-ledger export, and ordered by what it found:
 * EMAIL appears 4 times and EMAILCC never; LEDGERMOBILE and PHONENUMBER
 * appear 78 times each, LEDGERPHONE and LEDGERCONTACT 3.
 *
 * The mobile leads the phone list because a supplier a buyer actually rings
 * is more useful than a switchboard, and the two are equally present. As with
 * the GSTIN list, the first candidate carrying a value wins and a build that
 * has none of them simply yields nothing — a wrong guess costs an empty
 * column, never a contact somebody typed into the vendor form.
 */
const LEDGER_EMAIL_FIELDS = ['EMAIL', 'EMAILCC'] as const;

const LEDGER_PHONE_FIELDS = ['LEDGERMOBILE', 'PHONENUMBER', 'LEDGERPHONE', 'LEDGERCONTACT'] as const;

/** The first candidate field that carries anything, or undefined when none does. */
function firstOf(node: Record<string, unknown>, fields: readonly string[]): string | undefined {
    for (const field of fields) {
        const value = textOf(node[field]);
        if (value !== '') return value;
    }
    return undefined;
}

export async function exportLedgers(t: TallyTarget): Promise<LedgerNode[]> {
    // A ledger's Tally "Parent" is its ledger group. The party fields are
    // requested by name — Tally returns what the FETCH names and nothing more,
    // which is why they were absent until now rather than merely unread.
    const fetch = [
        'Name',
        'Parent',
        'GUID',
        'AlterID',
        ...LEDGER_GSTIN_FIELDS,
        ...LEDGER_STATE_FIELDS,
        ...LEDGER_EMAIL_FIELDS,
        ...LEDGER_PHONE_FIELDS,
    ].join(', ');

    return (await exportCollection(t, 'LEDGER', 'Ledger', fetch))
        .map((n) => {
            // A GSTIN is fifteen characters by definition. Anything else
            // after cleaning is a field somebody typed two things into, and
            // sending it can only cost the cloud a dropped column — so it is
            // not sent. The cloud sanitises independently (TallyText); this is
            // the same rule applied at the source, not a substitute for it.
            const gstinRaw = firstOf(n as Record<string, unknown>, LEDGER_GSTIN_FIELDS);
            const gstin = gstinRaw !== undefined && gstinRaw.length !== 15 ? undefined : gstinRaw;
            const state = firstOf(n as Record<string, unknown>, LEDGER_STATE_FIELDS);
            const email = firstOf(n as Record<string, unknown>, LEDGER_EMAIL_FIELDS);
            const phone = firstOf(n as Record<string, unknown>, LEDGER_PHONE_FIELDS);

            return {
                guid: textOf(n.GUID),
                name: clean(n['@_NAME'] ?? textOf(n.NAME)),
                group: normalizeParent(n.PARENT),
                alter_id: numberOrNull(n.ALTERID),
                // Spread, so a field Tally did not return is ABSENT from the
                // payload rather than sent as null. Absent means "leave alone".
                ...(gstin !== undefined ? { gstin } : {}),
                ...(state !== undefined ? { state_name: state } : {}),
                ...(email !== undefined ? { email } : {}),
                ...(phone !== undefined ? { phone } : {}),
            };
        })
        .filter((n) => n.guid && n.name);
}

export async function exportItems(t: TallyTarget): Promise<ItemNode[]> {
    return (await exportCollection(t, 'STOCKITEM', 'StockItem', 'Name, Parent, BaseUnits, GUID, AlterID'))
        .map((n) => ({
            ...toMasterNode(n),
            base_unit: textOf(n.BASEUNITS) || null,
        }))
        .filter((n) => n.guid && n.name);
}

/**
 * List the companies present in the local Tally. Unlike the master exports this
 * needs no company loaded/selected — it's what lets Settings offer a company to
 * pick in the first place.
 */
export async function exportCompanies(target: Pick<TallyTarget, 'host' | 'port'>): Promise<string[]> {
    const url = `http://${target.host}:${target.port}`;
    const xml =
        '<ENVELOPE><HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST>' +
        '<TYPE>Collection</TYPE><ID>List of Companies</ID></HEADER><BODY><DESC>' +
        '<STATICVARIABLES><SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT></STATICVARIABLES>' +
        '<TDL><TDLMESSAGE><COLLECTION NAME="List of Companies" ISMODIFY="No">' +
        '<TYPE>Company</TYPE><NATIVEMETHOD>Name</NATIVEMETHOD></COLLECTION></TDLMESSAGE></TDL></DESC></BODY></ENVELOPE>';

    const { data } = await withTallyGate(() =>
        axios.post<string>(url, xml, {
            headers: { 'Content-Type': 'text/xml' },
            timeout: 30000,
            responseType: 'text',
        }),
    );

    const parsed = parser.parse(data);
    const collection = parsed?.ENVELOPE?.BODY?.DATA?.COLLECTION;
    let nodes = collection ? collection.COMPANY : undefined;

    if (!nodes) return [];
    if (!Array.isArray(nodes)) nodes = [nodes];

    return nodes
        .map((n: any) => clean(typeof n === 'object' ? (n['@_NAME'] ?? textOf(n.NAME)) : ''))
        .filter((name: string) => name !== '');
}

/**
 * Pull every master type from Tally into one payload for POST /tally-sync/masters.
 *
 * SEQUENTIAL on purpose — this used to be a Promise.all, five collection
 * exports hitting Tally at once. The gate would serialize them anyway, but the
 * code should say what actually happens: one request to Tally at a time, ever.
 */
export async function exportMasters(target: TallyTarget): Promise<MastersPayload> {
    const item_groups = await exportItemGroups(target);
    const godowns = await exportGodowns(target);
    const ledger_groups = await exportLedgerGroups(target);
    const ledgers = await exportLedgers(target);
    const items = await exportItems(target);

    return { item_groups, godowns, ledger_groups, ledgers, items };
}
