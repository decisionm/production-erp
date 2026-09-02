import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Col, DatePicker, Modal, Row, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { type ReactElement, useState } from 'react';
import { Link } from 'react-router-dom';
import {
    getProductionReport,
    getReconciliationReport,
    getTraceabilityReport,
    listShifts,
    listWorkCenters,
} from '@/features/production/api';
import { useProductionSettings } from '@/features/production/packing';
import {
    efficiencyColumnTitle,
    lotFilterOptions,
    traceabilityFilters,
    traceabilityQueryKey,
} from '@/features/production/productionReports';
import type {
    MaterialBagStatus,
    ProductionReportRow,
    ProductionReportTotals,
    ReconciliationReportRow,
    TraceabilityReportBag,
    TraceabilityReportFeed,
    TraceabilityReportRow,
} from '@/features/production/types';
import { exportErrorSentence, runExport } from '@/features/exports/api';
import { listAllItems } from '@/features/inventory/api';
import { columnSorter, filterOptions, onFilterBy } from '@/lib/clientSort';
import { downloadBlob } from '@/lib/csv';
import { itemLabel } from '@/lib/itemLabel';
import { TABLE_STICKY } from '@/lib/tableProps';

// Bands are ruled server-side (config/production.php tolerances) — the UI
// only colour-maps them. Same mapping as ApproveProductionPage.
const BAND_TAG: Record<string, ReactElement> = {
    ok: <Tag color="green">OK</Tag>,
    watch: <Tag color="orange">Watch</Tag>,
    investigate: <Tag color="red">Investigate</Tag>,
    // Efficiency above the standard the run was measured against — not a grade
    // but a query: the produced count, hours or cavities are wrong, or the
    // standard cycle time is set slower than the machine really runs. Red, like
    // Investigate, because that is what it asks for. Without this row an
    // over-standard entry renders NO tag here (bandTag returns null for an
    // unmapped band) — history's loudest rows would go quietest.
    //
    // The label names no percentage on purpose: the boundary is deployment
    // config (production.tolerances.efficiency_over, 100 by default) and the
    // backend has already applied it to produce this band, so "over standard"
    // stays true even if a factory later allows a measurement margin.
    over_standard: <Tag color="red">Over standard</Tag>,
};

const bandTag = (band: string | null | undefined): ReactElement | null => (band ? (BAND_TAG[band] ?? null) : null);

/** "20.0000" → "20", "1.50" → "1.5", up to 2 decimals. "—" for null/unparseable. */
const fmtKg = (v: string | null | undefined): string => {
    if (v === null || v === undefined) return '—';
    const n = parseFloat(v);
    if (Number.isNaN(n)) return '—';
    return String(parseFloat(n.toFixed(2)));
};

const fmtPct = (v: number | null | undefined): string => (v === null || v === undefined ? '—' : `${v}%`);

/**
 * Same formatting as fmtKg (plain number, no unit appended), named for the
 * unit it prints — the reviewed column is PIECES, everything either side of it
 * is kilograms, and a reader of this table should not have to guess which.
 *
 * WHAT "—" MEANS IN THE REVIEWED COLUMN TODAY: not served. Not "this batch was
 * never checked". ProductionReportService emits no reviewed count at all (it
 * carries the quality rejection in kilograms only), so every row and the day
 * total print a dash regardless of what quality actually recorded — the counts
 * are on the entry and on the approval screen, just not yet in this report.
 * Once the service serves `qc_reviewed_pieces` the dash starts meaning the
 * honest thing. Until then do not read this column as evidence about a shift.
 */
const fmtPcs = fmtKg;

// Keyed by the status union, so it is exhaustive by construction: the two QC
// states were added when the union was widened to the six the backend enum has
// always carried, and the compiler is what found this map. The wording is this
// report's own ("At Machine" for a bag standing at a work centre) — the label
// bench uses features/inventory/bagStatus.ts.
const BAG_STATUS_LABEL: Record<MaterialBagStatus, string> = {
    waiting_qc: 'Waiting QC',
    in_store: 'In Store',
    in_day_bin: 'At Machine',
    consumed: 'Consumed',
    returned: 'Returned',
    rejected_qc: 'Rejected QC',
};

