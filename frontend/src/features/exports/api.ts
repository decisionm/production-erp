import { api } from '@/lib/api';
import { filenameFromDisposition, refusalSentence } from './filters';
import type { ExportFile, ExportKind, ExportRun } from './types';

/**
 * The Download / Export Center's three calls. Any authenticated user may
 * ask; the catalogue is what filters — a kind is offered, and runnable,
 * only to a reader holding one of its permissions, and a blocked kind (CEC
 * until the owner's sample document exists) is listed with its reason.
 */

/** GET /exports — the kinds this reader may run, blocked ones included. */
export async function listExportKinds(): Promise<ExportKind[]> {
    const { data } = await api.get<{ data: ExportKind[] }>('/exports');
    return data.data;
}

/**
 * POST /exports/{kind} — the server reads the SAME query the list/report
 * endpoint runs, with these filters, for this reader, and streams it as
 * CSV. The bytes come back as a blob under the name the server chose
 * (Content-Disposition, `{kind}-{YYYYMMDD-HHMM}.csv` in factory time).
 * A refusal (409 blocked · 422 over the cap or invalid filters · 403 ·
 * 404) rejects with the axios error; exportErrorSentence() reads its
 * sentence.
 */
export async function runExport(kind: string, filters: Record<string, unknown>): Promise<ExportFile> {
    const response = await api.post<Blob>(`/exports/${encodeURIComponent(kind)}`, filters, {
        responseType: 'blob',
    });
    const disposition = response.headers?.['content-disposition'] as string | undefined;

    return {
        filename: filenameFromDisposition(disposition, `${kind}.csv`),
        blob: response.data,
    };
}

/** GET /exports/runs — the caller's own recent runs, newest first, refusals included. */
export async function listExportRuns(): Promise<ExportRun[]> {
    const { data } = await api.get<{ data: ExportRun[] }>('/exports/runs');
    return data.data;
}

/**
 * The server's own sentence for a failed export, word for word. Because
 * runExport() asks for a blob, an error body arrives as a Blob too — it is
 * read back as text and parsed before the sentence is taken out of it. The
 * transport's message is the last resort, never a sentence invented here.
 */
export async function exportErrorSentence(error: unknown): Promise<string> {
    const anyErr = error as
        | { response?: { status?: number; data?: unknown }; message?: string }
        | undefined;
    const fallback = anyErr?.message ?? 'unknown error';
    const data = anyErr?.response?.data;

    if (data === undefined || data === null) return fallback;

    let body: unknown = data;
    if (typeof Blob !== 'undefined' && data instanceof Blob) {
        try {
            body = JSON.parse(await data.text());
        } catch {
            return fallback;
        }
    } else if (typeof data === 'string') {
        try {
            body = JSON.parse(data);
        } catch {
            return data.trim() !== '' ? data : fallback;
        }
    }

    return refusalSentence(body, fallback);
}
