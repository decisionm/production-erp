import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Card,
    Col,
    Form,
    Input,
    InputNumber,
    Modal,
    Row,
    Select,
    Space,
    Switch,
    Table,
    Tabs,
    Tag,
    Typography,
} from 'antd';
import { useMemo, useState } from 'react';
import { listAllItems } from '@/features/inventory/api';
import {
    approveProductionConfiguration,
    machineLabel,
    copyProductionConfiguration,
    createProductionConfiguration,
    deactivateProductionConfiguration,
    importProductionConfigurations,
    listDowntimeReasons,
    listFactorySettings,
    listMolds,
    listProductionConfigurations,
    listWorkCenters,
    saveDowntimeReason,
    saveFactorySetting,
    updateWorkCenterCapability,
} from '@/features/production/api';
import type { DowntimeReason, ImportResult, ProductionConfiguration, WorkCenter } from '@/features/production/types';
import { itemLabel } from '@/lib/itemLabel';

/**
 * The Production Configuration area — where the factory's real values are
 * entered and approved without a code deploy.
 *
 * The organising rule of this screen: nothing here is a production standard
 * until someone approves it. Imported and hand-entered rows are drafts, the
 * factory's own "To Confirm" wording is shown beside them, and approval is a
 * deliberate button with an actor behind it.
 */
export default function ProductionConfigurationPage() {
    return (
        <div style={{ padding: 24 }}>
            <Typography.Title level={3}>Production Configuration</Typography.Title>
            <Typography.Paragraph type="secondary" style={{ maxWidth: 820 }}>
                Machine, product, mould and colour together decide the cycle time and cavities a batch runs
                at. Only <Typography.Text strong>approved</Typography.Text> configurations reach the shop
                floor — everything else stays a draft.
            </Typography.Paragraph>

            <Tabs
                defaultActiveKey="configurations"
                items={[
                    { key: 'configurations', label: 'Machine–Product Configurations', children: <ConfigurationsTab /> },
                    { key: 'machines', label: 'Machines & Capabilities', children: <MachinesTab /> },
                    { key: 'downtime', label: 'Downtime Reasons', children: <DowntimeReasonsTab /> },
                    { key: 'settings', label: 'Factory Settings', children: <SettingsTab /> },
                    { key: 'import', label: 'Import', children: <ImportTab /> },
                ]}
            />
        </div>
    );
}

const STATUS_COLOUR: Record<string, string> = { draft: 'default', approved: 'success', inactive: 'warning' };

