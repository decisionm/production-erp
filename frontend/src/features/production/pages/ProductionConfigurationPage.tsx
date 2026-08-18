import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Card,
    message,
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
import { useSearchParams } from 'react-router-dom';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
import { listAllWarehouses } from '@/features/inventory/api';
import PackingMaterialsTab from '@/features/production/components/PackingMaterialsTab';
import ProductStandardsPage from '@/features/production/pages/ProductStandardsPage';
import { hasManageAccess } from '@/features/auth/permissions';
import { showApiError } from '@/lib/showApiError';
import { useAuthStore } from '@/features/auth/store';
import {
    createWorkCenter,
    importProductionConfigurations,
    listDowntimeReasons,
    listFactorySettings,
    listWorkCenters,
    saveDowntimeReason,
    saveFactorySetting,
    getFactoryWarehouseSettings,
    setFactoryWarehouse,
    updateWorkCenter,
} from '@/features/production/api';
import type { FactoryWarehouseRole, WorkCenterWritePayload } from '@/features/production/api';
import type { DowntimeReason, ImportResult, WorkCenter } from '@/features/production/types';

/**
 * Production Configuration — ONE place where the factory is set up, editable
 * without a deploy.
 *
 * This page used to be called Machine Setup and hold only the machine half,
 * while Product Standards sat in the menu as a separate page holding the
 * product half. That split was the owner's complaint: nothing on either screen
 * told you which of the two owned the setting you were looking for, so a
 * supervisor had to already know the answer to find it.
 *
 * The two halves are now tabs of one workspace, products first because that is
 * the screen the floor opens daily. Nothing was merged in the database: the
 * Product Standards tab renders exactly the component the old page rendered,
 * against exactly the same endpoints and the same permissions. Only the door
 * changed — /production/standards still resolves, as a redirect that keeps its
 * query string (see App.tsx).
 */

/**
 * Tab keys, in tab order. `?tab=` accepts exactly these; anything else lands
 * on the default.
 *
 * `products` is the default because it answers the question the factory
 * actually asks this page ("can this product run, and if not, what is
 * missing?"). `machines` is still addressable by name, which is what the
 * retired /production/work-centers URL redirects to.
 */
const TAB_KEYS = ['products', 'machines', 'packing', 'downtime', 'settings', 'import'] as const;
type TabKey = (typeof TAB_KEYS)[number];
const DEFAULT_TAB: TabKey = 'products';

export default function ProductionConfigurationPage() {
    // Addressable tabs. Without this, "Machine Setup → Factory Settings" in
    // someone else's prose lands the reader on whichever tab happened to be
    // the default, and the retired /production/work-centers URL has no way to
    // redirect to the machine master specifically.
    const [searchParams, setSearchParams] = useSearchParams();
    const requested = searchParams.get('tab');
    const activeTab: TabKey = TAB_KEYS.includes(requested as TabKey) ? (requested as TabKey) : DEFAULT_TAB;

    return (
        <div style={{ padding: 24 }}>
            <Typography.Title level={3}>Production Configuration</Typography.Title>
            <Typography.Paragraph type="secondary" style={{ maxWidth: 820 }}>
                Everything the factory is set up with, in one place. Products and what they run to, the
                machines and what each one can do, the downtime reasons the floor picks from, and the
                factory-wide rules. Nothing here moves stock or posts to Tally — it only decides what the
                shop floor is allowed to do.
            </Typography.Paragraph>

            <Tabs
                activeKey={activeTab}
                onChange={(key) => {
                    const next = new URLSearchParams(searchParams);
                    next.set('tab', key);
                    // Replace, not push: clicking through the tabs should not
                    // cost one Back press each to leave the page.
                    setSearchParams(next, { replace: true });
                }}
                items={[
                    { key: 'products', label: 'Product Standards', children: <ProductStandardsPage embedded /> },
                    { key: 'machines', label: 'Machines & Capabilities', children: <MachinesTab /> },
                    // The packing-material master's own screen. Until it
                    // existed the only control over "which Tally item is this
                    // spec" was the completion drawer's per-batch picker,
                    // which saves nothing — a wrong mapping was visible on
                    // every voucher and correctable on none.
                    { key: 'packing', label: 'Packing Materials', children: <PackingMaterialsTab /> },
                    { key: 'downtime', label: 'Downtime Reasons', children: <DowntimeReasonsTab /> },
                    { key: 'settings', label: 'Factory Rules', children: <SettingsTab /> },
                    { key: 'import', label: 'Import from Workbook', children: <ImportTab /> },
                ]}
            />
        </div>
    );
}