function useShiftOptions() {
    const { data: shifts } = useQuery({ queryKey: ['production', 'shifts'], queryFn: () => listShifts() });
    return (shifts?.data ?? []).filter((s) => s.is_active).map((s) => ({ value: s.id, label: s.name }));
}

/**
 * The tab's "Download CSV": a SERVER export (POST /exports/{kind}) of the
 * same report query, with the same filters the tab is showing, for the
 * same reader — never the rows this table happens to have rendered (Phase
 * 4.5; the client-side CSV builder that used to sit here is gone). The
 * file lands under the name the server gave it; a refusal (over the row
 * cap, an invalid filter, no permission) shows the server's own sentence.
 */
function useServerCsv(kind: string) {
    return useMutation({
        mutationFn: (filters: Record<string, unknown>) => runExport(kind, filters),
        onSuccess: (file) => downloadBlob(file.filename, file.blob),
        onError: async (error) => {
            Modal.error({ title: 'Download refused', content: await exportErrorSentence(error) });
        },
    });
}

// ---------------------------------------------------------------------------
// Production tab — one row per completed entry; pinned totals row aggregated
// as ratio-of-sums (formula dictionary row 24), never an average of row %s.
//
// Rows and the "Day total" beneath them are the SAME grain: Σ actual pieces ÷
// Σ expected pieces. They used to disagree — rows in pieces, the total in
// boxes — which put two efficiencies on one screen that could not be compared
// and nothing said so. The grain is named ONCE, in the Efficiency column
// header, with the formula spelled out in the caption below the table; the
// cells stay bare percentages so a shop-floor tablet isn't reading "(pcs)"
// twenty times down a column.
// ---------------------------------------------------------------------------

