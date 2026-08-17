/**
 * The post-Tally SNAPSHOT — what this agent sent to Tally and what Tally
 * answered — as a pure body builder plus an upload that can never escape.
 *
 * WHY THIS EXISTS (Phase 4). The cloud never contacts Tally and never builds
 * XML; until now it had no record of the XML the agent posted nor of Tally's
 * answer beyond a one-line error_message. After each post the agent uploads
 * {xml, sha256, tally response summary} so the Sync Control Center drawer can
 * show "What the agent sent" / "What Tally answered". It is a RECORD, made
 * after the post's own ack/fail path has run: nothing here can change what
 * reached Tally or what the cloud was told about it.
 *
 * WHY ITS OWN MODULE WITH AN INJECTED UPLOADER. sync.ts pulls in axios and
 * electron-store, so requiring it from a test downloads an Electron binary.
 * Every runtime import here is a node built-in and every other import is
 * type-only (erased at compile time), so dist/snapshot.js requires with zero
 * node_modules — same reasoning as postDecision.ts and the voucherBuilders
 * tree — and the "never throws" property is provable without a network.
 */
import { createHash } from 'node:crypto';
import { StringDecoder } from 'node:string_decoder';
import type { TallySyncEntry } from './cloudApi';
import type { TallyImportResult } from './tally/client';

/**
 * Cap on the raw Tally response text, in UTF-8 BYTES. The cloud's request
 * validates `raw` at max 65535 and stores it in a TEXT column (65535 bytes),
 * so the honest cap is bytes: a byte count under it satisfies the character
 * count too. Beyond this the summary still tells the story; the tail of a
 * huge response rarely does.
 */
export const SNAPSHOT_RAW_CAP_BYTES = 65535;

/**
 * Cap on the XML body, in UTF-8 bytes (2 MiB — the cloud request's max). An
 * XML over it is OMITTED from the upload while its sha256 still goes up, so
 * the cloud has "the agent sent this exact document" without holding it.
 */
export const SNAPSHOT_XML_CAP_BYTES = 2 * 1024 * 1024;

/** What Tally answered, or null when it never answered (inconclusive timeout). */
export interface SnapshotTally {
    success: boolean;
    created: number;
    errors: number;
    message: string | null;
    raw: string | null;
}

/**
 * The wire body of POST /tally-sync/entries/{id}/snapshot — matches
 * App\Modules\TallySync\Http\Requests\StoreTallySyncSnapshotRequest.
 * `xml` is absent (not null) when the document was over the cap.
 */
export interface SnapshotBody {
    xml?: string;
    xml_sha256: string;
    attempt: number | null;
    /** UTF-8 byte length of the XML the agent sent, whether or not the body rode along. */
    xml_bytes: number;
    tally: SnapshotTally | null;
    agent_version: string | null;
    payload_hash: string | null;
}

/** The slice of a pending entry the snapshot needs. */
export type SnapshotEntry = Pick<TallySyncEntry, 'id' | 'attempts' | 'payload_hash'>;

export interface SnapshotInput {
    entry: SnapshotEntry;
    /** The exact string handed to postVoucherXml — hashed as its UTF-8 bytes. */
    xml: string;
    /** Tally's parsed answer, or null when the request timed out with no answer. */
    tally: TallyImportResult | null;
    agentVersion: string | null;
}

export interface SnapshotDeps {
    /** The cloud call — cloudApi.uploadSnapshot in the app, a fake in tests. */
    upload: (entryId: number, body: SnapshotBody) => Promise<void>;
    warn: (message: string, meta?: Record<string, unknown>) => void;
    info?: (message: string, meta?: Record<string, unknown>) => void;
}

export function sha256Hex(text: string): string {
    return createHash('sha256').update(text, 'utf8').digest('hex');
}

/**
 * Truncate to at most `maxBytes` of UTF-8 without splitting a character.
 * StringDecoder holds back an incomplete trailing sequence, and not calling
 * end() drops it — so the result is whole characters, under the cap in bytes
 * and therefore in characters too.
 */
function capUtf8(text: string, maxBytes: number): string {
    const bytes = Buffer.from(text, 'utf8');
    if (bytes.length <= maxBytes) {
        return text;
    }

    return new StringDecoder('utf8').write(bytes.subarray(0, maxBytes));
}

function summarise(tally: TallyImportResult | null): SnapshotTally | null {
    if (tally === null) {
        return null;
    }

    const raw = typeof tally.rawResponse === 'string' ? tally.rawResponse : '';

    return {
        success: tally.success === true,
        created: Number.isFinite(tally.created) ? tally.created : 0,
        errors: Number.isFinite(tally.errors) ? tally.errors : 0,
        message: typeof tally.message === 'string' && tally.message.length > 0 ? tally.message : null,
        raw: raw.length > 0 ? capUtf8(raw, SNAPSHOT_RAW_CAP_BYTES) : null,
    };
}

/** Pure: the wire body for one post. Throws only on a malformed input (a non-string xml). */
export function buildSnapshotBody(input: SnapshotInput): SnapshotBody {
    const { entry, xml, tally, agentVersion } = input;

    const xmlBytes = Buffer.byteLength(xml, 'utf8');

    const body: SnapshotBody = {
        xml_sha256: sha256Hex(xml),
        // The 1-based ordinal of THIS post: /pending hands out `attempts` =
        // how many times Tally has refused it so far, so this post is the
        // next one. Matches the cloud's voucher.failed event `attempt` after
        // a rejection (markFailed increments before recording).
        attempt: typeof entry.attempts === 'number' ? entry.attempts + 1 : null,
        // Always sent, so a snapshot whose body was too large to upload still
        // says how large it was.
        xml_bytes: xmlBytes,
        tally: summarise(tally),
        agent_version: agentVersion ?? null,
        payload_hash: typeof entry.payload_hash === 'string' ? entry.payload_hash : null,
    };

    if (xmlBytes <= SNAPSHOT_XML_CAP_BYTES) {
        body.xml = xml;
    }

    return body;
}

/**
 * Build and upload the snapshot. NEVER throws and never rejects: a failure is
 * warned about (counts and the hash prefix only — never the XML or Tally's
 * text, which the cloud gates by reader) and swallowed. Returns whether the
 * cloud took it, for the log line and for tests.
 */
export async function sendSnapshot(input: SnapshotInput, deps: SnapshotDeps): Promise<boolean> {
    const entryId = input.entry?.id;
    let body: SnapshotBody | null = null;

    try {
        body = buildSnapshotBody(input);
        await deps.upload(entryId, body);
        deps.info?.(`Snapshot uploaded for entry #${entryId}`, {
            sha256: body.xml_sha256.slice(0, 12),
            xmlBytes: body.xml === undefined ? null : Buffer.byteLength(body.xml, 'utf8'),
            tallySuccess: body.tally?.success ?? null,
        });

        return true;
    } catch (err) {
        try {
            deps.warn(`Snapshot upload failed for entry #${entryId} — the post outcome above stands; the cloud just has no copy of the XML`, {
                message: err instanceof Error ? err.message : String(err),
                sha256: body?.xml_sha256.slice(0, 12) ?? null,
                tallySuccess: body?.tally?.success ?? null,
            });
        } catch {
            // The logger is not allowed to be the thing that breaks the loop either.
        }

        return false;
    }
}