function ConfigurationsTab() {
    const queryClient = useQueryClient();
    const [status, setStatus] = useState<string | undefined>();
    const [search, setSearch] = useState('');
    const [creating, setCreating] = useState(false);
    const [form] = Form.useForm();

    const { data, isFetching } = useQuery({
        queryKey: ['production', 'configurations', status, search],
        queryFn: () => listProductionConfigurations({ status, search: search || undefined }),
    });
    // Active only: a retired machine must not be selectable for a new
    // configuration, or the configuration is unusable the moment it is
    // approved.
    const { data: machines } = useQuery({
        queryKey: ['production', 'work-centers', 'active'],
        queryFn: () => listWorkCenters(true),
    });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    const { data: molds } = useQuery({ queryKey: ['production', 'molds'], queryFn: listMolds });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['production', 'configurations'] });
    const onError = (error: any) => {
        const errors = error?.response?.data?.errors;
        Modal.error({
            title: 'Could not save',
            // Field-level messages from the backend say exactly what is
            // missing or out of range — surface them rather than a generic
            // failure the user cannot act on.
            content: errors
                ? Object.values(errors).flat().join(' ')
                : (error?.response?.data?.message ?? 'Unexpected error.'),
        });
    };

    const createMutation = useMutation({
        mutationFn: createProductionConfiguration,
        onSuccess: () => {
            invalidate();
            setCreating(false);
            form.resetFields();
        },
        onError,
    });
    const approveMutation = useMutation({ mutationFn: approveProductionConfiguration, onSuccess: invalidate, onError });
    const deactivateMutation = useMutation({ mutationFn: deactivateProductionConfiguration, onSuccess: invalidate, onError });
    const copyMutation = useMutation({ mutationFn: copyProductionConfiguration, onSuccess: invalidate, onError });

    const columns = [
        {
            title: 'Machine',
            render: (_: unknown, row: ProductionConfiguration) => row.work_center.name ?? `#${row.work_center.id}`,
        },
        {
            title: 'Product',
            render: (_: unknown, row: ProductionConfiguration) => (
                <>
                    <div>{row.item.name ?? `#${row.item.id}`}</div>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        {[row.item.sku, row.mold?.name, row.colour].filter(Boolean).join(' · ')}
                    </Typography.Text>
                </>
            ),
        },
        { title: 'CT (s)', dataIndex: 'default_cycle_time' },
        { title: 'Cavities', dataIndex: 'default_cavities' },
        { title: 'Weight (g)', dataIndex: 'unit_weight_grams' },
        {
            title: 'Status',
            render: (_: unknown, row: ProductionConfiguration) => (
                <Space direction="vertical" size={2}>
                    <Tag color={STATUS_COLOUR[row.status]}>{row.status}</Tag>
                    {/* The factory's own wording, shown verbatim so nobody
                        mistakes an unreviewed candidate for a decision. */}
                    {row.confirmation_status && (
                        <Typography.Text type="secondary" style={{ fontSize: 11 }}>
                            {row.confirmation_status}
                        </Typography.Text>
                    )}
                </Space>
            ),
        },
        {
            title: 'Actions',
            render: (_: unknown, row: ProductionConfiguration) => (
                <Space>
                    {row.status === 'draft' && (
                        <Button
                            type="primary"
                            size="small"
                            loading={approveMutation.isPending}
                            onClick={() => approveMutation.mutate(row.id)}
                        >
                            Approve
                        </Button>
                    )}
                    {row.status === 'approved' && (
                        <Button size="small" danger onClick={() => deactivateMutation.mutate(row.id)}>
                            Deactivate
                        </Button>
                    )}
                    <Button size="small" onClick={() => copyMutation.mutate(row.id)}>
                        Copy
                    </Button>
                </Space>
            ),
        },
    ];

    return (
        <>
            <Space style={{ marginBottom: 16 }} wrap>
                <Input.Search
                    placeholder="Search product…"
                    allowClear
                    onSearch={setSearch}
                    style={{ width: 260 }}
                />
                <Select
                    allowClear
                    placeholder="All statuses"
                    style={{ width: 160 }}
                    value={status}
                    onChange={setStatus}
                    options={[
                        { value: 'draft', label: 'Draft' },
                        { value: 'approved', label: 'Approved' },
                        { value: 'inactive', label: 'Inactive' },
                    ]}
                />
                <Button type="primary" onClick={() => setCreating(true)}>
                    New Configuration
                </Button>
            </Space>

            <Table
                rowKey="id"
                size="small"
                loading={isFetching}
                dataSource={data?.data ?? []}
                columns={columns as never}
                pagination={false}
            />

            <Modal
                title="New configuration"
                open={creating}
                onCancel={() => setCreating(false)}
                okText="Create draft"
                confirmLoading={createMutation.isPending}
                onOk={() => form.validateFields().then((values) => createMutation.mutate(values))}
                destroyOnHidden
            >
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="Created as a draft. It reaches the shop floor only once approved."
                />
                <Form form={form} layout="vertical">
                    <Form.Item name="work_center_id" label="Machine" rules={[{ required: true }]}>
                        <Select
                            showSearch
                            optionFilterProp="label"
                            options={(machines?.data ?? []).map((m) => ({ value: m.id, label: machineLabel(m) }))}
                        />
                    </Form.Item>
                    <Form.Item name="item_id" label="Product" rules={[{ required: true }]}>
                        <Select
                            showSearch
                            optionFilterProp="label"
                            options={(items?.data ?? []).map((i) => ({ value: i.id, label: itemLabel(i) }))}
                        />
                    </Form.Item>
                    <Row gutter={12}>
                        <Col span={12}>
                            <Form.Item name="mold_id" label="Mould (optional)">
                                <Select
                                    allowClear
                                    showSearch
                                    optionFilterProp="label"
                                    options={(molds?.data ?? []).map((m) => ({ value: m.id, label: m.name }))}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="colour" label="Colour (optional)">
                                <Input placeholder="Amber, Clear…" />
                            </Form.Item>
                        </Col>
                    </Row>
                    <Row gutter={12}>
                        <Col span={8}>
                            <Form.Item name="default_cycle_time" label="Cycle time (s)">
                                <InputNumber min={0.1} style={{ width: '100%' }} />
                            </Form.Item>
                        </Col>
                        <Col span={8}>
                            <Form.Item name="default_cavities" label="Cavities">
                                <InputNumber min={1} style={{ width: '100%' }} />
                            </Form.Item>
                        </Col>
                        <Col span={8}>
                            <Form.Item name="unit_weight_grams" label="Unit weight (g)">
                                <InputNumber min={0.0001} style={{ width: '100%' }} />
                            </Form.Item>
                        </Col>
                    </Row>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        Cycle time, cavities and weight are all required before this can be approved — but you can
                        save what you know now and fill the rest in as the factory confirms it.
                    </Typography.Text>
                </Form>
            </Modal>
        </>
    );
}