function ProductionTab() {
    const [date, setDate] = useState(dayjs().format('YYYY-MM-DD'));
    const [shiftId, setShiftId] = useState<number | undefined>(undefined);
    const [workCenterId, setWorkCenterId] = useState<number | undefined>(undefined);

    const shiftOptions = useShiftOptions();
    // Reports filter HISTORY, so retired machines must stay listed —
    // otherwise past shifts on a decommissioned machine become unfindable.
    const { data: workCenters } = useQuery({
        queryKey: ['production', 'work-centers'],
        queryFn: () => listWorkCenters(),
    });
    const machineOptions = (workCenters?.data ?? []).map((wc) => ({ value: wc.id, label: `${wc.code} — ${wc.name}` }));

    const { data: report, isLoading } = useQuery({
        queryKey: ['production', 'reports', 'production', date, shiftId ?? 'all', workCenterId ?? 'all'],
        queryFn: () => getProductionReport({ date, shift_id: shiftId, work_center_id: workCenterId }),
    });

    const rows = report?.rows ?? [];
    const totals: ProductionReportTotals | undefined = report?.totals;

    // The same three filters getProductionReport() was called with above.
    const csv = useServerCsv('production_report');
    const exportCsv = () => csv.mutate({ date, shift_id: shiftId, work_center_id: workCenterId });

    return (
        <Space direction="vertical" size={12} style={{ width: '100%' }}>
            <Row gutter={[12, 12]} align="bottom">
                <Col xs={24} sm={8} md={5}>
                    <DatePicker
                        style={{ width: '100%' }}
                        value={dayjs(date)}
                        allowClear={false}
                        onChange={(_, dateString) => setDate((dateString as string) || dayjs().format('YYYY-MM-DD'))}
                    />
                </Col>
                <Col xs={24} sm={8} md={5}>
                    <Select
                        style={{ width: '100%' }}
                        placeholder="All shifts"
                        allowClear
                        options={shiftOptions}
                        value={shiftId}
                        onChange={setShiftId}
                    />
                </Col>
                <Col xs={24} sm={8} md={6}>
                    <Select
                        style={{ width: '100%' }}
                        placeholder="All machines"
                        allowClear
                        showSearch
                        optionFilterProp="label"
                        options={machineOptions}
                        value={workCenterId}
                        onChange={setWorkCenterId}
                    />
                </Col>
                <Col xs={24} md={8} style={{ textAlign: 'right' }}>
                    <Button onClick={exportCsv} disabled={rows.length === 0} loading={csv.isPending}>Download CSV</Button>
                </Col>
            </Row>

            <Table<ProductionReportRow>
                scroll={{ x: 'max-content' }}
                sticky={TABLE_STICKY}
                size="small"
                rowKey="entry_id"
                loading={isLoading}
                pagination={false}
                dataSource={rows}
                locale={{ emptyText: 'No completed batches for this date/filter.' }}
                // The whole day's report is in the browser; each column sorts
                // on the value it shows, and the two identity columns filter.
                columns={[
                    {
                        title: 'Shift',
                        sorter: columnSorter((r: ProductionReportRow) => r.shift.name, 'text'),
                        filters: filterOptions(rows, (r) => r.shift.name),
                        onFilter: onFilterBy((r: ProductionReportRow) => r.shift.name),
                        render: (_, r) => r.shift.name,
                    },
                    {
                        title: 'Machine',
                        sorter: columnSorter((r: ProductionReportRow) => r.work_center.code, 'text'),
                        filters: filterOptions(rows, (r) => r.work_center.code),
                        onFilter: onFilterBy((r: ProductionReportRow) => r.work_center.code),
                        render: (_, r) => r.work_center.code,
                    },
                    { title: 'Item', sorter: columnSorter((r: ProductionReportRow) => itemLabel(r.item), 'text'), render: (_, r) => itemLabel(r.item) },
                    {
                        title: 'Batch',
                        dataIndex: 'batch_number',
                        sorter: columnSorter((r: ProductionReportRow) => r.batch_number, 'text'),
                        render: (v: string | null) => v ?? '—',
                    },
                    { title: 'Hrs', dataIndex: 'running_hours', align: 'right', sorter: columnSorter((r: ProductionReportRow) => r.running_hours, 'number'), render: fmtKg },
                    {
                        title: 'Exp. Boxes',
                        dataIndex: 'expected_boxes',
                        align: 'right',
                        sorter: columnSorter((r: ProductionReportRow) => r.expected_boxes, 'number'),
                        render: (v: number | null) => v ?? '—',
                    },
                    {
                        title: 'Act. Boxes',
                        dataIndex: 'actual_boxes',
                        align: 'right',
                        sorter: columnSorter((r: ProductionReportRow) => r.actual_boxes, 'number'),
                        render: (v: number | null) => v ?? '—',
                    },
                    { title: 'Act. Pcs', dataIndex: 'actual_pieces', align: 'right', sorter: columnSorter((r: ProductionReportRow) => r.actual_pieces, 'number'), render: fmtKg },
                    { title: 'Good Kg', dataIndex: 'good_production_kg', align: 'right', sorter: columnSorter((r: ProductionReportRow) => r.good_production_kg, 'number'), render: fmtKg },
                    { title: 'Rej. Kg', dataIndex: 'rejection_kg_production', align: 'right', sorter: columnSorter((r: ProductionReportRow) => r.rejection_kg_production, 'number'), render: fmtKg },
                    // The two quality columns — deliberately two, not a
                    // dashboard. NOTE THE UNITS DIFFER, which is why both say
                    // so in the heading: the reviewed count is pieces, while
                    // the rejection the books actually move is the kilogram
                    // figure the gate derives from it.
                    //
                    // "(not served)" in the heading is not decoration. The
                    // backend report emits no reviewed count yet, so this
                    // column is blank for every row — and a blank column
                    // headed plainly "Reviewed" would read as "quality
                    // reviewed nothing", which is the opposite of the truth.
                    // Drop the suffix the day the service serves the field.
                    { title: 'Reviewed (pcs, not served)', dataIndex: 'qc_reviewed_pieces', align: 'right', sorter: columnSorter((r: ProductionReportRow) => r.qc_reviewed_pieces, 'number'), render: fmtPcs },
                    { title: 'Rej. by QC (kg)', dataIndex: 'rejection_kg_qc', align: 'right', sorter: columnSorter((r: ProductionReportRow) => r.rejection_kg_qc, 'number'), render: fmtKg },
                    { title: 'Lumps Kg', dataIndex: 'lumps_kg', align: 'right', sorter: columnSorter((r: ProductionReportRow) => r.lumps_kg, 'number'), render: fmtKg },
                    {
                        sorter: columnSorter((r: ProductionReportRow) => r.efficiency_pct, 'number'),
                        // The one place the grain is named — rows and the
                        // pinned Day total are both pieces ÷ pieces — and,
                        // beside it, WHAT THE RATIO DIVIDES BY (Phase 7):
                        // the basis the server names, or the report's own
                        // documented one (the standard cycle time's expected
                        // pieces — productionReports.ts). Not the supervisor
                        // target Shift Summary measures against; a reader
                        // moving between the two pages must not assume one
                        // basis for both.
                        title: efficiencyColumnTitle(report?.efficiency_basis),
                        align: 'right',
                        render: (_, r) => (
                            <Space size={6}>
                                {fmtPct(r.efficiency_pct)}
                                {bandTag(r.efficiency_band)}
                            </Space>
                        ),
                    },
                ]}
                summary={() =>
                    totals && rows.length > 0 ? (
                        <Table.Summary fixed>
                            <Table.Summary.Row style={{ fontWeight: 600 }}>
                                <Table.Summary.Cell index={0} colSpan={5}>Day total</Table.Summary.Cell>
                                <Table.Summary.Cell index={1} align="right">{totals.expected_boxes}</Table.Summary.Cell>
                                <Table.Summary.Cell index={2} align="right">{totals.actual_boxes}</Table.Summary.Cell>
                                <Table.Summary.Cell index={3} align="right">{fmtKg(totals.actual_pieces)}</Table.Summary.Cell>
                                <Table.Summary.Cell index={4} align="right">{fmtKg(totals.good_production_kg)}</Table.Summary.Cell>
                                <Table.Summary.Cell index={5} align="right">{fmtKg(totals.rejection_kg_production)}</Table.Summary.Cell>
                                {/* Two cells matching the two quality columns —
                                    the summary row is positional, so omitting
                                    them would shift every total right of here
                                    under the wrong heading. */}
                                <Table.Summary.Cell index={6} align="right">{fmtPcs(totals.qc_reviewed_pieces)}</Table.Summary.Cell>
                                <Table.Summary.Cell index={7} align="right">{fmtKg(totals.rejection_kg_qc)}</Table.Summary.Cell>
                                <Table.Summary.Cell index={8} align="right">{fmtKg(totals.lumps_kg)}</Table.Summary.Cell>
                                <Table.Summary.Cell index={9} align="right">{fmtPct(totals.efficiency_pct)}</Table.Summary.Cell>
                            </Table.Summary.Row>
                        </Table.Summary>
                    ) : null
                }
            />
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                Day-total efficiency = Σ actual pieces ÷ Σ expected pieces × 100 (ratio of sums) — the same grain as
                every row above it, deliberately not the average of the per-row percentages. Expected pieces come
                from the standard cycle time snapshotted at Start Batch, the active cavities and the running hours net
                of the downtime logged at completion — the product standard, not the supervisor-typed target the Shift
                Summary measures against. Rows with no recorded standard join neither sum. Boxes are reported as
                plain column totals only.
            </Typography.Text>
        </Space>
    );
}

