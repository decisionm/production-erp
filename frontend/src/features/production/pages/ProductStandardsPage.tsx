import { useQuery } from '@tanstack/react-query';
import { Alert, Card, Col, Input, Row, Segmented, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { listProductionStandards } from '@/features/production/api';
import { useProductionSettings } from '@/features/production/packing';
import type { ProductionStandardRow } from '@/features/production/types';
import { itemLabel } from '@/lib/itemLabel';

/**
 * The factory's imported product master, on screen.
 *
 * This existed as an API endpoint and a database table with no page, which made
 * the 28 configured products invisible: the only place their figures surfaced
 * was inside the Start Batch dialog, one product at a time, after picking a
 * machine. There was no way to answer "what is actually configured?".
 *
 * Read-only by design. The only writer is the import command, so this is a
 * window onto master data rather than an editing surface — editing a figure here
 * would silently disagree with the workbook it came from, and the workbook is
 * the factory's own record.
 *
 * ## The three tables this page is NOT
 *
 * Worth stating, because they are easy to confuse and each explains a different
 * notice on Start Batch:
 *
 *  - **Product standards** (this page) — what a product runs to WHEREVER it
 *    runs: cavities, weight, cycle time, packing. From the workbook.
 *  - **Production configuration** (/production/configuration) — a machine +
 *    product + mould approval. Empty today, which is what "No approved
 *    machine–product mapping yet" means. Deliberate: the factory has no machine
 *    column in its master, so a run records itself as evidence instead.
 *  - **Bills of Material** (/production/boms) — the consumption recipe: how much
 *    resin and masterbatch go into one bottle. Empty today, which is what
 *    "No active consumption recipe" means.
 */

/** The standard's packaging row for one mode, if the workbook gave one. */
const pkg = (r: ProductionStandardRow, mode: 'pouch' | 'tray' | 'direct_box') =>
    r.packagings.find((p) => p.mode === mode);

const fmt = (v: string | number | null | undefined, suffix = ''): string => {
    if (v === null || v === undefined || v === '') return '—';
    const n = typeof v === 'number' ? v : parseFloat(v);
    return Number.isNaN(n) ? '—' : `${parseFloat(n.toFixed(4))}${suffix}`;
};

const STATUS: Record<string, { colour: string; label: string; help: string }> = {
    approved: { colour: 'green', label: 'Approved', help: 'Signed off by a person.' },
    draft: {
        colour: 'blue',
        label: 'Ready',
        help: 'Imported cleanly and usable. Nothing here needs a decision — "draft" only means no one has formally signed it off, which the import deliberately never does on your behalf.',
    },
    unresolved: {
        colour: 'orange',
        label: 'Needs a factory answer',
        help: 'The workbook cell was ambiguous or blank. The batch can still run; the figure is just not one anybody has confirmed.',
    },
};

export default function ProductStandardsPage() {
    const [page, setPage] = useState(1);
    const [scope, setScope] = useState<'mapped' | 'all'>('all');
    const [search, setSearch] = useState('');
    const [cavityBand, setCavityBand] = useState<'any' | 'below' | 'atOrAbove'>('any');

    const { data, isLoading } = useQuery({
        queryKey: ['production', 'standards', page, scope],
        queryFn: () => listProductionStandards({ page, per_page: 100, matched_only: scope === 'mapped' }),
    });

    // The factory's machine rule, from the backend that also enforces it — so
    // the machines this page names cannot disagree with the machines Start
    // Batch allows. Absent on an older backend, in which case the column stays
    // silent rather than guessing.
    const settings = useProductionSettings();
    const rule = settings?.machine_capability ?? null;
    const threshold = rule?.cavity_threshold ?? null;
    const restrictedNames = (rule?.restricted_machines ?? []).map((m) => m.name);

    const rows = data?.data ?? [];

    // Filtered in the browser rather than by the API: the whole list is one
    // page of a hundred at most, and typing is instant this way.
    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return rows.filter((r) => {
            if (q !== '') {
                const hit =
                    r.source_product_name.toLowerCase().includes(q) ||
                    (r.item?.name ?? '').toLowerCase().includes(q) ||
                    (r.item?.sku ?? '').toLowerCase().includes(q);
                if (!hit) return false;
            }
            if (cavityBand === 'any' || threshold === null) return true;
            // A blank cavity count belongs to neither band. It is the figure
            // the rule is decided on, so a row without one cannot be claimed
            // for either side.
            if (r.cavities === null || r.cavities === undefined) return false;
            return cavityBand === 'below' ? r.cavities < threshold : r.cavities >= threshold;
        });
    }, [rows, search, cavityBand, threshold]);

    const mappedCount = rows.filter((r) => r.item !== null).length;
    const needsAnswer = rows.filter((r) => r.status === 'unresolved').length;
    const withPouch = rows.filter((r) => r.packagings.some((p) => p.mode === 'pouch')).length;

    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>
                Product Standards
            </Typography.Title>
            <Typography.Paragraph type="secondary">
                The factory's product master as imported — cavities, weight, cycle time and packing for each product,
                attached to the Tally item it applies to. Read-only: the workbook is the record, and this is a window
                onto it.
            </Typography.Paragraph>

            {/* The distinction that explains two Start Batch notices. Stated here
                because this is the page people arrive at looking for it. */}
            {/* Rewritten once the machine rule became visible in the table
                above. The old text told people configurations were empty and
                explained two Start Batch notices that have since been deleted —
                a page that describes the app as it was last week is worse than
                one that says nothing. */}
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="Which machines a product runs on"
                description={
                    <>
                        {threshold !== null && restrictedNames.length > 0 ? (
                            <>
                                The <b>MACHINES</b> column is the factory's own rule, not a list anyone maintains: under{' '}
                                {threshold} cavities a mould runs on <b>any</b> machine, and at {threshold} or more it is
                                set up on <b>{restrictedNames.join(' or ')}</b>. Change the rule on{' '}
                                <Link to="/production/configuration">Configuration → Machines &amp; Capabilities</Link> and
                                this column follows.{' '}
                            </>
                        ) : null}
                        A <b>standard</b> (this page) is what a product runs to wherever it runs. A{' '}
                        <b>configuration</b> (<Link to="/production/configuration">Configuration</Link>) is only needed
                        for an exception — a product that runs differently on one machine than the workbook says.
                    </>
                }
            />

            <Row gutter={[10, 10]} style={{ marginBottom: 16 }}>
                <Col xs={12} sm={6}>
                    <Card size="small" styles={{ body: { padding: '10px 14px' } }}>
                        <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                            Standards shown
                        </Typography.Text>
                        <Typography.Text strong style={{ fontSize: 24 }}>{rows.length}</Typography.Text>
                    </Card>
                </Col>
                <Col xs={12} sm={6}>
                    <Card size="small" styles={{ body: { padding: '10px 14px' } }}>
                        <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                            Attached to a Tally item
                        </Typography.Text>
                        <Typography.Text strong style={{ fontSize: 24, color: '#237804' }}>{mappedCount}</Typography.Text>
                    </Card>
                </Col>
                <Col xs={12} sm={6}>
                    <Card size="small" styles={{ body: { padding: '10px 14px' } }}>
                        <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                            Offer a pouch option
                        </Typography.Text>
                        <Typography.Text strong style={{ fontSize: 24 }}>{withPouch}</Typography.Text>
                    </Card>
                </Col>
                <Col xs={12} sm={6}>
                    <Card size="small" styles={{ body: { padding: '10px 14px' } }}>
                        <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                            Need a factory answer
                        </Typography.Text>
                        <Typography.Text strong style={{ fontSize: 24, color: needsAnswer > 0 ? '#ad6800' : undefined }}>
                            {needsAnswer}
                        </Typography.Text>
                    </Card>
                </Col>
            </Row>

            <Space style={{ marginBottom: 12 }} wrap>
                <Segmented
                    value={scope}
                    onChange={(v) => { setScope(v as 'mapped' | 'all'); setPage(1); }}
                    options={[
                        { value: 'mapped', label: 'Attached to an item' },
                        { value: 'all', label: 'Everything imported' },
                    ]}
                />
                <Input.Search
                    allowClear
                    placeholder="Search product or Tally item…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    style={{ width: 320 }}
                />
                {/* The cavity bands the machine rule actually splits on, so the
                    high-cavity group can be read as a group — that is the list
                    someone checks when they want to know what Machine 10 runs. */}
                {threshold !== null && restrictedNames.length > 0 && (
                    <Segmented
                        value={cavityBand}
                        onChange={(v) => setCavityBand(v as 'any' | 'below' | 'atOrAbove')}
                        options={[
                            { value: 'any', label: 'Any cavities' },
                            { value: 'below', label: `Under ${threshold} — all machines` },
                            { value: 'atOrAbove', label: `${threshold}+ — ${restrictedNames.join('/')} only` },
                        ]}
                    />
                )}
            </Space>

            <Table<ProductionStandardRow>
                rowKey="id"
                size="small"
                loading={isLoading}
                dataSource={visible}
                scroll={{ x: 'max-content' }}
                pagination={{
                    current: page,
                    pageSize: 100,
                    total: data?.meta?.total ?? rows.length,
                    onChange: setPage,
                    showSizeChanger: false,
                }}
                columns={[
                    {
                        title: 'SL.NO.',
                        align: 'right' as const,
                        render: (_, r) => (
                            <Tooltip title={r.source ? `From ${r.source}` : undefined}>
                                <Typography.Text type="secondary">{r.source_reference ?? '—'}</Typography.Text>
                            </Tooltip>
                        ),
                    },
                    {
                        title: 'PRODUCT',
                        sorter: (a, b) => a.source_product_name.localeCompare(b.source_product_name),
                        render: (_, r) => <Typography.Text strong>{r.source_product_name}</Typography.Text>,
                    },
                    {
                        title: 'Tally item it applies to',
                        sorter: (a, b) => (a.item?.name ?? '').localeCompare(b.item?.name ?? ''),
                        render: (_, r) =>
                            r.item ? (
                                itemLabel(r.item)
                            ) : (
                                <Tooltip title="Imported, but the workbook name matches no Tally item — the standard is recorded as work to do rather than dropped.">
                                    <Tag>not attached</Tag>
                                </Tooltip>
                            ),
                    },
                    {
                        title: 'NO. OF CAVITY',
                        align: 'right',
                        sorter: (a, b) => (a.cavities ?? -1) - (b.cavities ?? -1),
                        render: (_, r) => r.cavities ?? '—',
                    },
                    {
                        // Which machines this product may run on — the factory's
                        // own rule (below the threshold, anywhere; at or above
                        // it, the named machines), applied to this row's cavity
                        // count. Computed, never stored: the rule is one
                        // sentence, and storing it per product-machine pair
                        // would mean ~790 rows to approve that then outrank the
                        // workbook the moment a figure is corrected there.
                        title: 'MACHINES',
                        sorter: (a, b) => (a.cavities ?? -1) - (b.cavities ?? -1),
                        render: (_, r) => {
                            if (threshold === null || restrictedNames.length === 0) return '—';
                            if (r.cavities === null || r.cavities === undefined) {
                                return (
                                    <Tooltip title="No cavity count on this standard, and the rule is decided on cavities — so which machines this runs on is not something the app can answer yet.">
                                        <Typography.Text type="secondary">needs a cavity count</Typography.Text>
                                    </Tooltip>
                                );
                            }
                            return r.cavities >= threshold ? (
                                <Tooltip title={`${r.cavities} cavities — at or above ${threshold}, so this mould is set up on ${restrictedNames.join(' or ')}.`}>
                                    <Tag color="gold">{restrictedNames.join(' or ')} only</Tag>
                                </Tooltip>
                            ) : (
                                <Tooltip title={`${r.cavities} cavities — below ${threshold}, so any machine can run it.`}>
                                    <Tag color="green">All machines</Tag>
                                </Tooltip>
                            );
                        },
                    },
                    {
                        title: 'WT. (g)',
                        align: 'right',
                        sorter: (a, b) => parseFloat(a.unit_weight_grams ?? '-1') - parseFloat(b.unit_weight_grams ?? '-1'),
                        render: (_, r) => fmt(r.unit_weight_grams),
                    },
                    {
                        title: 'CYCLE TIME (s)',
                        align: 'right',
                        sorter: (a, b) => parseFloat(a.cycle_time ?? '-1') - parseFloat(b.cycle_time ?? '-1'),
                        render: (_, r) =>
                            r.cycle_time_raw && r.cycle_time_raw !== r.cycle_time ? (
                                <Tooltip title={`The workbook cell held "${r.cycle_time_raw}" — split into separate variants rather than averaged.`}>
                                    <span>{fmt(r.cycle_time)} *</span>
                                </Tooltip>
                            ) : (
                                fmt(r.cycle_time)
                            ),
                    },
                    {
                        // The workbook's own three pouch columns, as columns —
                        // not folded into a tooltip. This page is read against
                        // the printed sheet, so it must line up with it.
                        title: 'POUCH',
                        children: [
                            { title: 'BOTL/POUCH', align: 'right' as const, render: (_: unknown, r: ProductionStandardRow) => pkg(r, 'pouch')?.nos_per_pouch ?? '—' },
                            { title: 'BOT/BOX', align: 'right' as const, render: (_: unknown, r: ProductionStandardRow) => pkg(r, 'pouch')?.nos_per_box ?? '—' },
                            { title: 'POUCH/BOX', align: 'right' as const, render: (_: unknown, r: ProductionStandardRow) => pkg(r, 'pouch')?.pouches_per_box ?? '—' },
                        ],
                    },
                    {
                        title: 'TRAY',
                        children: [
                            { title: 'BOTL/TRAY', align: 'right' as const, render: (_: unknown, r: ProductionStandardRow) => pkg(r, 'tray')?.nos_per_tray ?? '—' },
                            { title: 'BOT/BOX', align: 'right' as const, render: (_: unknown, r: ProductionStandardRow) => pkg(r, 'tray')?.nos_per_box ?? '—' },
                            { title: 'TRAY/BOX', align: 'right' as const, render: (_: unknown, r: ProductionStandardRow) => pkg(r, 'tray')?.trays_per_box ?? '—' },
                        ],
                    },
                    {
                        title: 'Box only',
                        align: 'right' as const,
                        render: (_, r) => pkg(r, 'direct_box')?.nos_per_box ?? '—',
                    },
                    {
                        // The three right-hand spec columns of the sheet:
                        // which carton, which tray, which pouch film.
                        title: 'Packaging materials',
                        children: [
                            { title: 'CARTON', render: (_: unknown, r: ProductionStandardRow) => r.carton_spec ?? '—' },
                            { title: 'TRAY', render: (_: unknown, r: ProductionStandardRow) => r.tray_spec ?? '—' },
                            { title: 'POUCH', render: (_: unknown, r: ProductionStandardRow) => r.pouch_spec ?? '—' },
                        ],
                    },
                    {
                        title: 'Status',
                        render: (_, r) => {
                            const s = STATUS[r.status] ?? { colour: 'default', label: r.status, help: '' };
                            return (
                                <Tooltip title={r.status === 'unresolved' ? (r.unresolved_reason ?? s.help) : s.help}>
                                    <Tag color={s.colour}>{s.label}</Tag>
                                </Tooltip>
                            );
                        },
                    },
                ]}
            />

            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 12, fontSize: 12 }}>
                A cycle time marked <b>*</b> came from a workbook cell holding more than one value; each became its own
                variant rather than being averaged, because the mean of two real cycle times is a rate no machine runs
                at. Hover any tag for the figures behind it.
            </Typography.Text>
        </>
    );
}
