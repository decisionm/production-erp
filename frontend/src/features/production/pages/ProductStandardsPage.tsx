import { useQuery } from '@tanstack/react-query';
import { Alert, Card, Col, Input, Row, Segmented, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { listProductionStandards } from '@/features/production/api';
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
    const [scope, setScope] = useState<'mapped' | 'all'>('mapped');
    const [search, setSearch] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['production', 'standards', page, scope],
        queryFn: () => listProductionStandards({ page, per_page: 100, matched_only: scope === 'mapped' }),
    });

    const rows = data?.data ?? [];

    // Filtered in the browser rather than by the API: the whole list is one
    // page of a hundred at most, and typing is instant this way.
    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (q === '') return rows;
        return rows.filter(
            (r) =>
                r.source_product_name.toLowerCase().includes(q) ||
                (r.item?.name ?? '').toLowerCase().includes(q) ||
                (r.item?.sku ?? '').toLowerCase().includes(q),
        );
    }, [rows, search]);

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
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="This is not the same as Production Configuration"
                description={
                    <>
                        A <b>standard</b> is what a product runs to wherever it runs — that is this page.{' '}
                        A <b>configuration</b> (<Link to="/production/configuration">Production Configuration</Link>) is
                        a machine + product + mould approval, and there are none yet, which is what “No approved
                        machine–product mapping” means on Start Batch. A <b>recipe</b> (
                        <Link to="/production/boms">Bills of Material</Link>) is how much resin and masterbatch go into
                        one bottle — that is the “No active consumption recipe” notice.
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
                        title: 'Workbook product',
                        render: (_, r) => <Typography.Text strong>{r.source_product_name}</Typography.Text>,
                    },
                    {
                        title: 'Tally item it applies to',
                        render: (_, r) =>
                            r.item ? (
                                itemLabel(r.item)
                            ) : (
                                <Tooltip title="Imported, but the workbook name matches no Tally item — the standard is recorded as work to do rather than dropped.">
                                    <Tag>not attached</Tag>
                                </Tooltip>
                            ),
                    },
                    { title: 'Cavities', align: 'right', render: (_, r) => r.cavities ?? '—' },
                    { title: 'Weight (g)', align: 'right', render: (_, r) => fmt(r.unit_weight_grams) },
                    {
                        title: 'Cycle time (s)',
                        align: 'right',
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
                        title: 'Packed as',
                        render: (_, r) =>
                            r.packagings.length === 0 ? (
                                '—'
                            ) : (
                                <Space size={4} wrap>
                                    {r.packagings.map((p) => (
                                        <Tooltip
                                            key={p.id}
                                            title={
                                                p.mode === 'pouch'
                                                    ? `${p.nos_per_pouch ?? '?'} per pouch · ${p.pouches_per_box ?? '?'} pouches per box · ${p.nos_per_box ?? '?'} per box`
                                                    : p.mode === 'tray'
                                                      ? `${p.nos_per_tray ?? '?'} per tray · ${p.trays_per_box ?? '?'} trays per box · ${p.nos_per_box ?? '?'} per box`
                                                      : `${p.nos_per_box ?? '?'} per box`
                                            }
                                        >
                                            <Tag color={p.mode === 'pouch' ? 'purple' : p.mode === 'tray' ? 'blue' : 'default'}>
                                                {p.mode === 'direct_box' ? 'box' : p.mode}
                                            </Tag>
                                        </Tooltip>
                                    ))}
                                </Space>
                            ),
                    },
                    {
                        title: 'Materials',
                        render: (_, r) => (
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                {[r.carton_spec && `carton ${r.carton_spec}`, r.tray_spec && `tray ${r.tray_spec}`, r.pouch_spec && `film ${r.pouch_spec}`]
                                    .filter(Boolean)
                                    .join(' · ') || '—'}
                            </Typography.Text>
                        ),
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
                    {
                        title: 'From',
                        render: (_, r) => (
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                {r.source ?? '—'}
                                {r.source_reference ? ` row ${r.source_reference}` : ''}
                            </Typography.Text>
                        ),
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