// ---------------------------------------------------------------------------
// Reconciliation tab — per-batch consumption breakdown. Consumption is
// calculated (good + confirmed rejection + lumps), so there is no per-batch
// unaccounted figure here by owner ruling — missing material is checked at
// the central day bin, not per batch.
// ---------------------------------------------------------------------------

function ReconciliationTab() {
    const [range, setRange] = useState<[string, string]>([
        dayjs().subtract(6, 'day').format('YYYY-MM-DD'),
        dayjs().format('YYYY-MM-DD'),
    ]);
    const [shiftId, setShiftId] = useState<number | undefined>(undefined);
    const shiftOptions = useShiftOptions();

    const { data: rows, isLoading } = useQuery({
        queryKey: ['production', 'reports', 'reconciliation', range[0], range[1], shiftId ?? 'all'],
        queryFn: () => getReconciliationReport({ date_from: range[0], date_to: range[1], shift_id: shiftId }),
    });

    const dataSource = rows ?? [];

    // The same range and shift getReconciliationReport() was called with above.
    const csv = useServerCsv('reconciliation_report');
    const exportCsv = () => csv.mutate({ date_from: range[0], date_to: range[1], shift_id: shiftId });

    return (
        <Space direction="vertical" size={12} style={{ width: '100%' }}>
            <Row gutter={[12, 12]} align="bottom">
                <Col xs={24} sm={12} md={8}>
                    <DatePicker.RangePicker
                        style={{ width: '100%' }}
                        value={[dayjs(range[0]), dayjs(range[1])]}
                        allowClear={false}
                        onChange={(_, dateStrings) => {
                            const [start, end] = dateStrings as [string, string];
                            if (start && end) setRange([start, end]);
                        }}
                    />
                </Col>
                <Col xs={24} sm={12} md={6}>
                    <Select
                        style={{ width: '100%' }}
                        placeholder="All shifts"
                        allowClear
                        options={shiftOptions}
                        value={shiftId}
                        onChange={setShiftId}
                    />
                </Col>
                <Col xs={24} md={10} style={{ textAlign: 'right' }}>
                    <Button onClick={exportCsv} disabled={dataSource.length === 0} loading={csv.isPending}>Download CSV</Button>
                </Col>
            </Row>

            <Table<ReconciliationReportRow>
                scroll={{ x: 'max-content' }}
                sticky={TABLE_STICKY}
                size="small"
                rowKey="entry_id"
                loading={isLoading}
                pagination={false}
                dataSource={dataSource}
                locale={{ emptyText: 'Nothing to reconcile in this range.' }}
                // The whole range is in the browser; each column sorts on the
                // value it shows, and the two identity columns filter.
                columns={[
                    { title: 'Date', dataIndex: 'production_date', sorter: columnSorter((r: ReconciliationReportRow) => r.production_date, 'date') },
                    {
                        title: 'Shift',
                        sorter: columnSorter((r: ReconciliationReportRow) => r.shift.name, 'text'),
                        filters: filterOptions(dataSource, (r) => r.shift.name),
                        onFilter: onFilterBy((r: ReconciliationReportRow) => r.shift.name),
                        render: (_, r) => r.shift.name,
                    },
                    {
                        title: 'Machine',
                        sorter: columnSorter((r: ReconciliationReportRow) => r.work_center.code, 'text'),
                        filters: filterOptions(dataSource, (r) => r.work_center.code),
                        onFilter: onFilterBy((r: ReconciliationReportRow) => r.work_center.code),
                        render: (_, r) => r.work_center.code,
                    },
                    { title: 'Item', sorter: columnSorter((r: ReconciliationReportRow) => itemLabel(r.item), 'text'), render: (_, r) => itemLabel(r.item) },
                    {
                        title: 'Batch',
                        dataIndex: 'batch_number',
                        sorter: columnSorter((r: ReconciliationReportRow) => r.batch_number, 'text'),
                        render: (v: string | null) => v ?? '—',
                    },
                    { title: 'Good Kg', dataIndex: 'good_production_kg', align: 'right', sorter: columnSorter((r: ReconciliationReportRow) => r.good_production_kg, 'number'), render: fmtKg },
                    { title: 'Rej. Kg', dataIndex: 'confirmed_rejection_kg', align: 'right', sorter: columnSorter((r: ReconciliationReportRow) => r.confirmed_rejection_kg, 'number'), render: fmtKg },
                    { title: 'Lumps Kg', dataIndex: 'lumps_kg', align: 'right', sorter: columnSorter((r: ReconciliationReportRow) => r.lumps_kg, 'number'), render: fmtKg },
                    { title: 'Consumed Kg', dataIndex: 'issued_kg', align: 'right', sorter: columnSorter((r: ReconciliationReportRow) => r.issued_kg, 'number'), render: fmtKg },
                ]}
            />
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                Consumed = good + confirmed rejection + lumps (calculated). Missing material is checked at the
                central day bin — see the <Link to="/production/day-bin">Day Bin</Link> reconciliation card.
            </Typography.Text>
        </Space>
    );
}