function MachinesTab() {
    const queryClient = useQueryClient();
    // Defaults to the machines actually in service. Retired ones stay
    // reachable behind the filter — production history references them and
    // they must remain inspectable, just not selectable.
    const [showInactive, setShowInactive] = useState(false);
    const { data, isFetching } = useQuery({
        queryKey: ['production', 'work-centers', showInactive ? 'inactive' : 'active'],
        queryFn: () => listWorkCenters(!showInactive),
    });
    const [editing, setEditing] = useState<WorkCenter | null>(null);
    const [form] = Form.useForm();

    const mutation = useMutation({
        mutationFn: (values: any) => {
            const permitted =
                typeof values.permitted_cavities === 'string' && values.permitted_cavities.trim() !== ''
                    ? values.permitted_cavities
                          .split(',')
                          .map((v: string) => Number(v.trim()))
                          .filter((v: number) => Number.isFinite(v) && v > 0)
                    : null;
            return updateWorkCenterCapability(editing!.id, { ...values, permitted_cavities: permitted });
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'work-centers'] });
            setEditing(null);
        },
        onError: (error: any) =>
            Modal.error({ title: 'Could not save', content: error?.response?.data?.message ?? 'Unexpected error.' }),
    });

    return (
        <>
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="A blank limit means “not known”, and never blocks anything."
                description="Only a stated limit constrains a configuration. Today every machine's cavity limits are blank — the factory's master workbook leaves them empty."
                closable
            />
            <Space style={{ marginBottom: 16 }}>
                <Switch checked={showInactive} onChange={setShowInactive} size="small" />
                <Typography.Text>{showInactive ? 'Showing retired machines' : 'Showing active machines'}</Typography.Text>
            </Space>
            <Table
                rowKey="id"
                size="small"
                loading={isFetching}
                dataSource={data?.data ?? []}
                pagination={false}
                columns={
                    [
                        { title: 'Code', dataIndex: 'code' },
                        { title: 'Name', dataIndex: 'name' },
                        { title: 'Class', dataIndex: 'capacity_class', render: (v: string) => v ?? '—' },
                        {
                            title: 'Cavities',
                            render: (_: unknown, row: WorkCenter) =>
                                row.permitted_cavities?.length
                                    ? row.permitted_cavities.join(' / ')
                                    : row.min_cavities || row.max_cavities
                                      ? `${row.min_cavities ?? '?'}–${row.max_cavities ?? '?'}`
                                      : '—',
                        },
                        {
                            title: 'Cycle time (s)',
                            render: (_: unknown, row: WorkCenter) =>
                                row.cycle_time_min || row.cycle_time_max
                                    ? `${row.cycle_time_min ?? '?'}–${row.cycle_time_max ?? '?'}`
                                    : '—',
                        },
                        {
                            title: '',
                            render: (_: unknown, row: WorkCenter) => (
                                <Button
                                    size="small"
                                    onClick={() => {
                                        setEditing(row);
                                        form.setFieldsValue({
                                            ...row,
                                            permitted_cavities: row.permitted_cavities?.join(', ') ?? '',
                                        });
                                    }}
                                >
                                    Edit
                                </Button>
                            ),
                        },
                    ] as never
                }
            />

            <Modal
                title={`Capabilities — ${editing?.name ?? ''}`}
                open={editing !== null}
                onCancel={() => setEditing(null)}
                confirmLoading={mutation.isPending}
                onOk={() => form.validateFields().then((v) => mutation.mutate(v))}
                destroyOnHidden
            >
                <Form form={form} layout="vertical">
                    <Form.Item name="name" label="Display name">
                        <Input />
                    </Form.Item>
                    <Form.Item name="capacity_class" label="Capacity class">
                        <Input placeholder="Small, High Capacity…" />
                    </Form.Item>
                    <Row gutter={12}>
                        <Col span={12}>
                            <Form.Item name="min_cavities" label="Min cavities">
                                <InputNumber min={1} style={{ width: '100%' }} />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="max_cavities" label="Max cavities">
                                <InputNumber min={1} style={{ width: '100%' }} />
                            </Form.Item>
                        </Col>
                    </Row>
                    <Form.Item
                        name="permitted_cavities"
                        label="Permitted cavity options"
                        extra="Comma-separated, for machines whose options are not a continuous range (e.g. 6, 7, 8). Overrides min/max."
                    >
                        <Input placeholder="6, 7, 8" />
                    </Form.Item>
                    <Row gutter={12}>
                        <Col span={12}>
                            <Form.Item name="cycle_time_min" label="Min cycle time (s)">
                                <InputNumber min={0.1} style={{ width: '100%' }} />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="cycle_time_max" label="Max cycle time (s)">
                                <InputNumber min={0.1} style={{ width: '100%' }} />
                            </Form.Item>
                        </Col>
                    </Row>
                </Form>
            </Modal>
        </>
    );
}

