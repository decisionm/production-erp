import { useQuery } from '@tanstack/react-query';
import {
    Alert,
    Card,
    Descriptions,
    Empty,
    Input,
    Space,
    Table,
    Tag,
    Typography,
} from 'antd';
import { useState } from 'react';
import { lookupCartonTrace } from '@/features/production/api';
import type { CartonInternalTrace, CartonTraceLot } from '@/features/production/types';

/** "92.0000" → "92"; "—" for null/unparseable. Rates stay strings until display. */
function fmtDecimal(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const parsed = parseFloat(value);
    return Number.isNaN(parsed) ? '—' : String(parseFloat(parsed.toFixed(4)));
}

const VERDICT_TAGS: Record<string, { color: string; label: string }> = {
    approved: { color: 'success', label: 'Approved' },
    pending: { color: 'processing', label: 'Pending approval' },
    quality_rejected: { color: 'error', label: 'QUALITY REJECTED' },
};

/**
 * THE INTERNAL CARTON TRACE (DEC-20260810-001): scan a carton, read what the
 * public tier never says — completion datetime, shift, the day-bin lot
 * attribution (GRN reference, inward date, rate) and the batch's costing
 * rate. The server 403s anyone without carton-trace.view, so this page is
 * reachable only by Owner (Administrator), Plant Manager and Accounts
 * logins; the menu entry is gated by the same permission.
 *
 * THE WORDING IS CONSTITUTIONAL (FC-01). Every block that names a lot
 * renders the server's `basis` sentence beside it: the day bin held loads
 * from these lots during that shift — a calculated attribution, never
 * "this batch used this bag". Do not shorten those sentences away.
 *
 * AND THERE ARE TWO SUCH BLOCKS, deliberately not one. The day bin's
 * sentence is owner-fixed (DEC-20260810-001) and says the BIN held these
 * lots; material now reaches production as a store issue instead
 * (DEC-20260817-001), and those lots never entered the bin. Each ledger
 * renders under its own sentence — merging the tables would make the
 * owner's sentence untrue about half the rows under it.
 */