/**
 * What a blank capability reads as.
 *
 * Not a dash. A dash looks like a value somebody forgot to type, and these
 * blanks are a real and deliberate state: the factory's master workbook leaves
 * every cavity field empty, so the software says so instead of inventing a
 * limit that would then start refusing runs.
 */
const NOT_CONFIRMED = 'Not confirmed';

/** A stated value, or the honest blank. */
function stated(value: string | number | null | undefined) {
    return value == null || value === '' ? (
        <Typography.Text type="secondary">{NOT_CONFIRMED}</Typography.Text>
    ) : (
        String(value)
    );
}

interface MachineFormValues {
    code: string;
    name: string;
    capacity_class?: string | null;
    capacity_hours_per_day?: number | null;
    min_cavities?: number | null;
    max_cavities?: number | null;
    /** Comma-separated in the form; a list of numbers on the wire. */
    permitted_cavities?: string | null;
    cycle_time_min?: number | null;
    cycle_time_max?: number | null;
    is_active?: boolean;
}

/** The add flow starts blank and in service — a machine is added to be run. */
const BLANK_MACHINE: MachineFormValues = {
    code: '',
    name: '',
    capacity_class: null,
    capacity_hours_per_day: null,
    min_cavities: null,
    max_cavities: null,
    permitted_cavities: '',
    cycle_time_min: null,
    cycle_time_max: null,
    is_active: true,
};

/**
 * The resource returns decimal columns as strings (`"8.00"`). An InputNumber
 * will display that but not increment it, so it is coerced on the way in.
 */