function DowntimeReasonsTab() {
    const queryClient = useQueryClient();
    const { data, isFetching } = useQuery({
        queryKey: ['production', 'downtime-reasons'],
        queryFn: () => listDowntimeReasons(),
    });

    const mutation = useMutation({
        mutationFn: ({ row, patch }: { row: DowntimeReason; patch: Partial<DowntimeReason> }) =>
            saveDowntimeReason({ ...row, ...patch } as never, row.id),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['production', 'downtime-reasons'] }),
    });

    return (
        <>
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="Planned downtime entered before Start lowers the target. Unplanned downtime explains the gap without lowering it."
                closable
            />
            <Table
                rowKey="id"
                size="small"
                loading={isFetching}
                dataSource={data?.data ?? []}
                pagination={false}
                columns={
                    [
                        { title: 'Code', dataIndex: 'code' },
                        { title: 'Description', dataIndex: 'description' },
                        { title: 'Category', dataIndex: 'category' },
                        {
                            title: 'Type',
                            render: (_: unknown, row: DowntimeReason) => (
                                <Select
                                    size="small"
                                    style={{ width: 130 }}
                                    value={row.planning_type}
                                    onChange={(v) => mutation.mutate({ row, patch: { planning_type: v } })}
                                    options={[
                                        { value: 'planned', label: 'Planned' },
                                        { value: 'unplanned', label: 'Unplanned' },
                                    ]}
                                />
                            ),
                        },
                        {
                            title: 'At Start',
                            render: (_: unknown, row: DowntimeReason) => (
                                <Switch
                                    size="small"
                                    checked={row.selectable_at_start}
                                    onChange={(v) => mutation.mutate({ row, patch: { selectable_at_start: v } })}
                                />
                            ),
                        },
                        {
                            title: 'Active',
                            render: (_: unknown, row: DowntimeReason) => (
                                <Switch
                                    size="small"
                                    checked={row.is_active}
                                    onChange={(v) => mutation.mutate({ row, patch: { is_active: v } })}
                                />
                            ),
                        },
                        {
                            title: 'Status',
                            render: (_: unknown, row: DowntimeReason) =>
                                row.confirmation_status ? <Tag>{row.confirmation_status}</Tag> : '—',
                        },
                    ] as never
                }
            />
        </>
    );
}

function SettingsTab() {
    const queryClient = useQueryClient();
    const { data, isFetching } = useQuery({ queryKey: ['production', 'factory-settings'], queryFn: listFactorySettings });
    const [edits, setEdits] = useState<Record<string, string>>({});

    const mutation = useMutation({
        mutationFn: saveFactorySetting,
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['production', 'factory-settings'] }),
    });

    return (
        <Table
            rowKey="id"
            size="small"
            loading={isFetching}
            dataSource={data?.data ?? []}
            pagination={false}
            columns={
                [
                    {
                        title: 'Setting',
                        render: (_: unknown, row: any) => (
                            <>
                                <div>{row.label ?? row.key}</div>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    {row.description}
                                </Typography.Text>
                            </>
                        ),
                    },
                    {
                        title: 'Value',
                        width: 220,
                        render: (_: unknown, row: any) => (
                            <Space.Compact style={{ width: '100%' }}>
                                <Input
                                    size="small"
                                    value={edits[row.key] ?? row.value ?? ''}
                                    onChange={(e) => setEdits((s) => ({ ...s, [row.key]: e.target.value }))}
                                />
                                <Button
                                    size="small"
                                    type="primary"
                                    disabled={edits[row.key] === undefined || edits[row.key] === row.value}
                                    loading={mutation.isPending}
                                    onClick={() => mutation.mutate({ key: row.key, value: edits[row.key] })}
                                >
                                    Save
                                </Button>
                            </Space.Compact>
                        ),
                    },
                    {
                        title: 'Status',
                        render: (_: unknown, row: any) =>
                            row.confirmation_status ? <Tag>{row.confirmation_status}</Tag> : '—',
                    },
                ] as never
            }
        />
    );
}

