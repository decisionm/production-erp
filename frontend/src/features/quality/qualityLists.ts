/**
 * THE QUALITY LISTS' READING RULES — the pure half of the six server-paged
 * registers (Production QC queue, Incoming Inspections, NCRs, CAPAs,
 * Instruments, SPC Characteristics), kept out of the components so the
 * URL ↔ request round trip is pinned by an ordinary vitest and the render
 * tests can seed the exact query key a page derives from its URL.
 *
 * Each list is one `QualityList`: the `ListParamsSpec` the URL is read
 * through (module-level, as useListParams requires), the columns the
 * server sorts on (the FormRequest's SORTABLE, spelled bare ascending and
 * "-" descending), the order the service answers with when no sort is
 * asked for, and the mapping from URL params to the request the server
 * gets. Nothing here fetches or derives a factory value.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';
import type { InspectionResult } from './types';

export interface QualityList {
    /** The URL keys beyond q / page / per_page, and what each may hold. */
    spec: ListParamsSpec;
    /** The server's sortable columns (its FormRequest's SORTABLE), besides id where the list admits it. */
    sortFields: readonly string[];
    /** The service's order when the URL carries no sort — what the header arrow shows by default. */
    defaultSort: string;
}

/** A list's URL params once `sort` is known to be the one string the spec admits. */
export type SortedListParams = ListParams & { sort?: string };

function sortOptions(fields: readonly string[]): string[] {
    return fields.flatMap((field) => [field, `-${field}`]);
}

// ---------------------------------------------------------------- the queue

/** ListBatchQualityQueueRequest::SORTABLE — the queue's Batch #, Produced and Completed columns. */
export const PRODUCTION_QC_SORT_FIELDS: readonly string[] = ['batch_number', 'quantity_produced', 'production_date'];
/** ShiftProductionEntryService with oldestFirst: production date ascending — a queue is worked front to back. */
export const PRODUCTION_QC_DEFAULT_SORT = 'production_date';

/**
 * `returned` is the "Returned" switch (03-Sep-2026, Task 2 of "Returned by
 * Quality") — same `=1` spelling and same round trip as the instrument
 * register's `due` below: on the URL as `returned=1`, dropped for anything
 * else, read with `productionQcReturnedOnly` and turned into the server's
 * `returned: 1` with `productionQcListRequest`.
 */
export const PRODUCTION_QC_LIST: QualityList = {
    spec: { strings: ['returned', 'sort'], allowed: { returned: ['1'], sort: sortOptions(PRODUCTION_QC_SORT_FIELDS) } },
    sortFields: PRODUCTION_QC_SORT_FIELDS,
    defaultSort: PRODUCTION_QC_DEFAULT_SORT,
};

export type ProductionQcListParams = SortedListParams & { returned?: string };

/** The URL's `returned=1` → the server's `returned: 1`; absent → no key at all. */
export function productionQcListRequest(params: ProductionQcListParams): Record<string, string | number | undefined> {
    const { returned, ...rest } = params;

    return compactParams({ ...rest, returned: returned === '1' ? 1 : undefined });
}

/** Is the "Returned" switch on, as the URL says? */
export function productionQcReturnedOnly(params: ProductionQcListParams): boolean {
    return params.returned === '1';
}

// ------------------------------------------------------ incoming inspections

export const INSPECTION_RESULTS: readonly InspectionResult[] = ['pass', 'partial', 'fail'];
/** ListIncomingInspectionsRequest::SORTABLE. */
export const INSPECTION_SORT_FIELDS: readonly string[] = ['inspected_quantity', 'accepted_quantity', 'rejected_quantity', 'result', 'inspection_date'];
/** IncomingInspectionService: newest first. No column shows the id, so no header arrow by default. */
export const INSPECTION_DEFAULT_SORT = '-id';

export const INSPECTION_LIST: QualityList = {
    spec: {
        strings: ['result', 'sort'],
        allowed: { result: INSPECTION_RESULTS, sort: sortOptions(INSPECTION_SORT_FIELDS) },
    },
    sortFields: INSPECTION_SORT_FIELDS,
    defaultSort: INSPECTION_DEFAULT_SORT,
};

// ------------------------------------------------------------------- NCRs

/** ListNonConformanceReportsRequest::SORTABLE plus id, which the ID column shows. */
export const NCR_SORT_FIELDS: readonly string[] = ['id', 'severity', 'status', 'raised_date'];
/** NonConformanceReportService: newest first. */
export const NCR_DEFAULT_SORT = '-id';

export const NCR_LIST: QualityList = {
    spec: { strings: ['sort'], allowed: { sort: sortOptions(NCR_SORT_FIELDS) } },
    sortFields: NCR_SORT_FIELDS,
    defaultSort: NCR_DEFAULT_SORT,
};

// ------------------------------------------------------------------ CAPAs

/** ListCapasRequest::SORTABLE. `due_date` is nullable: undated CAPAs sort last either way. */
export const CAPA_SORT_FIELDS: readonly string[] = ['title', 'status', 'due_date'];
/** CapaService: newest first. */
export const CAPA_DEFAULT_SORT = '-id';

export const CAPA_LIST: QualityList = {
    spec: { strings: ['sort'], allowed: { sort: sortOptions(CAPA_SORT_FIELDS) } },
    sortFields: CAPA_SORT_FIELDS,
    defaultSort: CAPA_DEFAULT_SORT,
};

// ------------------------------------------------------------- instruments

/** ListMeasuringInstrumentsRequest::SORTABLE. */
export const INSTRUMENT_SORT_FIELDS: readonly string[] = ['code', 'name', 'location', 'next_calibration_due', 'last_calibrated_date', 'status'];
/** MeasuringInstrumentService: next calibration due first. */
export const INSTRUMENT_DEFAULT_SORT = 'next_calibration_due';

/**
 * `due` is the "Due for calibration only" switch, on the URL as `due=1`
 * exactly as the server reads it; anything else is dropped.
 */
export const INSTRUMENT_LIST: QualityList = {
    spec: { strings: ['due', 'sort'], allowed: { due: ['1'], sort: sortOptions(INSTRUMENT_SORT_FIELDS) } },
    sortFields: INSTRUMENT_SORT_FIELDS,
    defaultSort: INSTRUMENT_DEFAULT_SORT,
};

export type InstrumentListParams = SortedListParams & { due?: string };

/** The URL's `due=1` → the server's `due: 1`; absent → no key at all. */
export function instrumentListRequest(params: InstrumentListParams): Record<string, string | number | undefined> {
    const { due, ...rest } = params;

    return compactParams({ ...rest, due: due === '1' ? 1 : undefined });
}

/** Is the switch on, as the URL says? */
export function instrumentsDueOnly(params: InstrumentListParams): boolean {
    return params.due === '1';
}

// ------------------------------------------------------- SPC characteristics

/** ListSpcCharacteristicsRequest::SORTABLE. */
export const SPC_SORT_FIELDS: readonly string[] = ['name', 'unit_of_measure', 'target_value'];
/** SpcCharacteristicService: by name. */
export const SPC_DEFAULT_SORT = 'name';

/** `item_id` is the product filter the endpoint already had, kept on the URL as a positive integer. */
export const SPC_LIST: QualityList = {
    spec: { numbers: ['item_id'], strings: ['sort'], allowed: { sort: sortOptions(SPC_SORT_FIELDS) } },
    sortFields: SPC_SORT_FIELDS,
    defaultSort: SPC_DEFAULT_SORT,
};

export type SpcListParams = SortedListParams & { item_id?: number };
