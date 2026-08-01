import { useQuery } from '@tanstack/react-query';
import { Button, Col, DatePicker, Row, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { type ReactElement, useState } from 'react';
import {
    getProductionReport,
    getReconciliationReport,
    getTraceabilityReport,
    listShifts,
    listWorkCenters,
} from '@/features/production/api';
import { useProductionSettings } from '@/features/production/packing';
import type {
    MaterialBagStatus,
    ProductionReportRow,
    ProductionReportTotals,
    ReconciliationReportRow,
    TraceabilityReportBag,
    TraceabilityReportFeed,
    TraceabilityReportRow,
} from '@/features/production/types';
import { downloadCsv, toCsv } from '@/lib/csv';
import { itemLabel } from '@/lib/itemLabel';

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

const BAG_STATUS_LABEL: Record<MaterialBagStatus, string> = {
    in_store: 'In Store',
    in_day_bin: 'At Machine',
    consumed: 'Consumed',
    returned: 'Returned',
};

function useShiftOptions() {
    const { data: shifts } = useQuery({ queryKey: ['production', 'shifts'], queryFn: listShifts });
    return (shifts?.data ?? []).filter((s) => s.is_active).map((s) => ({ value: s.id, label: s.name }));
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

    const exportCsv = () => {
        const csv = toCsv(
            ['Date', 'Shift', 'Machine', 'Item', 'Batch', 'Running Hrs', 'Expected Boxes', 'Actual Boxes', 'Expected Pcs', 'Actual Pcs', 'Good Kg', 'Rejection Kg', 'Reviewed Pcs', 'Rejected by QC Kg', 'Lumps Kg', 'Efficiency % (pcs)'],
            [
                ...rows.map((r) => [
                    r.production_date,
                    r.shift.name,
                    r.work_center.code,
                    itemLabel(r.item),
                    r.batch_number,
                    r.running_hours,
                    r.expected_boxes,
                    r.actual_boxes,
                    r.expected_pieces,
                    r.actual_pieces,
                    r.good_production_kg,
                    r.rejection_kg_production,
                    r.qc_reviewed_pieces ?? '',
                    r.rejection_kg_qc,
                    r.lumps_kg,
                    r.efficiency_pct,
                ]),
                ...(totals
                    ? [[
                          date,
                          'TOTAL',
                          '',
                          '',
                          '',
                          '',
                          totals.expected_boxes,
                          totals.actual_boxes,
                          // The totals row's denominator (Σ eligible expected
                          // pieces) is not on the wire — the server sends the
                          // finished ratio, not its parts — so this cell stays
                          // blank rather than summing the row column, which
                          // would include rows the ratio excluded and read as
                          // a denominator that does not produce the % beside it.
                          '',
                          totals.actual_pieces,
                          totals.good_production_kg,
                          totals.rejection_kg_production,
                          totals.qc_reviewed_pieces ?? '',
                          totals.rejection_kg_qc,
                          totals.lumps_kg,
                          totals.efficiency_pct,
                      ] as (string | number | null)[]]
                    : []),
            ],
        );
        downloadCsv(`production-report-${date}.csv`, csv);
    };

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
                    <Button onClick={exportCsv} disabled={rows.length === 0}>Export CSV</Button>
                </Col>
            </Row>

            <Table<ProductionReportRow>
                scroll={{ x: 'max-content' }}
                size="small"
                rowKey="entry_id"
                loading={isLoading}
                pagination={false}
                dataSource={rows}
                locale={{ emptyText: 'No completed batches for this date/filter.' }}
                columns={[
                    { title: 'Shift', render: (_, r) => r.shift.name },
                    { title: 'Machine', render: (_, r) => r.work_center.code },
                    { title: 'Item', render: (_, r) => itemLabel(r.item) },
                    { title: 'Batch', dataIndex: 'batch_number', render: (v: string | null) => v ?? '—' },
                    { title: 'Hrs', dataIndex: 'running_hours', align: 'right', render: fmtKg },
                    { title: 'Exp. Boxes', dataIndex: 'expected_boxes', align: 'right', render: (v: number | null) => v ?? '—' },
                    { title: 'Act. Boxes', dataIndex: 'actual_boxes', align: 'right', render: (v: number | null) => v ?? '—' },
                    { title: 'Act. Pcs', dataIndex: 'actual_pieces', align: 'right', render: fmtKg },
                    { title: 'Good Kg', dataIndex: 'good_production_kg', align: 'right', render: fmtKg },
                    { title: 'Rej. Kg', dataIndex: 'rejection_kg_production', align: 'right', render: fmtKg },
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
                    { title: 'Reviewed (pcs, not served)', dataIndex: 'qc_reviewed_pieces', align: 'right', render: fmtPcs },
                    { title: 'Rej. by QC (kg)', dataIndex: 'rejection_kg_qc', align: 'right', render: fmtKg },
                    { title: 'Lumps Kg', dataIndex: 'lumps_kg', align: 'right', render: fmtKg },
                    {
                        // The one place the grain is named — rows and the
                        // pinned Day total are both pieces ÷ pieces.
                        title: 'Efficiency (pcs)',
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
                every row above it, deliberately not the average of the per-row percentages. Rows with no recorded
                standard join neither sum. Boxes are reported as plain column totals only.
            </Typography.Text>
        </Space>
    );
}

// ---------------------------------------------------------------------------
// Reconciliation tab — worst-unaccounted-first (server-ordered); band tags
// straight off the server's unaccounted_band field.
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

    const exportCsv = () => {
        const csv = toCsv(
            ['Date', 'Shift', 'Machine', 'Item', 'Batch', 'Issued Kg', 'Good Kg', 'Confirmed Rejection Kg', 'Lumps Kg', 'Unaccounted Kg', 'Band'],
            dataSource.map((r) => [
                r.production_date,
                r.shift.name,
                r.work_center.code,
                itemLabel(r.item),
                r.batch_number,
                r.issued_kg,
                r.good_production_kg,
                r.confirmed_rejection_kg,
                r.lumps_kg,
                r.reconciliation_unaccounted_kg,
                r.unaccounted_band ?? '',
            ]),
        );
        downloadCsv(`reconciliation-report-${range[0]}-to-${range[1]}.csv`, csv);
    };

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
                    <Button onClick={exportCsv} disabled={dataSource.length === 0}>Export CSV</Button>
                </Col>
            </Row>

            <Table<ReconciliationReportRow>
                scroll={{ x: 'max-content' }}
                size="small"
                rowKey="entry_id"
                loading={isLoading}
                pagination={false}
                dataSource={dataSource}
                locale={{ emptyText: 'Nothing to reconcile in this range.' }}
                columns={[
                    { title: 'Date', dataIndex: 'production_date' },
                    { title: 'Shift', render: (_, r) => r.shift.name },
                    { title: 'Machine', render: (_, r) => r.work_center.code },
                    { title: 'Item', render: (_, r) => itemLabel(r.item) },
                    { title: 'Batch', dataIndex: 'batch_number', render: (v: string | null) => v ?? '—' },
                    { title: 'Issued Kg', dataIndex: 'issued_kg', align: 'right', render: fmtKg },
                    { title: 'Good Kg', dataIndex: 'good_production_kg', align: 'right', render: fmtKg },
                    { title: 'Rej. Kg', dataIndex: 'confirmed_rejection_kg', align: 'right', render: fmtKg },
                    { title: 'Lumps Kg', dataIndex: 'lumps_kg', align: 'right', render: fmtKg },
                    {
                        title: 'Unaccounted Kg',
                        align: 'right',
                        render: (_, r) =>
                            r.unaccounted_band === 'investigate' ? (
                                <Typography.Text type="danger" strong>{fmtKg(r.reconciliation_unaccounted_kg)}</Typography.Text>
                            ) : (
                                fmtKg(r.reconciliation_unaccounted_kg)
                            ),
                    },
                    { title: 'Band', render: (_, r) => bandTag(r.unaccounted_band) ?? '—' },
                ]}
            />
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                Unaccounted = issued − good − confirmed rejection − lumps, worst first. Bands are ruled server-side
                from the configured tolerances.
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

    const { data: rows, isLoading } = useQuery({
        queryKey: ['production', 'reports', 'traceability', range[0], range[1]],
        queryFn: () => getTraceabilityReport({ date_from: range[0], date_to: range[1] }),
    });

    const dataSource = rows ?? [];

    // Flattened lot/bag/fed grain — the whole loaded drill-down, one line
    // per deepest visible level (machine/segment destination).
    const exportCsv = () => {
        const flat: (string | number | null)[][] = [];
        dataSource.forEach((lot) => {
            if (lot.bags.length === 0) {
                flat.push([lot.supplier_lot_no, itemLabel(lot.item), lot.received_date, '', '', '', '', '', '', '']);
                return;
            }
            lot.bags.forEach((bag) => {
                if (bag.fed.length === 0) {
                    flat.push([lot.supplier_lot_no, itemLabel(lot.item), lot.received_date, bag.barcode, bag.status, bag.remaining_kg, '', '', '', '']);
                    return;
                }
                bag.fed.forEach((feed) => {
                    flat.push([
                        lot.supplier_lot_no,
                        itemLabel(lot.item),
                        lot.received_date,
                        bag.barcode,
                        bag.status,
                        bag.remaining_kg,
                        feed.machine.code,
                        feed.segment?.batch_number ?? '',
                        feed.loaded_kg,
                        feed.loads,
                    ]);
                });
            });
        });
        const csv = toCsv(
            ['Supplier Lot', 'Material', 'Received', 'Bag Barcode', 'Bag Status', 'Bag Remaining Kg', 'Machine', 'Batch', 'Loaded Kg', 'Loads'],
            flat,
        );
        downloadCsv(`traceability-report-${range[0]}-to-${range[1]}.csv`, csv);
    };

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
                <Col xs={24} md={16} style={{ textAlign: 'right' }}>
                    <Button onClick={exportCsv} disabled={dataSource.length === 0}>Export CSV</Button>
                </Col>
            </Row>
            <Table<TraceabilityReportRow>
                scroll={{ x: 'max-content' }}
                size="small"
                rowKey="id"
                loading={isLoading}
                dataSource={dataSource}
                locale={{ emptyText: 'No material lots received in this range.' }}
                expandable={{ expandedRowRender: (lot) => bagsTable(lot.bags) }}
                columns={[
                    { title: 'Supplier Lot', dataIndex: 'supplier_lot_no', render: (v: string | null) => v ?? '—' },
                    { title: 'Material', render: (_, lot) => itemLabel(lot.item) },
                    { title: 'Received', dataIndex: 'received_date', render: (v: string | null) => v ?? '—' },
                    { title: 'Bags', dataIndex: 'bag_count', align: 'right' },
                    { title: 'Received Kg', dataIndex: 'total_received_kg', align: 'right', render: fmtKg },
                ]}
            />
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                Lots received in the selected window. Expand a lot to see its bags, and a bag to see every
                machine/batch segment it was loaded into.
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
