import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Card,
    Col,
    Descriptions,
    Empty,
    Form,
    InputNumber,
    message,
    Modal,
    Radio,
    Row,
    Select,
    Space,
    Statistic,
    Table,
    Tag,
    Typography,
} from 'antd';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import BarcodeScanInput from '@/components/barcode/BarcodeScanInput';
import { listUsers } from '@/features/access/api';
import { useAuthStore } from '@/features/auth/store';
import { listAllItems } from '@/features/inventory/api';
import {
    getBinBayAvailability,
    getBinBayHistory,
    getMaterialBagPickList,
    listActiveBatches,
    listWorkCenters,
    loadBinBay,
    machineLabel,
    type BinBayLoadPayload,
} from '@/features/production/api';
import { useProductionSettings } from '@/features/production/packing';
import type { BinBayHistoryRow, BinBayLayer, BinBayRequirementComponent } from '@/features/production/types';
import { itemLabel } from '@/lib/itemLabel';

/** "10.6000" → "10.6"; "—" for null/unparseable. */
function fmtKg(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const parsed = parseFloat(value);
    return Number.isNaN(parsed) ? '—' : String(parseFloat(parsed.toFixed(4)));
}

function fmtWhen(value: string | null): string {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

/**
 * THE CENTRAL BIN BAY — one workspace where material is loaded into a
 * machine's day bin.
 *
 * This exists so loading is asked for ONCE, here, instead of inside every
 * batch. The batch screens read the bin; they never ask the supervisor to
 * declare the same bag again.
 *
 * What a load is, and is not: moving a bag into a bin bay is an inventory
 * LOCATION movement — the material travels from the store to the machine's
 * day bin and nothing else happens. It is NOT consumption, and it posts
 * NOTHING to Tally. Consumption is a separate figure worked out later, at
 * batch completion, from the weighed day-bin count. That sentence is on the
 * screen too, deliberately: a bay operator should never be left wondering
 * whether scanning a bag has already "used" it.
 */
export default function BinBayLoadingPage() {
    const currentUser = useAuthStore((state) => state.user);
    const settings = useProductionSettings();
    const traceabilityEnabled = settings?.traceability_enabled === true;
    const queryClient = useQueryClient();

    const [machineId, setMachineId] = useState<number | null>(null);
    const [materialId, setMaterialId] = useState<number | null>(null);
    const [loadedBy, setLoadedBy] = useState<number | null>(null);
    const [barcode, setBarcode] = useState<string | null>(null);
    const [mode, setMode] = useState<'full' | 'partial'>('full');
    const [weighedKg, setWeighedKg] = useState<number | null>(null);
    const [productId, setProductId] = useState<number | null>(null);
    const [expectedPieces, setExpectedPieces] = useState<number | null>(null);

    const { data: machines } = useQuery({
        queryKey: ['production', 'work-centers', 'active'],
        queryFn: () => listWorkCenters(true),
    });
    const {
        data: activeBatches,
        isLoading: activeBatchesLoading,
        isError: activeBatchesError,
    } = useQuery({
        queryKey: ['production', 'active-batches'],
        queryFn: listActiveBatches,
        enabled: traceabilityEnabled,
        refetchInterval: 20000,
    });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });

    // A bay operator usually has no user-admin rights, so /users 403s for
    // them. That is a normal answer, not an error: the picker quietly
    // collapses to "just me" rather than shouting at the floor.
    const { data: users, isError: usersUnavailable } = useQuery({
        queryKey: ['access', 'users', 'bin-bay'],
        queryFn: listUsers,
        retry: false,
    });

    const { data: availability, isLoading: availabilityLoading } = useQuery({
        queryKey: ['production', 'bin-bay', 'availability', machineId, materialId, productId, expectedPieces],
        queryFn: () =>
            getBinBayAvailability({
                work_center_id: machineId!,
                item_id: materialId ?? undefined,
                product_item_id: productId !== null && expectedPieces !== null ? productId : undefined,
                expected_pieces: productId !== null && expectedPieces !== null ? expectedPieces : undefined,
            }),
        enabled: traceabilityEnabled && machineId !== null,
    });

    const { data: history } = useQuery({
        queryKey: ['production', 'bin-bay', 'history', machineId],
        queryFn: () => getBinBayHistory(machineId!),
        enabled: traceabilityEnabled && machineId !== null,
    });

    // The store's FIFO pick list for the chosen material, used only to show
    // the scanned bag's weight and lot BEFORE submitting. The authoritative
    // FIFO check is the backend's — this never gates the load.
    const { data: pickList } = useQuery({
        queryKey: ['production', 'material-bags', 'pick-list', materialId],
        queryFn: () => getMaterialBagPickList(materialId!),
        enabled: traceabilityEnabled && materialId !== null,
        retry: false,
    });

    const scannedBag = useMemo(
        () => (barcode ? (pickList ?? []).find((bag) => bag.barcode === barcode) ?? null : null),
        [barcode, pickList],
    );
    const runningBatch = useMemo(
        () =>
            (activeBatches?.data ?? []).find(
                (entry) => entry.work_center.id === machineId && entry.batch_status === 'in_progress',
            ) ?? null,
        [activeBatches, machineId],
    );

    const machineOptions = (machines?.data ?? []).map((machine) => ({
        value: machine.id,
        label: machineLabel(machine),
    }));
    const itemOptions = (items?.data ?? []).map((item) => ({ value: item.id, label: itemLabel(item) }));
    const userOptions =
        usersUnavailable || !users
            ? currentUser
                ? [{ value: currentUser.id, label: `${currentUser.name} (you)` }]
                : []
            : users.data.map((user) => ({
                  value: user.id,
                  label: user.id === currentUser?.id ? `${user.name} (you)` : user.name,
              }));

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['production', 'bin-bay'] });
        queryClient.invalidateQueries({ queryKey: ['production', 'material-bags', 'pick-list'] });
        // The machine's day-bin drawer reads the same ledger.
        queryClient.invalidateQueries({ queryKey: ['production', 'day-bin', machineId] });
    };

    const loadMutation = useMutation({
        mutationFn: loadBinBay,
        onSuccess: (movement) => {
            invalidate();
            setBarcode(null);
            setWeighedKg(null);
            // Loading a material the bay hadn't picked yet: select it so the
            // bin card below is about what was just loaded.
            if (materialId === null) setMaterialId(movement.item_id);
            const bag = movement.material_bag;
            message.success(
                `Moved ${fmtKg(movement.quantity_kg)} kg into the bin bay${
                    bag ? ` — bag ${bag.barcode} has ${fmtKg(bag.remaining_kg)} kg left` : ''
                }`,
            );
        },
        onError: (error: any, variables: BinBayLoadPayload) => {
            const body = error?.response?.data;
            const text: string = body?.message ?? 'Unknown error';
            // FIFO refusal — keyed strictly off the machine-readable code,
            // never off message text. The override retry is still enforced
            // server-side (production.override-fifo) and recorded by name.
            if (!variables.override_fifo && body?.code === 'fifo_order') {
                Modal.confirm({
                    title: 'An older bag is still open',
                    content: `${text} Load this bag anyway? This needs the FIFO-override permission and is recorded against your name.`,
                    okText: 'Override FIFO',
                    okButtonProps: { danger: true },
                    onOk: () => loadMutation.mutate({ ...variables, override_fifo: true }),
                });
                return;
            }
            Modal.error({ title: 'Could not load the bag', content: text });
        },
    });

    const canSubmit =
        machineId !== null
        && barcode !== null
        && activeBatches !== undefined
        && !activeBatchesError
        && (mode === 'full' || (weighedKg !== null && weighedKg > 0));

    const performLoad = () => {
        if (!canSubmit) return;
        loadMutation.mutate({
            work_center_id: machineId!,
            barcode: barcode!,
            quantity_kg: mode === 'partial' ? weighedKg! : undefined,
            loaded_by: loadedBy ?? undefined,
            shift_production_entry_id: runningBatch?.id,
        });
    };

    const submit = () => {
        if (!canSubmit) return;
        if (runningBatch) {
            performLoad();
            return;
        }

        Modal.confirm({
            title: 'Pre-load this material?',
            content:
                'This machine has no running batch. The load will become opening stock in the centralized bin bay '
                + 'and the next batch will read it from there.',
            okText: 'Pre-load for next batch',
            onOk: performLoad,
        });
    };

    const layerColumns = [
        {
            title: 'Lot',
            key: 'lot',
            render: (_: unknown, layer: BinBayLayer) => layer.lot?.supplier_lot_no ?? (layer.lot ? `Lot ${layer.lot.id}` : '—'),
        },
        { title: 'Bag', dataIndex: 'barcode', key: 'barcode', render: (code: string | null) => code ?? '—' },
        {
            title: 'Loaded kg',
            dataIndex: 'loaded_kg',
            key: 'loaded_kg',
            align: 'right' as const,
            render: fmtKg,
        },
        {
            title: 'Still in bin',
            dataIndex: 'in_bin_kg',
            key: 'in_bin_kg',
            align: 'right' as const,
            render: (value: string) => (
                <Typography.Text type={parseFloat(value) > 0 ? undefined : 'secondary'}>{fmtKg(value)}</Typography.Text>
            ),
        },
    ];

    const requirementColumns = [
        {
            title: 'Component',
            key: 'component',
            render: (_: unknown, row: BinBayRequirementComponent) => (
                <Space direction="vertical" size={0}>
                    <span>{row.name}</span>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        {row.sku ?? '—'} · {row.uom ?? '—'}
                    </Typography.Text>
                </Space>
            ),
        },
        {
            title: 'Needs',
            dataIndex: 'expected_quantity',
            key: 'expected_quantity',
            align: 'right' as const,
            render: fmtKg,
        },
        {
            title: 'In bin',
            dataIndex: 'available_quantity',
            key: 'available_quantity',
            align: 'right' as const,
            render: fmtKg,
        },
        {
            title: 'Short by',
            key: 'shortage_quantity',
            align: 'right' as const,
            render: (_: unknown, row: BinBayRequirementComponent) => {
                if (row.shortage_quantity === null) {
                    return <Typography.Text type="secondary">not bin-tracked</Typography.Text>;
                }
                const short = parseFloat(row.shortage_quantity) > 0;
                return short ? (
                    <Tag color="error">{fmtKg(row.shortage_quantity)}</Tag>
                ) : (
                    <Tag color="success">enough</Tag>
                );
            },
        },
    ];

    const historyColumns = [
        { title: 'When', dataIndex: 'recorded_at', key: 'recorded_at', render: fmtWhen },
        {
            title: 'Material',
            key: 'item',
            render: (_: unknown, row: BinBayHistoryRow) => row.item?.name ?? '—',
        },
        {
            title: 'Bag / lot',
            key: 'bag',
            render: (_: unknown, row: BinBayHistoryRow) => (
                <Space direction="vertical" size={0}>
                    <span>{row.barcode ?? '—'}</span>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        {row.lot?.supplier_lot_no ?? '—'}
                    </Typography.Text>
                </Space>
            ),
        },
        { title: 'kg', dataIndex: 'quantity_kg', key: 'quantity_kg', align: 'right' as const, render: fmtKg },
        {
            title: 'Loaded by',
            key: 'loaded_by',
            render: (_: unknown, row: BinBayHistoryRow) => row.loaded_by?.name ?? '—',
        },
    ];

    if (settings !== undefined && !traceabilityEnabled) {
        return (
            <>
                <Typography.Title level={3}>Bin Bay Loading</Typography.Title>
                <Empty
                    description={
                        <>
                            Lot and bag traceability is switched off for this deployment, so there is no bin bay to
                            load. Turn on PROD_TRACEABILITY to start scanning bags into machines. Day-to-day loading
                            happens on <Link to="/production/day-bin">Day Bin (factory)</Link>.
                        </>
                    }
                />
            </>
        );
    }

    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>Bin Bay Loading</Typography.Title>
            <Typography.Paragraph type="secondary">
                Material is loaded into a machine&apos;s day bin here, once — the batch screens read the bin, so nobody
                is asked to declare the same bag again when a batch starts or finishes.
            </Typography.Paragraph>
            {/* This page is the OPTIONAL per-machine, bag-by-bag detail. The
                plain central path — one factory day bin, no barcode, no
                machine choice — is Day Bin (factory), and that is where the
                floor loads material day to day. */}
            <Typography.Paragraph type="secondary" style={{ marginTop: -8 }}>
                Loading the factory&apos;s one central day bin instead (no barcode, no machine)?{' '}
                <Link to="/production/day-bin">Go to Day Bin (factory)</Link>.
            </Typography.Paragraph>
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="Loading a bag is a stock LOCATION movement, not consumption"
                description={
                    'The material moves from the store to this machine’s day bin and nothing else happens. It is not '
                    + 'counted as consumed and nothing is posted to Tally. Consumption is worked out later, at batch '
                    + 'completion, from the weighed day-bin count (opening + loaded − closing − returned).'
                }
            />

            <Row gutter={16}>
                <Col xs={24} lg={9}>
                    <Card title="Load a bag">
                        <Form layout="vertical">
                            <Form.Item label="Machine / bin bay" required>
                                <Select
                                    size="large"
                                    value={machineId ?? undefined}
                                    onChange={(value) => setMachineId(value)}
                                    options={machineOptions}
                                    placeholder="Which machine's bin bay?"
                                    showSearch
                                    optionFilterProp="label"
                                />
                            </Form.Item>
                            {machineId !== null && (
                                <Alert
                                    type={activeBatchesError ? 'error' : runningBatch ? 'success' : 'warning'}
                                    showIcon
                                    style={{ marginBottom: 16 }}
                                    message={
                                        activeBatchesError
                                            ? 'Could not verify this machine’s running batch'
                                            : activeBatchesLoading || activeBatches === undefined
                                              ? 'Checking this machine’s running batch…'
                                              : runningBatch
                                            ? `Loading into Batch ${runningBatch.batch_number ?? `#${runningBatch.id}`}`
                                            : 'No batch is running on this machine'
                                    }
                                    description={
                                        activeBatchesError
                                            ? 'Loading is paused so material cannot be attached to the wrong batch. Refresh and try again.'
                                            : activeBatchesLoading || activeBatches === undefined
                                              ? 'The load button will unlock when the current machine state is known.'
                                              : runningBatch
                                            ? `${runningBatch.item.name} · this scan will be traceable to the active batch.`
                                            : 'You can still pre-load the bin for the next run, but the app will ask you to confirm.'
                                    }
                                />
                            )}
                            <Form.Item
                                label="Material"
                                help="Optional — pick it to see this bay's stock and the bag's weight before you load."
                            >
                                <Select
                                    value={materialId ?? undefined}
                                    onChange={(value) => setMaterialId(value ?? null)}
                                    options={itemOptions}
                                    placeholder="Resin, masterbatch…"
                                    showSearch
                                    optionFilterProp="label"
                                    allowClear
                                />
                            </Form.Item>
                            <Form.Item
                                label="Loaded by"
                                help={
                                    usersUnavailable
                                        ? 'Recorded against you.'
                                        : 'Defaults to you; name someone else only if they carried the bag.'
                                }
                            >
                                <Select
                                    value={loadedBy ?? currentUser?.id}
                                    onChange={(value) => setLoadedBy(value ?? null)}
                                    options={userOptions}
                                    disabled={usersUnavailable}
                                    showSearch
                                    optionFilterProp="label"
                                />
                            </Form.Item>
                            <Form.Item label="Bag barcode">
                                {/* Scanning captures the bag; the load is a separate,
                                    deliberate press — a bay load carries a weighed
                                    quantity, so scan-to-act would fire before the
                                    operator has read the scale. */}
                                <BarcodeScanInput onScan={setBarcode} placeholder="Scan or type the bag barcode…" />
                            </Form.Item>

                            {barcode !== null && (
                                <Descriptions
                                    size="small"
                                    column={1}
                                    bordered
                                    style={{ marginBottom: 16 }}
                                    extra={<Button size="small" onClick={() => setBarcode(null)}>Clear</Button>}
                                >
                                    <Descriptions.Item label="Bag">{barcode}</Descriptions.Item>
                                    <Descriptions.Item label="On the bag">
                                        {scannedBag
                                            ? `${fmtKg(scannedBag.remaining_kg)} kg`
                                            : materialId === null
                                              ? 'Pick a material to see the bag weight'
                                              : 'Not in this material’s open-bag list — checked on submit'}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="Lot">
                                        {scannedBag?.lot?.supplier_lot_no ?? '—'}
                                    </Descriptions.Item>
                                </Descriptions>
                            )}

                            <Form.Item label="How much">
                                <Radio.Group
                                    value={mode}
                                    onChange={(event) => setMode(event.target.value)}
                                    optionType="button"
                                    buttonStyle="solid"
                                >
                                    <Radio.Button value="full">Whole bag</Radio.Button>
                                    <Radio.Button value="partial">Weighed part</Radio.Button>
                                </Radio.Group>
                            </Form.Item>
                            {mode === 'partial' && (
                                <Form.Item label="Weighed kg" required>
                                    <InputNumber
                                        size="large"
                                        min={0.0001}
                                        step={0.1}
                                        value={weighedKg}
                                        onChange={(value) => setWeighedKg(value)}
                                        style={{ width: '100%' }}
                                        placeholder="What the scale says"
                                    />
                                </Form.Item>
                            )}

                            <Button
                                type="primary"
                                size="large"
                                block
                                disabled={!canSubmit}
                                loading={loadMutation.isPending}
                                onClick={submit}
                            >
                                Load into bin bay
                            </Button>
                        </Form>
                    </Card>
                </Col>

                <Col xs={24} lg={15}>
                    <Card
                        title={availability?.bin?.item ? `In this bay — ${availability.bin.item.name}` : 'In this bay'}
                        loading={availabilityLoading}
                        style={{ marginBottom: 16 }}
                    >
                        {machineId === null || availability?.bin == null ? (
                            <Empty
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                                description={
                                    machineId === null
                                        ? 'Pick a machine to see what its bin bay holds.'
                                        : 'Pick a material to see what this bay holds of it.'
                                }
                            />
                        ) : (
                            <>
                                <Space size="large" wrap style={{ marginBottom: 16 }}>
                                    <Statistic
                                        title="Available in bin"
                                        value={fmtKg(availability.bin.available_kg)}
                                        suffix="kg"
                                    />
                                    <Statistic
                                        title="Loaded here (all time)"
                                        value={fmtKg(availability.bin.loaded_kg)}
                                        suffix="kg"
                                    />
                                </Space>
                                {parseFloat(availability.bin.unattributed_kg) > 0 && (
                                    <Alert
                                        type="warning"
                                        showIcon
                                        style={{ marginBottom: 16 }}
                                        message={`${fmtKg(availability.bin.unattributed_kg)} kg counted in the bin cannot be traced to a loaded bag.`}
                                        description="A weighed count put the balance above everything ever scanned in here. Nothing is wrong with the count — it just has no lot behind it."
                                    />
                                )}
                                <Table
                                    scroll={{ x: 'max-content' }}
                                    size="small"
                                    rowKey="movement_id"
                                    columns={layerColumns}
                                    dataSource={availability.bin.layers}
                                    pagination={false}
                                    locale={{ emptyText: 'Nothing has been scanned into this bay yet.' }}
                                />
                                <Typography.Paragraph type="secondary" style={{ fontSize: 12, marginTop: 8 }}>
                                    Layers are in the order material physically went in. &ldquo;Still in bin&rdquo; is
                                    an estimate: the current balance spread over the layers oldest-out-first — the
                                    ledger never tracks which grain came from which bag.
                                </Typography.Paragraph>
                            </>
                        )}
                    </Card>

                    <Card title="Enough for the run?" style={{ marginBottom: 16 }}>
                        <Space wrap style={{ marginBottom: 12 }}>
                            <Select
                                value={productId ?? undefined}
                                onChange={(value) => setProductId(value ?? null)}
                                options={itemOptions}
                                placeholder="Product about to run"
                                showSearch
                                optionFilterProp="label"
                                allowClear
                                style={{ minWidth: 260 }}
                            />
                            <InputNumber
                                min={0}
                                value={expectedPieces}
                                onChange={(value) => setExpectedPieces(value)}
                                placeholder="Expected pieces"
                                style={{ width: 160 }}
                            />
                        </Space>
                        {availability?.requirement == null ? (
                            <Empty
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                                description="Name the product and how many pieces it should make to compare its recipe against this bay."
                            />
                        ) : availability.requirement.recipe_source === null ? (
                            <Empty
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                                description="This product has no active recipe, so there is nothing to compare against. Add a Bill of Material for it."
                            />
                        ) : (
                            <Table
                                scroll={{ x: 'max-content' }}
                                size="small"
                                rowKey="item_id"
                                columns={requirementColumns}
                                dataSource={availability.requirement.components}
                                pagination={false}
                            />
                        )}
                    </Card>

                    <Card title="Load history">
                        <Table
                            scroll={{ x: 'max-content' }}
                            size="small"
                            rowKey="id"
                            columns={historyColumns}
                            dataSource={history ?? []}
                            pagination={{ pageSize: 10, hideOnSinglePage: true }}
                            locale={{
                                emptyText:
                                    machineId === null
                                        ? 'Pick a machine to see who has been feeding it.'
                                        : 'Nothing has been loaded into this bay yet.',
                            }}
                        />
                    </Card>
                </Col>
            </Row>
        </>
    );
}
