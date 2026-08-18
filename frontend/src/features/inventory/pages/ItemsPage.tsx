import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Drawer, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import BarcodeDisplay from '@/components/barcode/BarcodeDisplay';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
import { createItem, listAllItems, updateItem } from '@/features/inventory/api';
import type { Item, ItemTrackingType } from '@/features/inventory/types';

const itemSchema = z.object({
    sku: z.string().min(1, 'SKU is required').max(64),
    name: z.string().min(1, 'Name is required').max(255),
    uom: z.string().min(1, 'UOM is required').max(16),
    hsn_sac_code: z.string().max(20).optional(),
    reorder_level: z.number().min(0).optional(),
    nominal_weight_grams: z.number().gt(0).optional(),
    tracking_type: z.enum(['none', 'batch', 'serial']).optional(),
    is_production_input: z.boolean().optional(),
});

type ItemFormValues = z.infer<typeof itemSchema>;

const trackingTypeOptions: { value: ItemTrackingType; label: string }[] = [
    { value: 'none', label: 'None' },
    { value: 'batch', label: 'Batch / Lot' },
    { value: 'serial', label: 'Serial Number' },
];
const trackingTypeColor: Record<ItemTrackingType, string> = { none: 'default', batch: 'blue', serial: 'purple' };

export default function ItemsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<Item | null>(null);
    const [barcodeItem, setBarcodeItem] = useState<Item | null>(null);
    const [search, setSearch] = useState('');
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    // Fetch the full item list (not the default first 20) and paginate/search
    // client-side — with 600+ Tally-synced items the old page-1-only fetch hid
    // almost everything.
    const { data, isLoading } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['inventory', 'items'] });

    const q = search.trim().toLowerCase();
    const filteredItems = q
        ? (data?.data ?? []).filter(
              (i) => i.name.toLowerCase().includes(q) || i.sku.toLowerCase().includes(q),
          )
        : data?.data;

    const { control, handleSubmit, reset, formState: { errors } } = useForm<ItemFormValues>({
        resolver: zodResolver(itemSchema),
        defaultValues: {
            sku: '', name: '', uom: 'PCS', hsn_sac_code: '', reorder_level: 0, nominal_weight_grams: undefined, tracking_type: 'none', is_production_input: false,
        },
    });

    const mutation = useMutation({
        mutationFn: createItem,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<ItemFormValues>({ resolver: zodResolver(itemSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & ItemFormValues) => updateItem(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingItem(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update item', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    // The item name is the Tally wire key (every voucher line carries it, no
    // GUID does), so on a Tally-linked item it is Tally's to change — the API
    // refuses a rename; the masters pull brings a Tally-side one across.
    const nameIsTallys = editingItem?.tally_stock_item_guid != null;

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Items</Typography.Title>
                <Space>
                    <Input.Search
                        placeholder="Search by name or SKU"
                        allowClear
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        style={{ width: 260 }}
                    />
                    <Button type="primary" onClick={() => setModalOpen(true)}>New Item</Button>
                </Space>
            </Space>

            <Table<Item>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={filteredItems}
                pagination={{ pageSize: 20, showSizeChanger: true, pageSizeOptions: [20, 50, 100], showTotal: (t) => `${t} items` }}
                columns={[
                    { title: 'SKU', dataIndex: 'sku' },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'UOM', dataIndex: 'uom' },
                    { title: 'HSN/SAC', dataIndex: 'hsn_sac_code' },
                    { title: 'Reorder Level', dataIndex: 'reorder_level' },
                    { title: 'Nominal Wt (g)', dataIndex: 'nominal_weight_grams', render: (v: string | null) => v ?? '—' },
                    {
                        title: 'Tracking',
                        dataIndex: 'tracking_type',
                        render: (type: ItemTrackingType) => <Tag color={trackingTypeColor[type]}>{type}</Tag>,
                    },
                    {
                        // The state, in the product's two words. The Switch this
                        // replaces PUT `is_active` straight onto the item — a
                        // deactivate that ran no dependency report, took no
                        // reason and left no audit line, on the most-referenced
                        // master in the schema.
                        title: 'Status',
                        dataIndex: 'is_active',
                        render: (_: boolean, row) => <ConfigurationStatusTag entity="item" row={row} />,
                    },
                    {
                        // The owner's switch, made visible. The eligibility
                        // rule is backfilled from what the factory has
                        // configured and actually used, and anything it could
                        // not infer is left OFF rather than guessed at — so
                        // the residue has to be readable and correctable here,
                        // or "just switch it on" would not be true (Q56).
                        title: 'Requestable',
                        dataIndex: 'is_production_input',
                        render: (yes: boolean) => (yes
                            ? <Tag color="blue">Production material</Tag>
                            : <Tag>Not requestable</Tag>),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => {
                            const edit = () => {
                                setEditingItem(row);
                                resetEdit({
                                    sku: row.sku,
                                    name: row.name,
                                    uom: row.uom,
                                    hsn_sac_code: row.hsn_sac_code ?? '',
                                    reorder_level: Number(row.reorder_level),
                                    nominal_weight_grams: row.nominal_weight_grams ? Number(row.nominal_weight_grams) : undefined,
                                    tracking_type: row.tracking_type,
                                    is_production_input: row.is_production_input,
                                });
                            };
                            return (
                                <Space>
                                    <Button size="small" onClick={() => navigate(`/inventory/items/${row.id}`)}>
                                        Details
                                    </Button>
                                    <Button size="small" onClick={() => setBarcodeItem(row)}>
                                        Barcode
                                    </Button>
                                    <ConfigurationActionsCell
                                        entity="item"
                                        id={row.id}
                                        can={row.can}
                                        recordName={`${row.sku} — ${row.name}`}
                                        onEdit={edit}
                                    />
                                </Space>
                            );
                        },
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Item"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="SKU" validateStatus={errors.sku ? 'error' : ''} help={errors.sku?.message}>
                        <Controller name="sku" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="UOM" validateStatus={errors.uom ? 'error' : ''} help={errors.uom?.message}>
                        <Controller name="uom" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="HSN/SAC Code"
                        validateStatus={errors.hsn_sac_code ? 'error' : ''}
                        help={errors.hsn_sac_code?.message}
                    >
                        <Controller name="hsn_sac_code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Reorder Level">
                        <Controller
                            name="reorder_level"
                            control={control}
                            render={({ field }) => (
                                <InputNumber {...field} min={0} style={{ width: '100%' }} />
                            )}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Nominal Weight (g)"
                        tooltip="Weight of a single unit — used to compute Kg figures on the shop floor from a piece count (Production Report's WT column). Leave blank for raw materials."
                    >
                        <Controller
                            name="nominal_weight_grams"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Tracking Type">
                        <Controller
                            name="tracking_type"
                            control={control}
                            render={({ field }) => <Select {...field} options={trackingTypeOptions} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Requestable as a production material"
                        help="When on, the floor may ask the store to issue this item. Finished goods and saleable products stay off."
                    >
                        {/* On the CREATE form too, not only on edit: a new
                            material would otherwise always arrive
                            non-requestable and need a second trip through the
                            edit modal before the store could issue it. */}
                        <Controller
                            name="is_production_input"
                            control={control}
                            render={({ field }) => <Switch checked={field.value ?? false} onChange={field.onChange} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Edit "${editingItem?.name}"`}
                open={editingItem !== null}
                onCancel={() => setEditingItem(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingItem) editMutation.mutate({ id: editingItem.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="SKU" validateStatus={editErrors.sku ? 'error' : ''} help={editErrors.sku?.message}>
                        <Controller name="sku" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Name"
                        validateStatus={editErrors.name ? 'error' : ''}
                        help={editErrors.name?.message}
                        extra={nameIsTallys ? "Tally's name — rename in Tally, then pull masters." : undefined}
                    >
                        <Controller
                            name="name"
                            control={editControl}
                            render={({ field }) => <Input {...field} disabled={nameIsTallys} />}
                        />
                    </Form.Item>
                    <Form.Item label="UOM" validateStatus={editErrors.uom ? 'error' : ''} help={editErrors.uom?.message}>
                        <Controller name="uom" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="HSN/SAC Code"
                        validateStatus={editErrors.hsn_sac_code ? 'error' : ''}
                        help={editErrors.hsn_sac_code?.message}
                    >
                        <Controller name="hsn_sac_code" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Reorder Level">
                        <Controller
                            name="reorder_level"
                            control={editControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Nominal Weight (g)"
                        tooltip="Weight of a single unit — used to compute Kg figures on the shop floor from a piece count. Leave blank for raw materials."
                    >
                        <Controller
                            name="nominal_weight_grams"
                            control={editControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Tracking Type">
                        <Controller
                            name="tracking_type"
                            control={editControl}
                            render={({ field }) => <Select {...field} options={trackingTypeOptions} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Requestable as a production material"
                        help="When on, the floor may ask the store to issue this item. Finished goods and saleable products stay off."
                    >
                        <Controller
                            name="is_production_input"
                            control={editControl}
                            render={({ field }) => (
                                <Switch checked={field.value ?? false} onChange={field.onChange} />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Drawer
                title={`Barcode — ${barcodeItem?.sku}`}
                open={barcodeItem !== null}
                onClose={() => setBarcodeItem(null)}
                width="min(100vw, 420px)"
                destroyOnHidden
            >
                {barcodeItem && <BarcodeDisplay code={barcodeItem.sku} label={barcodeItem.name} />}
            </Drawer>
        </>
    );
}
