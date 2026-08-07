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
function clean(value: unknown): string {
    const raw = String(value ?? '');
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

export async function exportLedgers(t: TallyTarget): Promise<LedgerNode[]> {
    // A ledger's Tally "Parent" is its ledger group.
    return (await exportCollection(t, 'LEDGER', 'Ledger', 'Name, Parent, GUID, AlterID'))
        .map((n) => ({
            guid: textOf(n.GUID),
            name: clean(n['@_NAME'] ?? textOf(n.NAME)),
            group: normalizeParent(n.PARENT),
            alter_id: numberOrNull(n.ALTERID),
        }))
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
