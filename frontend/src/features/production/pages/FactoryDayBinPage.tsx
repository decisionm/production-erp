import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, Col, DatePicker, Empty, Form, InputNumber, Row, Select, Space, Table, Tag, Typography, message } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { listAllItems, listAllWarehouses } from '@/features/inventory/api';
import { getFactoryDayBin, loadFactoryDayBin, setDayBinWarehouse } from '@/features/production/api';
import type { FactoryDayBinMaterial } from '@/features/production/types';
import { itemLabel } from '@/lib/itemLabel';

/** "1250.5000" → "1250.5"; "—" for null/unparseable. */
function fmtQty(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const parsed = parseFloat(value);
    return Number.isNaN(parsed) ? '—' : String(parseFloat(parsed.toFixed(4)));
}

/** The warehouse the bay would normally draw from — the main/raw-material store. */
function guessStoreWarehouseId(
    warehouses: { id: number; code: string; name: string; is_active: boolean }[],
    dayBinId: number | null,
): number | undefined {
    const candidates = warehouses.filter((w) => w.is_active && w.id !== dayBinId);
    const named = candidates.find((w) => /\bstore\b|\bmain\b|\braw\b|\brm\b/i.test(`${w.code} ${w.name}`));

    return (named ?? candidates[0])?.id;
}

/**
 * THE FACTORY DAY BIN — the central, always-visible answer to "what material
 * is in the factory right now, ready to run".
 *
 * The design in one line: the day bin is simply a WAREHOUSE. So this page has
 * no arithmetic of its own —
 *
 *  - the table is that warehouse's ordinary stock balances,
 *  - the form posts the ordinary store → warehouse stock transfer,
 *  - and nothing here consumes anything: consumption happens on the Shift
 *    Floor at Complete Batch, where each material line is issued FROM this
 *    warehouse and so reduces this table automatically.
 *
 * No barcode, no bag scan, no machine choice. The per-machine bag-level
 * ledger (Bin Bay Loading) still exists untouched for factories that want
 * that detail later — this is the simple central path.
 *
 * Until someone names the day-bin warehouse, the page shows one plain line
 * asking for it and nothing else in the ERP changes behaviour.
 */