/**
 * Paste rows from the factory workbook (Excel copies as tab-separated) and
 * see exactly what would happen before anything is written. The dry run is
 * the default and the write is a second, separate click.
 */
function ImportTab() {
    const queryClient = useQueryClient();
    const [raw, setRaw] = useState('');
    const [result, setResult] = useState<ImportResult | null>(null);

    const rows = useMemo(() => {
        return raw
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean)
            .map((line) => {
                const [machine, item, mold, colour, cycle_time, cavities, unit_weight_grams, mapping_id] =
                    line.split(/\t|,(?=(?:[^"]*"[^"]*")*[^"]*$)/).map((c) => c.trim());
                return {
                    machine,
                    item,
                    mold: mold || null,
                    colour: colour || null,
                    cycle_time: cycle_time || null,
                    cavities: cavities || null,
                    unit_weight_grams: unit_weight_grams || null,
                    mapping_id: mapping_id || null,
                    confirmation_status: 'To Confirm',
                };
            });
    }, [raw]);

    const mutation = useMutation({
        mutationFn: (dryRun: boolean) => importProductionConfigurations(rows as never, dryRun),
        onSuccess: (data) => {
            setResult(data);
            if (!data.dry_run) queryClient.invalidateQueries({ queryKey: ['production', 'configurations'] });
        },
        onError: (error: any) =>
            Modal.error({ title: 'Import failed', content: error?.response?.data?.message ?? 'Unexpected error.' }),
    });

    return (
        <Row gutter={16}>
            <Col xs={24} lg={10}>
                <Card size="small" title="Paste rows">
                    <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
                        One row per line:{' '}
                        <Typography.Text code>machine, product, mould, colour, cycle time, cavities, weight, mapping id</Typography.Text>
                        . Copying straight from the workbook (tab-separated) works too. Every imported row lands as a{' '}
                        <Typography.Text strong>draft</Typography.Text>.
                    </Typography.Paragraph>
                    <Input.TextArea
                        rows={10}
                        value={raw}
                        onChange={(e) => setRaw(e.target.value)}
                        placeholder="MC-01, 500 ml Round Amber, , Amber, 12, 8, 31.5, MAP-CAND-001"
                    />
                    <Space style={{ marginTop: 12 }}>
                        <Button
                            type="primary"
                            disabled={rows.length === 0}
                            loading={mutation.isPending}
                            onClick={() => mutation.mutate(true)}
                        >
                            Dry run ({rows.length})
                        </Button>
                        <Button
                            danger
                            disabled={!result || result.summary.create === 0}
                            loading={mutation.isPending}
                            onClick={() => mutation.mutate(false)}
                        >
                            Import {result?.summary.create ?? 0} as drafts
                        </Button>
                    </Space>
                </Card>
            </Col>
            <Col xs={24} lg={14}>
                {result && (
                    <Card
                        size="small"
                        title={result.dry_run ? 'Dry run — nothing written' : 'Imported'}
                        extra={
                            <Space>
                                <Tag color="success">{result.summary.create ?? 0} create</Tag>
                                <Tag color="warning">{result.summary.conflict ?? 0} conflict</Tag>
                                <Tag color="error">{result.summary.rejected ?? 0} rejected</Tag>
                            </Space>
                        }
                    >
                        <Table
                            rowKey="row"
                            size="small"
                            dataSource={result.rows}
                            pagination={false}
                            columns={
                                [
                                    { title: '#', dataIndex: 'row', width: 50 },
                                    {
                                        title: 'Action',
                                        width: 100,
                                        render: (_: unknown, r: any) => (
                                            <Tag
                                                color={
                                                    r.action === 'create'
                                                        ? 'success'
                                                        : r.action === 'conflict'
                                                          ? 'warning'
                                                          : 'error'
                                                }
                                            >
                                                {r.action}
                                            </Tag>
                                        ),
                                    },
                                    { title: 'Reason', render: (_: unknown, r: any) => r.reason ?? '—' },
                                ] as never
                            }
                        />
                    </Card>
                )}
            </Col>
        </Row>
    );
}