// ---------------------------------------------------------------------------
// Traceability tab — lot → bags → fed machine/segment drill-down (flag-gated).
// ---------------------------------------------------------------------------

function fedTable(fed: TraceabilityReportFeed[]) {
    return (
        <Table<TraceabilityReportFeed>
            scroll={{ x: 'max-content' }}
            size="small"
            // No server id at this grain — machine:segment mirrors the
            // backend's distinct-destination grouping key, unique per bag.
            rowKey={(r) => `${r.machine.id}:${r.segment?.id ?? 0}`}
            pagination={false}
            dataSource={fed}
            locale={{ emptyText: 'This bag has not been loaded to any machine yet.' }}
            columns={[
                { title: 'Machine', render: (_, r) => `${r.machine.code} — ${r.machine.name}` },
                // Segment is null for a load recorded outside any batch
                // window — the machine still shows, the batch honestly doesn't.
                { title: 'Batch', render: (_, r) => r.segment?.batch_number ?? '—' },
                { title: 'Loaded Kg', dataIndex: 'loaded_kg', align: 'right', render: fmtKg },
                { title: 'Loads', dataIndex: 'loads', align: 'right' },
            ]}
        />
    );
}

function bagsTable(bags: TraceabilityReportBag[]) {
    return (
        <Table<TraceabilityReportBag>
            scroll={{ x: 'max-content' }}
            size="small"
            rowKey="id"
            pagination={false}
            dataSource={bags}
            expandable={{ expandedRowRender: (bag) => fedTable(bag.fed) }}
            columns={[
                { title: 'Barcode', dataIndex: 'barcode' },
                { title: 'Original Kg', dataIndex: 'original_kg', align: 'right', render: fmtKg },
                { title: 'Remaining Kg', dataIndex: 'remaining_kg', align: 'right', render: fmtKg },
                { title: 'Status', dataIndex: 'status', render: (s: MaterialBagStatus) => BAG_STATUS_LABEL[s] ?? s },
                { title: 'Fed', render: (_, bag) => bag.fed.length },
            ]}
        />
    );
}