export default function FactoryDayBinPage() {
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const [pickingWarehouse, setPickingWarehouse] = useState<number | null>(null);

    const { data: dayBin, isLoading } = useQuery({
        queryKey: ['production', 'factory-day-bin'],
        queryFn: getFactoryDayBin,
    });
    // The material/warehouse pick-lists are Inventory's reads. A production-only
    // login 403s on them — a normal answer, not a crash: the balances above
    // still show and one plain line explains why the pickers are empty.
    const { data: warehouses, isError: warehousesUnavailable } = useQuery({
        queryKey: ['inventory', 'warehouses', 'all'],
        queryFn: listAllWarehouses,
        retry: false,
    });
    const { data: items, isError: itemsUnavailable } = useQuery({
        queryKey: ['inventory', 'items', 'all'],
        queryFn: listAllItems,
        retry: false,
    });
    const inventoryReadsUnavailable = warehousesUnavailable || itemsUnavailable;

    const dayBinWarehouse = dayBin?.warehouse ?? null;
    const configured = dayBinWarehouse !== null;

    const warehouseOptions = useMemo(
        () =>
            (warehouses?.data ?? [])
                .filter((w) => w.is_active)
                .map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })),
        [warehouses],
    );
    // The bin can never be its own source — the transfer endpoint refuses
    // from === to, so it must not be offerable.
    const sourceOptions = useMemo(
        () => warehouseOptions.filter((option) => option.value !== dayBinWarehouse?.id),
        [warehouseOptions, dayBinWarehouse],
    );
    const itemOptions = useMemo(
        () => (items?.data ?? []).filter((i) => i.is_active).map((i) => ({ value: i.id, label: itemLabel(i) })),
        [items],
    );

    // Default the source to the main store once the lists land, without ever
    // overwriting a choice the user has already made.
    useEffect(() => {
        if (!configured || warehouses === undefined) return;
        if (form.getFieldValue('from_warehouse_id') !== undefined) return;

        const storeId = guessStoreWarehouseId(warehouses.data, dayBinWarehouse?.id ?? null);
        if (storeId !== undefined) form.setFieldsValue({ from_warehouse_id: storeId });
    }, [configured, warehouses, dayBinWarehouse, form]);

    const chooseWarehouse = useMutation({
        mutationFn: (warehouseId: number | null) => setDayBinWarehouse(warehouseId),
        onSuccess: () => {
            message.success('Day bin warehouse saved');
            setPickingWarehouse(null);
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-day-bin'] });
            // The Shift Floor reads the setting from /production/settings to
            // default its consumption warehouse — it must see this at once.
            queryClient.invalidateQueries({ queryKey: ['production', 'settings'] });
        },
        onError: (error: any) => {
            message.error(
                error?.response?.status === 403
                    ? 'You do not have permission to change production settings (needs Production: Manage).'
                    : (error?.response?.data?.message ?? 'Could not save the day bin warehouse'),
            );
        },
    });

    const load = useMutation({
        mutationFn: (values: { item_id: number; from_warehouse_id: number; quantity: number; loaded_at?: Dayjs }) =>
            loadFactoryDayBin({
                item_id: values.item_id,
                from_warehouse_id: values.from_warehouse_id,
                to_warehouse_id: dayBinWarehouse!.id,
                quantity: values.quantity,
                movement_date: (values.loaded_at ?? dayjs()).format('YYYY-MM-DD HH:mm:ss'),
                reference: 'Day bin load',
            }),
        onSuccess: () => {
            message.success('Loaded into the day bin');
            // Keep only the source warehouse and time — the bay usually loads
            // several materials from the same store in one go.
            form.setFieldsValue({ item_id: undefined, quantity: undefined });
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-day-bin'] });
            queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
        },
        onError: (error: any) => {
            const status = error?.response?.status;
            if (status === 403) {
                message.error('You do not have permission to move stock (needs Inventory: Manage).');
                return;
            }
            message.error(error?.response?.data?.message ?? 'Could not load the day bin');
        },
    });

    const columns = [
        {
            title: 'Material',
            key: 'material',
            render: (_: unknown, row: FactoryDayBinMaterial) =>
                row.item ? (
                    <Space direction="vertical" size={0}>
                        <Typography.Text strong>{row.item.name}</Typography.Text>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            {row.item.sku}
                        </Typography.Text>
                    </Space>
                ) : (
                    <Typography.Text type="secondary">Item #{row.item_id}</Typography.Text>
                ),
        },
        {
            title: 'In day bin now',
            key: 'quantity',
            align: 'right' as const,
            render: (_: unknown, row: FactoryDayBinMaterial) => (
                <Typography.Text strong style={{ fontSize: 18 }}>
                    {fmtQty(row.quantity_kg)} <Typography.Text type="secondary">{row.item?.uom ?? 'Kg'}</Typography.Text>
                </Typography.Text>
            ),
        },
    ];

    return (
        <Space direction="vertical" size="large" style={{ width: '100%' }}>
            <Space direction="vertical" size={0}>
                <Typography.Title level={3} style={{ marginBottom: 0 }}>
                    Day Bin (factory)
                </Typography.Title>
                <Typography.Text type="secondary">
                    One central bin for the whole factory. Load raw material here whenever it is needed; production
                    consumption reduces it automatically at Complete Batch. Loading moves material between locations —
                    it does not consume anything and posts nothing to Tally.
                </Typography.Text>
            </Space>

            {inventoryReadsUnavailable && (
                <Alert
                    type="warning"
                    showIcon
                    message="You can see the day bin but not load it"
                    description="Choosing a warehouse and moving material both read and write Inventory, which this login does not have. Ask for Inventory access (View to pick, Manage to load)."
                />
            )}

            {!configured && (
                <Alert
                    type="info"
                    showIcon
                    message="No day bin warehouse chosen yet"
                    description={
                        <Space direction="vertical" style={{ width: '100%' }}>
                            <Typography.Text>
                                Pick which warehouse is the factory day bin. Until then everything works exactly as it
                                does today.
                            </Typography.Text>
                            <Space wrap>
                                <Select
                                    style={{ minWidth: 280 }}
                                    placeholder="Choose the day bin warehouse…"
                                    options={warehouseOptions}
                                    showSearch
                                    optionFilterProp="label"
                                    value={pickingWarehouse ?? undefined}
                                    onChange={(value) => setPickingWarehouse(value)}
                                />
                                <Button
                                    type="primary"
                                    disabled={pickingWarehouse === null}
                                    loading={chooseWarehouse.isPending}
                                    onClick={() => chooseWarehouse.mutate(pickingWarehouse)}
                                >
                                    Save
                                </Button>
                            </Space>
                        </Space>
                    }
                />
            )}

            {configured && (
                <>
                    <Card
                        size="small"
                        title={
                            <Space wrap>
                                <span>
                                    Day bin: {dayBinWarehouse.code} — {dayBinWarehouse.name}
                                </span>
                                {dayBinWarehouse.tally_guid === null && <Tag color="orange">Not a Tally godown</Tag>}
                            </Space>
                        }
                        extra={
                            <Space wrap>
                                <Select
                                    size="small"
                                    style={{ minWidth: 220 }}
                                    placeholder="Change warehouse…"
                                    options={warehouseOptions}
                                    showSearch
                                    optionFilterProp="label"
                                    value={pickingWarehouse ?? undefined}
                                    onChange={(value) => setPickingWarehouse(value)}
                                />
                                <Button
                                    size="small"
                                    disabled={pickingWarehouse === null || pickingWarehouse === dayBinWarehouse.id}
                                    loading={chooseWarehouse.isPending}
                                    onClick={() => chooseWarehouse.mutate(pickingWarehouse)}
                                >
                                    Change
                                </Button>
                            </Space>
                        }
                    >
                        {dayBinWarehouse.tally_guid === null && (
                            <Alert
                                type="warning"
                                showIcon
                                style={{ marginBottom: 12 }}
                                message="This warehouse does not exist in Tally yet"
                                description="Consumption vouchers name the godown material came out of, so Tally will refuse a voucher issued from a godown it does not know. Pull godowns from Tally, or pick a warehouse that came from Tally."
                            />
                        )}
                        <Table
                            rowKey={(row) => row.item_id}
                            size="middle"
                            loading={isLoading}
                            columns={columns}
                            dataSource={dayBin?.materials ?? []}
                            pagination={false}
                            locale={{
                                emptyText: (
                                    <Empty description="Nothing in the day bin yet — load material below." />
                                ),
                            }}
                        />
                    </Card>

                    <Card size="small" title="Load material into the day bin">
                        <Form
                            form={form}
                            layout="vertical"
                            onFinish={(values) => load.mutate(values)}
                            initialValues={{ loaded_at: dayjs() }}
                        >
                            <Row gutter={[12, 0]}>
                                <Col xs={24} md={8}>
                                    <Form.Item
                                        name="item_id"
                                        label="Material"
                                        rules={[{ required: true, message: 'Pick the material' }]}
                                    >
                                        <Select
                                            size="large"
                                            options={itemOptions}
                                            showSearch
                                            optionFilterProp="label"
                                            placeholder="Resin / Masterbatch / …"
                                        />
                                    </Form.Item>
                                </Col>
                                <Col xs={12} md={4}>
                                    <Form.Item
                                        name="quantity"
                                        label="Quantity"
                                        rules={[
                                            { required: true, message: 'Enter the quantity' },
                                            // The transfer endpoint requires gt:0 — say so here
                                            // rather than letting the floor read a 422.
                                            {
                                                validator: (_, value) =>
                                                    value === undefined || value === null || value > 0
                                                        ? Promise.resolve()
                                                        : Promise.reject(new Error('Must be more than zero')),
                                            },
                                        ]}
                                    >
                                        <InputNumber size="large" min={0} style={{ width: '100%' }} placeholder="Kg" />
                                    </Form.Item>
                                </Col>
                                <Col xs={12} md={6}>
                                    <Form.Item name="loaded_at" label="Date & time">
                                        <DatePicker
                                            size="large"
                                            showTime
                                            format="DD MMM YYYY HH:mm"
                                            style={{ width: '100%' }}
                                        />
                                    </Form.Item>
                                </Col>
                                <Col xs={24} md={6}>
                                    <Form.Item
                                        name="from_warehouse_id"
                                        label="From"
                                        rules={[{ required: true, message: 'Pick where it came from' }]}
                                    >
                                        <Select
                                            size="large"
                                            options={sourceOptions}
                                            showSearch
                                            optionFilterProp="label"
                                            placeholder="Store…"
                                        />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Button type="primary" size="large" htmlType="submit" loading={load.isPending}>
                                Load into day bin
                            </Button>
                        </Form>
                    </Card>
                </>
            )}
        </Space>
    );
}