export default function CartonTracePage() {
    const [scanned, setScanned] = useState('');

    const { data, error, isFetching } = useQuery<CartonInternalTrace>({
        queryKey: ['carton-internal-trace', scanned],
        queryFn: () => lookupCartonTrace(scanned),
        enabled: scanned !== '',
        retry: false,
    });

    const status = (error as { response?: { status?: number } } | null)?.response?.status;
    const detail =
        (error as { response?: { data?: { message?: string } } } | null)?.response?.data?.message;

    const lotColumns = (quantityTitle: string) => [
        { title: 'Material', dataIndex: 'material', render: (v: string | null) => v ?? '—' },
        { title: 'Supplier lot', dataIndex: 'supplier_lot_no', render: (v: string | null) => v ?? '—' },
        { title: 'GRN reference', dataIndex: 'grn_reference', render: (v: string | null) => v ?? '—' },
        { title: 'Inward date', dataIndex: 'inward_date', render: (v: string | null) => v ?? '—' },
        {
            title: 'Rate (₹/kg)',
            dataIndex: 'rate_per_kg',
            render: (v: string | null, row: CartonTraceLot) =>
                v === null ? (
                    <Typography.Text type="secondary">no recorded rate</Typography.Text>
                ) : (
                    <>
                        {fmtDecimal(v)}{' '}
                        {row.rate_source === 'bag_version' && <Tag color="blue">revised</Tag>}
                    </>
                ),
        },
        { title: quantityTitle, dataIndex: 'loaded_kg', render: fmtDecimal },
    ];

    return (
        <Space direction="vertical" size={16} style={{ width: '100%' }}>
            <Card>
                <Space direction="vertical" size={4} style={{ width: '100%' }}>
                    <Typography.Title level={4} style={{ margin: 0 }}>
                        Carton trace (internal)
                    </Typography.Title>
                    <Typography.Text type="secondary">
                        Completion, shift, day-bin lot attribution and batch costing — the tier the
                        public scan deliberately does not carry. Owner, Plant Manager and Accounts only.
                    </Typography.Text>
                    <Input.Search
                        placeholder="Scan or type a carton code"
                        allowClear
                        autoFocus
                        enterButton="Trace"
                        loading={isFetching}
                        onSearch={(value) => setScanned(value.trim())}
                        style={{ maxWidth: 420, marginTop: 8 }}
                    />
                </Space>
            </Card>

            {error != null && (
                <Alert
                    type={status === 403 ? 'warning' : 'error'}
                    showIcon
                    message={
                        status === 403
                            ? 'This login does not hold the internal carton trace'
                            : 'Could not trace this carton'
                    }
                    description={detail ?? 'Unknown error'}
                />
            )}

            {data === undefined && error == null && (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="Scan a carton to read its internal trace."
                />
            )}

            {data !== undefined && (
                <>
                    <Card title={data.carton.carton_no} size="small">
                        <Descriptions size="small" column={{ xs: 1, md: 3 }}>
                            <Descriptions.Item label="Item">
                                {data.carton.item?.name ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Pieces">
                                {fmtDecimal(data.carton.pieces)}
                                {data.carton.is_partial ? ' (partial box)' : ''}
                            </Descriptions.Item>
                            <Descriptions.Item label="Quality">
                                {(() => {
                                    const verdict = data.carton.quality?.verdict;
                                    const tag = verdict !== undefined ? VERDICT_TAGS[verdict] : undefined;
                                    return tag !== undefined ? <Tag color={tag.color}>{tag.label}</Tag> : '—';
                                })()}
                            </Descriptions.Item>
                            <Descriptions.Item label="Batch">
                                {data.carton.batch?.batch_number ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Machine">
                                {data.carton.batch?.machine ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Production date">
                                {data.carton.batch?.production_date ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Shift">
                                {data.completion.shift ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Completed on">
                                {data.completion.completed_on ?? 'not resolvable'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Completed at">
                                {data.completion.completed_at ?? 'not resolvable'}
                            </Descriptions.Item>
                        </Descriptions>
                    </Card>

                    <Card title="Day-bin lot attribution" size="small">
                        <Space direction="vertical" size={12} style={{ width: '100%' }}>
                            <Alert type="info" showIcon message={data.day_bin_attribution.basis} />
                            {data.day_bin_attribution.reason !== null && (
                                <Alert type="warning" showIcon message={data.day_bin_attribution.reason} />
                            )}
                            {data.day_bin_attribution.window !== null && (
                                <Typography.Text type="secondary">
                                    Window: {data.day_bin_attribution.window.shift} shift on{' '}
                                    {data.day_bin_attribution.window.production_date} (
                                    {data.day_bin_attribution.window.timezone})
                                </Typography.Text>
                            )}
                            <Table<CartonTraceLot>
                                size="small"
                                rowKey={(row) => `${row.supplier_lot_no}-${row.grn_reference}`}
                                columns={lotColumns('Loaded (kg)')}
                                dataSource={data.day_bin_attribution.lots}
                                pagination={false}
                                locale={{
                                    emptyText:
                                        'No day-bin loads were recorded during this production date and shift.',
                                }}
                            />
                            {parseFloat(data.day_bin_attribution.unattributed_loaded_kg) > 0 && (
                                <Typography.Text type="secondary">
                                    {fmtDecimal(data.day_bin_attribution.unattributed_loaded_kg)} kg was
                                    loaded without a bag identity in this window — kilograms with no lot
                                    to name.
                                </Typography.Text>
                            )}
                        </Space>
                    </Card>

                    {/*
                      * THE SECOND LEDGER, IN ITS OWN CARD AND UNDER ITS OWN
                      * SENTENCE. Material reaches production as a store issue
                      * now (DEC-20260817-001); those lots never went through
                      * the day bin, so they must not render under the card
                      * above, whose sentence is owner-fixed
                      * (DEC-20260810-001) and speaks only of what the bin
                      * held. Do not merge the two tables.
                      */}
                    <Card title="Store-issue lot attribution" size="small">
                        <Space direction="vertical" size={12} style={{ width: '100%' }}>
                            <Alert type="info" showIcon message={data.store_issue_attribution.basis} />
                            {data.store_issue_attribution.reason !== null && (
                                <Alert
                                    type="warning"
                                    showIcon
                                    message={data.store_issue_attribution.reason}
                                />
                            )}
                            {data.store_issue_attribution.window !== null && (
                                <Typography.Text type="secondary">
                                    Window: {data.store_issue_attribution.window.shift} shift on{' '}
                                    {data.store_issue_attribution.window.production_date} (
                                    {data.store_issue_attribution.window.timezone})
                                </Typography.Text>
                            )}
                            <Table<CartonTraceLot>
                                size="small"
                                rowKey={(row) => `${row.supplier_lot_no}-${row.grn_reference}`}
                                columns={lotColumns('Issued (kg)')}
                                dataSource={data.store_issue_attribution.lots}
                                pagination={false}
                                locale={{
                                    emptyText:
                                        'No material was issued from the store during this production date and shift.',
                                }}
                            />
                            {parseFloat(data.store_issue_attribution.unattributed_issued_kg) > 0 && (
                                <Typography.Text type="secondary">
                                    {fmtDecimal(data.store_issue_attribution.unattributed_issued_kg)} kg
                                    was issued without a lot identity in this window — kilograms with no
                                    lot to name.
                                </Typography.Text>
                            )}
                        </Space>
                    </Card>

                    <Card title="Batch costing" size="small">
                        <Space direction="vertical" size={12} style={{ width: '100%' }}>
                            <Alert type="info" showIcon message={data.costing.basis} />
                            {data.costing.reason !== null && (
                                <Alert type="warning" showIcon message={data.costing.reason} />
                            )}
                            <Descriptions size="small" column={{ xs: 1, md: 4 }}>
                                <Descriptions.Item label="Material cost total">
                                    {fmtDecimal(data.costing.material_cost_total)}
                                </Descriptions.Item>
                                <Descriptions.Item label="Resin (pool)">
                                    {fmtDecimal(data.costing.resin_cost)}
                                </Descriptions.Item>
                                <Descriptions.Item label="Other materials">
                                    {fmtDecimal(data.costing.other_cost)}
                                </Descriptions.Item>
                                <Descriptions.Item label="Cost per accepted piece">
                                    {fmtDecimal(data.costing.cost_per_accepted_unit)}
                                </Descriptions.Item>
                            </Descriptions>
                            {data.costing.allocations !== undefined && data.costing.allocations.length > 0 && (
                                <Table
                                    size="small"
                                    rowKey={(row) => `${row.item_id}-${row.rate_source}-${row.quantity}`}
                                    columns={[
                                        {
                                            title: 'Material',
                                            dataIndex: 'item_name',
                                            render: (v: string | null) => v ?? '—',
                                        },
                                        { title: 'Drawn (kg)', dataIndex: 'quantity', render: fmtDecimal },
                                        {
                                            title: 'Pool rate (₹/kg)',
                                            dataIndex: 'pool_rate',
                                            render: fmtDecimal,
                                        },
                                        { title: 'Amount (₹)', dataIndex: 'amount', render: fmtDecimal },
                                        { title: 'Rate source', dataIndex: 'rate_source' },
                                    ]}
                                    dataSource={data.costing.allocations}
                                    pagination={false}
                                />
                            )}
                        </Space>
                    </Card>
                </>
            )}
        </Space>
    );
}