function numberOrNull(value: string | number | null | undefined): number | null {
    if (value == null || value === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function machineFormValues(row: WorkCenter): MachineFormValues {
    return {
        code: row.code,
        name: row.name,
        capacity_class: row.capacity_class ?? null,
        capacity_hours_per_day: numberOrNull(row.capacity_hours_per_day),
        min_cavities: row.min_cavities ?? null,
        max_cavities: row.max_cavities ?? null,
        permitted_cavities: row.permitted_cavities?.join(', ') ?? '',
        cycle_time_min: numberOrNull(row.cycle_time_min),
        cycle_time_max: numberOrNull(row.cycle_time_max),
        is_active: row.is_active,
    };
}

/**
 * Named fields, never a spread of the form store: the payload IS the contract
 * with the endpoint, and a spread quietly posts whatever else the form is
 * holding and relies on the backend to drop it.
 *
 * `default_shift_hours` and `confirmation_status` are absent on purpose. Both
 * columns exist and both come back on every read; nothing on this screen edits
 * them, and a key the payload never mentions is a column the backend never
 * touches.
 */
function machinePayload(values: MachineFormValues): WorkCenterWritePayload {
    const permitted =
        typeof values.permitted_cavities === 'string' && values.permitted_cavities.trim() !== ''
            ? values.permitted_cavities
                  .split(',')
                  .map((entry) => Number(entry.trim()))
                  .filter((entry) => Number.isFinite(entry) && entry > 0)
            : [];

    return {
        code: values.code.trim(),
        name: values.name.trim(),
        capacity_class: values.capacity_class?.trim() || null,
        capacity_hours_per_day: values.capacity_hours_per_day ?? null,
        min_cavities: values.min_cavities ?? null,
        max_cavities: values.max_cavities ?? null,
        permitted_cavities: permitted.length > 0 ? permitted : null,
        cycle_time_min: values.cycle_time_min ?? null,
        cycle_time_max: values.cycle_time_max ?? null,
        is_active: values.is_active ?? true,
    };
}

/**
 * The MACHINE MASTER. One record per machine, and the only place a machine is
 * created or edited.
 *
 * It replaces two screens that both wrote the same `work_centers` row from
 * different halves of it: a "Work Centers" admin page that knew about code,
 * name, hours and active state, and this tab, which knew about cavities and
 * cycle time. Neither could describe a whole machine. There is no second model
 * behind this page — the WorkCenter ids the shop floor, the day bin, downtime
 * and every batch already point at are exactly the rows listed here, which is
 * why retiring the old page cost no history.
 *
 * Codes stay MC-01..MC-10 (the floor's own names). The code field is editable
 * so a future rename is one edit, not a migration.
 */
function MachinesTab() {
    const queryClient = useQueryClient();
    const user = useAuthStore((s) => s.user);
    // Supervisors read this master constantly — it is how a machine's cavity
    // range is checked before a run. Only the office writes it.
    const canManage = hasManageAccess(user, 'machine-master');

    // Defaults to the machines actually in service. Turning the filter on adds
    // the retired ones to the same list rather than swapping to them, because
    // the question it answers is "did we ever have a machine like this?" — a
    // history read, not a different list.
    const [showInactive, setShowInactive] = useState(false);
    const { data, isFetching } = useQuery({
        queryKey: ['production', 'work-centers', showInactive ? 'all' : 'active'],
        queryFn: () => listWorkCenters(showInactive ? undefined : true),
    });

    /** `null` = closed, `'new'` = the add flow, a machine = editing that machine. */
    const [editing, setEditing] = useState<WorkCenter | 'new' | null>(null);
    const [form] = Form.useForm();

    // Field-level messages say exactly which limit was refused and why ("max
    // cavities must be at least min cavities"), and the shared helper prints
    // each one under its own key. A generic failure leaves the office guessing
    // at a number.
    const onError = (error: unknown) => showApiError(error, 'Could not save');

    const mutation = useMutation({
        mutationFn: (values: MachineFormValues) => {
            const payload = machinePayload(values);
            // The modal is the only caller and only exists while `editing` is
            // set. The null branch is unreachable — and refuses rather than
            // falling through to create, because "unreachable" going wrong
            // here would mean a duplicate machine in a live master.
            if (editing === null) return Promise.reject(new Error('No machine is open.'));
            return editing === 'new' ? createWorkCenter(payload) : updateWorkCenter(editing.id, payload);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'work-centers'] });
            setEditing(null);
        },
        onError,
    });

    const open = (row: WorkCenter | 'new') => {
        setEditing(row);
        // Cleared first, then filled. The form store outlives the modal (it is
        // destroyed on hide, the store is not), so without the reset an Add
        // straight after an Edit could open carrying the edited machine's code
        // — which on a live master is a duplicate machine one Enter away.
        // BLANK_MACHINE and machineFormValues both state all ten fields, so
        // the fill alone is already complete; the reset makes that independent
        // of how the form library treats preserved values.
        form.resetFields();
        form.setFieldsValue(row === 'new' ? BLANK_MACHINE : machineFormValues(row));
    };

    const columns = [
        { title: 'Code', dataIndex: 'code', width: 110, fixed: 'left' as const },
        { title: 'Machine', dataIndex: 'name', width: 200 },
        {
            title: 'Class',
            width: 140,
            render: (_: unknown, row: WorkCenter) => stated(row.capacity_class),
        },
        {
            title: 'Cavities',
            width: 150,
            render: (_: unknown, row: WorkCenter) =>
                row.permitted_cavities?.length
                    ? row.permitted_cavities.join(' / ')
                    : row.min_cavities != null || row.max_cavities != null
                      ? `${row.min_cavities ?? '?'}–${row.max_cavities ?? '?'}`
                      : NOT_CONFIRMED,
        },
        {
            title: 'Cycle time (s)',
            width: 150,
            render: (_: unknown, row: WorkCenter) =>
                row.cycle_time_min != null || row.cycle_time_max != null
                    ? `${row.cycle_time_min ?? '?'}–${row.cycle_time_max ?? '?'}`
                    : NOT_CONFIRMED,
        },
        {
            title: 'Hours/day',
            width: 120,
            render: (_: unknown, row: WorkCenter) => stated(row.capacity_hours_per_day),
        },
        {
            // "In service" was this screen's own word for the state every other
            // master calls Active. One vocabulary, product-wide, is the point.
            title: 'Status',
            width: 110,
            render: (_: unknown, row: WorkCenter) => <ConfigurationStatusTag entity="work-center" row={row} />,
        },
        ...(canManage
            ? [
                  {
                      title: '',
                      width: 190,
                      fixed: 'right' as const,
                      render: (_: unknown, row: WorkCenter) => (
                          <ConfigurationActionsCell
                              entity="work-center"
                              id={row.id}
                              can={row.can}
                              recordName={`${row.code} — ${row.name}`}
                              onEdit={() => open(row)}
                          />
                      ),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="A blank limit reads “Not confirmed”, and never blocks anything."
                description={
                    <>
                        Only a stated limit constrains a configuration. Where the factory's master workbook
                        left a cavity range or cycle-time band empty, this master says “Not confirmed” rather
                        than inventing a number — and a machine with nothing confirmed still runs everything.
                    </>
                }
                closable
            />

            <Space style={{ marginBottom: 16 }} wrap>
                <Space size={8}>
                    <Switch checked={showInactive} onChange={setShowInactive} size="small" />
                    <Typography.Text>
                        {showInactive ? 'Showing every machine, retired included' : 'Showing machines in service'}
                    </Typography.Text>
                </Space>
                {canManage && (
                    <Button type="primary" onClick={() => open('new')}>
                        Add Machine
                    </Button>
                )}
            </Space>

            {!canManage && (
                <Typography.Paragraph type="secondary" style={{ fontSize: 12, marginBottom: 12 }}>
                    Machine records are edited by the office.
                </Typography.Paragraph>
            )}

            <Table
                rowKey="id"
                size="small"
                loading={isFetching}
                dataSource={data?.data ?? []}
                pagination={false}
                scroll={{ x: 'max-content' }}
                columns={columns as never}
            />

            <Typography.Paragraph type="secondary" style={{ fontSize: 12, marginTop: 12 }}>
                A machine is retired, never deleted — every batch, downtime entry and material movement already
                recorded still points at its record, so it stays listed behind the filter above.
            </Typography.Paragraph>

            <Modal
                title={editing === 'new' ? 'Add machine' : `Machine — ${editing?.code ?? ''}`}
                open={editing !== null}
                onCancel={() => setEditing(null)}
                okText={editing === 'new' ? 'Add machine' : 'Save'}
                confirmLoading={mutation.isPending}
                onOk={() => form.validateFields().then((v) => mutation.mutate(v))}
                maskClosable={false}
                destroyOnHidden
                width={640}
            >
                <Form form={form} layout="vertical">
                    <Row gutter={12}>
                        <Col span={8}>
                            <Form.Item
                                name="code"
                                label="Code"
                                rules={[{ required: true, message: 'A code is required.' }, { max: 32 }]}
                                extra="The floor's own name for the machine."
                            >
                                <Input placeholder="MC-01" />
                            </Form.Item>
                        </Col>
                        <Col span={16}>
                            <Form.Item
                                name="name"
                                label="Display name"
                                rules={[{ required: true, message: 'A name is required.' }, { max: 255 }]}
                            >
                                <Input placeholder="Machine 1" />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        Everything below is optional. Leave a field blank and it stays “Not confirmed” — the
                        machine still runs, nothing is guessed, and it can be filled in the day the factory
                        measures it.
                    </Typography.Text>

                    <Row gutter={12} style={{ marginTop: 16 }}>
                        <Col span={12}>
                            <Form.Item name="capacity_class" label="Capacity class">
                                <Input placeholder="Small, High Capacity…" />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="capacity_hours_per_day" label="Capacity (hours/day)">
                                <InputNumber min={0} max={24} style={{ width: '100%' }} placeholder="Not confirmed" />
                            </Form.Item>
                        </Col>
                    </Row>
                    <Row gutter={12}>
                        <Col span={12}>
                            <Form.Item name="min_cavities" label="Min cavities">
                                <InputNumber min={1} max={512} style={{ width: '100%' }} placeholder="Not confirmed" />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="max_cavities" label="Max cavities">
                                <InputNumber min={1} max={512} style={{ width: '100%' }} placeholder="Not confirmed" />
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
                                <InputNumber min={0.1} style={{ width: '100%' }} placeholder="Not confirmed" />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="cycle_time_max" label="Max cycle time (s)">
                                <InputNumber min={0.1} style={{ width: '100%' }} placeholder="Not confirmed" />
                            </Form.Item>
                        </Col>
                    </Row>
                    {/* Shown, not set. The field stays in the form so a save
                        carries the machine's CURRENT state through untouched —
                        dropping it would make every edit of a retired machine
                        quietly put it back in service. Changing the state is
                        Archive / Reactivate on the row, which counts what uses
                        the machine first and takes a reason. */}
                    <Form.Item
                        name="is_active"
                        label="In service"
                        valuePropName="checked"
                        extra="Changed with Archive / Reactivate on the row — that path checks what already uses the machine and records why. Retired machines cannot be picked for a new run, and stay listed here with their history intact."
                    >
                        <Switch disabled />
                    </Form.Item>
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
                scroll={{ x: 'max-content' }}
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
                            // The state is READ here and CHANGED by Archive /
                            // Reactivate below. The Switch this replaces PUT
                            // `is_active` straight onto the reason — no
                            // dependency report, no reason recorded, and this
                            // master cascades to `production_downtime_events`.
                            title: 'Status',
                            render: (_: unknown, row: DowntimeReason) => (
                                <ConfigurationStatusTag entity="downtime-reason" row={row} />
                            ),
                        },
                        {
                            // The factory's own wording, shown verbatim — a
                            // different column from the lifecycle state, and
                            // kept apart from it on purpose.
                            title: 'Confirmation',
                            render: (_: unknown, row: DowntimeReason) =>
                                row.confirmation_status ? <Tag>{row.confirmation_status}</Tag> : '—',
                        },
                        {
                            title: 'Actions',
                            // The row edits itself in place (Type, At Start), so
                            // there is no separate Edit to offer.
                            render: (_: unknown, row: DowntimeReason) => (
                                <ConfigurationActionsCell
                                    entity="downtime-reason"
                                    id={row.id}
                                    can={row.can}
                                    recordName={`${row.code} — ${row.description}`}
                                />
                            ),
                        },
                    ] as never
                }
            />
        </>
    );
}