function TraceabilityTab() {
    const [range, setRange] = useState<[string, string]>([
        dayjs().subtract(6, 'day').format('YYYY-MM-DD'),
        dayjs().format('YYYY-MM-DD'),
    ]);
    const [lotId, setLotId] = useState<number | undefined>(undefined);
    const [itemId, setItemId] = useState<number | undefined>(undefined);
    const queryClient = useQueryClient();

    // ONE filter object (Phase 7, WS-C): the query key, the report request
    // and the CSV export all read it — TraceabilityReportRequest accepts
    // lot_id / item_id beside the range and the export's filterRules are
    // the same request's, so what the table shows is what the file holds.
    const filters = traceabilityFilters(range, lotId, itemId);

    const { data: rows, isLoading } = useQuery({
        queryKey: traceabilityQueryKey(filters),
        queryFn: () => getTraceabilityReport(filters),
    });

    const dataSource = rows ?? [];

    // Reports filter HISTORY, so retired materials stay listed — a lot
    // received under an item since made inactive is still a lot to trace.
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    const itemOptions = (items?.data ?? []).map((item) => ({ value: item.id, label: itemLabel(item) }));

    // The lot picker offers the lots of THIS window (a lot outside it cannot
    // narrow the report to anything). While one lot is chosen the response
    // holds only that lot, so the options come from the cached lot-agnostic
    // read of the same range and material when there is one — the read the
    // reader was looking at when they picked — and from the response
    // otherwise. Clearing the pick always widens back to the window.
    const lotAgnosticRows =
        lotId === undefined
            ? rows
            : queryClient.getQueryData<TraceabilityReportRow[] | null>(
                traceabilityQueryKey(traceabilityFilters(range, undefined, itemId)),
            ) ?? rows;
    const lotOptions = lotFilterOptions(lotAgnosticRows);

    // The same filters getTraceabilityReport() was called with above; the
    // server flattens the lot → bag → fed drill-down itself.
    const csv = useServerCsv('traceability_report');
    const exportCsv = () => csv.mutate(filters);

    return (
        <Space direction="vertical" size={12} style={{ width: '100%' }}>
            <Row gutter={[12, 12]} align="bottom">
                <Col xs={24} sm={12} md={7}>
                    <DatePicker.RangePicker
                        style={{ width: '100%' }}
                        value={[dayjs(range[0]), dayjs(range[1])]}
                        allowClear={false}
                        onChange={(_, dateStrings) => {
                            const [start, end] = dateStrings as [string, string];
                            if (start && end) {
                                setRange([start, end]);
                                // The lot was picked from the old window's
                                // lots; a new window offers its own.
                                setLotId(undefined);
                            }
                        }}
                    />
                </Col>
                <Col xs={24} sm={12} md={5}>
                    <Select
                        style={{ width: '100%' }}
                        placeholder="All materials"
                        allowClear
                        showSearch
                        optionFilterProp="label"
                        options={itemOptions}
                        value={itemId}
                        onChange={(value) => {
                            setItemId(value);
                            // A lot belongs to one material — a lot picked
                            // under another material would filter to nothing.
                            setLotId(undefined);
                        }}
                    />
                </Col>
                <Col xs={24} sm={12} md={6}>
                    <Select
                        style={{ width: '100%' }}
                        placeholder="All lots in this window"
                        allowClear
                        showSearch
                        optionFilterProp="label"
                        options={lotOptions}
                        value={lotId}
                        onChange={setLotId}
                    />
                </Col>
                <Col xs={24} sm={12} md={6} style={{ textAlign: 'right' }}>
                    <Button onClick={exportCsv} disabled={dataSource.length === 0} loading={csv.isPending}>Download CSV</Button>
                </Col>
            </Row>
            <Table<TraceabilityReportRow>
                scroll={{ x: 'max-content' }}
                sticky={TABLE_STICKY}
                size="small"
                rowKey="id"
                loading={isLoading}
                dataSource={dataSource}
                locale={{ emptyText: 'No material lots received in this range.' }}
                expandable={{ expandedRowRender: (lot) => bagsTable(lot.bags) }}
                // The lots of the window are all in the browser; the bag and
                // feed tables inside a lot keep their own order.
                columns={[
                    {
                        title: 'Supplier Lot',
                        dataIndex: 'supplier_lot_no',
                        sorter: columnSorter((lot: TraceabilityReportRow) => lot.supplier_lot_no, 'text'),
                        render: (v: string | null) => v ?? '—',
                    },
                    { title: 'Material', sorter: columnSorter((lot: TraceabilityReportRow) => itemLabel(lot.item), 'text'), render: (_, lot) => itemLabel(lot.item) },
                    {
                        title: 'Received',
                        dataIndex: 'received_date',
                        sorter: columnSorter((lot: TraceabilityReportRow) => lot.received_date, 'date'),
                        render: (v: string | null) => v ?? '—',
                    },
                    { title: 'Bags', dataIndex: 'bag_count', align: 'right', sorter: columnSorter((lot: TraceabilityReportRow) => lot.bag_count, 'number') },
                    { title: 'Received Kg', dataIndex: 'total_received_kg', align: 'right', sorter: columnSorter((lot: TraceabilityReportRow) => lot.total_received_kg, 'number'), render: fmtKg },
                ]}
            />
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                Lots received in the selected window, narrowed by material and lot when chosen — the CSV carries the
                same filters. Expand a lot to see its bags, and a bag to see every machine/batch segment it was loaded
                into.
            </Typography.Text>
        </Space>
    );
}

export default function ReportsPage() {
    const settings = useProductionSettings();
    const traceabilityEnabled = settings?.traceability_enabled === true;

    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>Production Reports</Typography.Title>
            <Typography.Paragraph type="secondary">
                Read-only views over what the floor already logged — nothing here writes anything.
            </Typography.Paragraph>
            <Tabs
                defaultActiveKey="production"
                items={[
                    { key: 'production', label: 'Production', children: <ProductionTab /> },
                    { key: 'reconciliation', label: 'Reconciliation', children: <ReconciliationTab /> },
                    ...(traceabilityEnabled
                        ? [{ key: 'traceability', label: 'Traceability', children: <TraceabilityTab /> }]
                        : []),
                ]}
            />
        </>
    );
}