/**
 * The warehouse ROLES the floor resolves silently. This card exists because
 * its absence blocked the factory's first real batch: Start Batch refused
 * (correctly) to guess where finished goods land, and its error said "name one
 * in Production settings" — a place that, until this card, had no control to
 * do so. The backend endpoint predates the screen.
 *
 * THE PACKING MATERIAL STORE IS THE ODD ONE OUT, and the card says so rather
 * than reusing the other rows' warning. The other two roles fall back to the
 * single Tally-linked warehouse, which is safe on a one-godown factory: the
 * resin and the bottles are both in the one place Tally knows. Packing
 * material is exactly the case where that stops being true — a Packing
 * Material Store is a SECOND named location, named separately because cartons
 * do not come out of the resin store. So it has no fallback, an unset value
 * blocks nothing on the floor, and what waits for it is the Tally POST.
 */
function FactoryWarehousesCard() {
    const queryClient = useQueryClient();
    const { data: settings } = useQuery({
        queryKey: ['production', 'factory-warehouse-settings'],
        queryFn: getFactoryWarehouseSettings,
    });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses', 'all'], queryFn: listAllWarehouses });

    const mutation = useMutation({
        mutationFn: ({ role, warehouseId }: { role: FactoryWarehouseRole; warehouseId: number | null }) =>
            setFactoryWarehouse(role, warehouseId),
        onSuccess: () => {
            // The preview/readiness reads resolve through these settings, so
            // stale caches would keep showing the refusal after it is fixed.
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-warehouse-settings'] });
            queryClient.invalidateQueries({ queryKey: ['production', 'settings'] });
            message.success('Saved — the floor uses this from the next action.');
        },
        onError: (error: any) =>
            Modal.error({ title: 'Could not save', content: error?.response?.data?.message ?? 'Unexpected error.' }),
    });

    const options = (warehouses?.data ?? [])
        .filter((w) => w.is_active)
        .map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` }));

    /**
     * `unsetText` is per role, not shared. The default sentence names the
     * consequence of leaving a role blank — and for packing material that
     * consequence is a different one: nothing on the floor is refused, the
     * VOUCHER is. Saying "Start Batch is REFUSED" there would be a threat the
     * software does not carry out, which is how a warning stops being read.
     */
    const describe = (
        stored: number | null | undefined,
        resolved: number | null | undefined,
        unsetText = 'Nothing set and nothing resolvable — Start Batch is REFUSED until this is chosen.',
    ): string => {
        if (stored != null) return '';
        if (resolved != null) {
            const w = (warehouses?.data ?? []).find((x) => x.id === resolved);
            return `Nothing set — currently resolving to ${w ? w.name : `warehouse #${resolved}`}.`;
        }
        return unsetText;
    };

    const row = (
        label: string,
        help: string,
        role: FactoryWarehouseRole,
        stored: number | null | undefined,
        resolved: number | null | undefined,
        unsetText?: string,
    ) => (
        <div style={{ marginBottom: 12 }}>
            <Typography.Text strong style={{ display: 'block' }}>{label}</Typography.Text>
            <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>{help}</Typography.Text>
            <Select
                style={{ width: 360, maxWidth: '100%' }}
                placeholder="Choose a warehouse…"
                showSearch
                optionFilterProp="label"
                allowClear
                value={stored ?? undefined}
                options={options}
                onChange={(v) => mutation.mutate({ role, warehouseId: v ?? null })}
            />
            {describe(stored, resolved, unsetText) && (
                <Typography.Text
                    type={resolved == null && stored == null ? 'danger' : 'secondary'}
                    style={{ fontSize: 12, display: 'block', marginTop: 4 }}
                >
                    {describe(stored, resolved, unsetText)}
                </Typography.Text>
            )}
        </div>
    );

    return (
        <Card size="small" title="Factory warehouses" style={{ marginBottom: 16 }}>
            {row(
                'Finished-goods warehouse',
                'Where produced bottles are booked when a batch completes. Start Batch is refused until this resolves.',
                'finished_goods_warehouse_id',
                settings?.finished_goods_warehouse_id,
                settings?.finished_goods_resolved_warehouse_id,
            )}
            {row(
                'Raw-material store',
                'The store that material is issued from. It is the source of every issue to production — RM Store → Production/WIP → FG Store.',
                'raw_material_warehouse_id',
                settings?.raw_material_warehouse_id,
                settings?.raw_material_resolved_warehouse_id,
            )}
            {row(
                'Packing Material Store',
                'Where cartons, trays, pouches and tape are issued from on the Tally voucher. No fallback: cartons must not come out of the resin store, so this one is never guessed.',
                'packing_material_warehouse_id',
                settings?.packing_material_warehouse_id,
                settings?.packing_material_resolved_warehouse_id,
                // The truthful consequence. Nothing on the floor is blocked —
                // the shift is real and gets recorded either way; it is the
                // Tally post that waits for this.
                'Nothing set — packing lines have no store to issue from, so the Tally voucher will not post until this is chosen. Production itself is unaffected.',
            )}
        </Card>
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
        <>
        <FactoryWarehousesCard />
        <Table
            scroll={{ x: 'max-content' }}
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
        </>
    );
}

/**
 * Paste rows from the factory workbook (Excel copies as tab-separated) and
 * see exactly what would happen before anything is written. The dry run is
 * the default and the write is a second, separate click.
 *
 * The BEHAVIOUR here is deliberately unchanged — it is useful and it is safe:
 * check first, write second, and everything written lands as a draft that a
 * person still has to approve. What changed is that the screen now says so.
 * It was labelled "Import", which told the reader nothing about which file it
 * wanted, what it would create, or what it would overwrite — so the honest
 * response to it was to not touch it. The panel below answers those three
 * questions in the order they get asked.
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
        <>
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="What this screen does"
                description={
                    <>
                        <Typography.Paragraph style={{ marginBottom: 8 }}>
                            <Typography.Text strong>What to paste.</Typography.Text> Rows copied out of the
                            factory machine workbook in Excel. Select the rows, copy, paste into the box below —
                            one machine-and-product line per row, in this order:{' '}
                            <Typography.Text code>
                                machine, product, mould, colour, cycle time, cavities, weight, mapping id
                            </Typography.Text>
                            . Commas work too. There is no file to upload.
                        </Typography.Paragraph>
                        <Typography.Paragraph style={{ marginBottom: 8 }}>
                            <Typography.Text strong>What it creates.</Typography.Text> One{' '}
                            <Typography.Text strong>draft machine setting</Typography.Text> per row — this
                            product, run on this machine, at these cavities, this cycle time and this weight.
                            A draft does nothing on its own. Somebody has to open the product on the{' '}
                            <Typography.Text strong>Product Standards</Typography.Text> tab and approve it
                            before any machine runs to it.
                        </Typography.Paragraph>
                        <Typography.Paragraph style={{ marginBottom: 0 }}>
                            <Typography.Text strong>What it never changes.</Typography.Text> It never edits a
                            product's own agreed figures, never changes packing, never touches stock, never
                            posts anything to Tally, and never alters a setting the factory has already
                            approved — a row that clashes with an existing one is reported as a{' '}
                            <Typography.Text strong>conflict</Typography.Text> and skipped, not applied.
                            Press <Typography.Text strong>Check first</Typography.Text> to see exactly what
                            would happen; nothing at all is written until you press the second button.
                        </Typography.Paragraph>
                    </>
                }
            />
            <Row gutter={16}>
            <Col xs={24} lg={10}>
                <Card size="small" title="Paste rows from the workbook">
                    <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
                        One row per line:{' '}
                        <Typography.Text code>machine, product, mould, colour, cycle time, cavities, weight, mapping id</Typography.Text>
                        . Copying straight from the workbook (tab-separated) works too. Every imported row lands as a{' '}
                        <Typography.Text strong>draft</Typography.Text> machine setting — read, checked and
                        approved on the <Typography.Text strong>Product Standards</Typography.Text> tab, beside
                        the product it belongs to. Nothing reaches the shop floor until it is approved there.
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
                            Check first ({rows.length} rows) — writes nothing
                        </Button>
                        <Button
                            danger
                            disabled={!result || result.summary.create === 0}
                            loading={mutation.isPending}
                            onClick={() => mutation.mutate(false)}
                        >
                            Create {result?.summary.create ?? 0} drafts for approval
                        </Button>
                    </Space>
                </Card>
            </Col>
            <Col xs={24} lg={14}>
                {result && (
                    <Card
                        size="small"
                        title={result.dry_run ? 'Checked — nothing was written' : 'Drafts created — approve them on Product Standards'}
                        extra={
                            <Space>
                                <Tag color="success">{result.summary.create ?? 0} create</Tag>
                                <Tag color="warning">{result.summary.conflict ?? 0} conflict</Tag>
                                <Tag color="error">{result.summary.rejected ?? 0} rejected</Tag>
                            </Space>
                        }
                    >
                        <Table
                            scroll={{ x: 'max-content' }}
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
        </>
    );
}
