import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Drawer, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import BarcodeDisplay from '@/components/barcode/BarcodeDisplay';
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
            sku: '', name: '', uom: 'PCS', hsn_sac_code: '', reorder_level: 0, nominal_weight_grams: undefined, tracking_type: 'none',
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

    const activeMutation = useMutation({
        mutationFn: ({ id, is_active }: { id: number; is_active: boolean }) => updateItem(id, { is_active }),
        onSuccess: invalidate,
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
                        title: 'Active',
                        dataIndex: 'is_active',
                        render: (active: boolean, row) => (
                            <Switch
                                checked={active}
                                size="small"
                                loading={activeMutation.isPending}
                                onChange={(checked) => activeMutation.mutate({ id: row.id, is_active: checked })}
                            />
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => navigate(`/inventory/items/${row.id}`)}>
                                    Details
                                </Button>
                                <Button size="small" onClick={() => setBarcodeItem(row)}>
                                    Barcode
                                </Button>
                                <Button
                                    size="small"
                                    onClick={() => {
                                        setEditingItem(row);
                                        resetEdit({
                                            sku: row.sku,
                                            name: row.name,
                                            uom: row.uom,
                                            hsn_sac_code: row.hsn_sac_code ?? '',
                                            reorder_level: Number(row.reorder_level),
                                            nominal_weight_grams: row.nominal_weight_grams ? Number(row.nominal_weight_grams) : undefined,
                                            tracking_type: row.tracking_type,
                                        });
                                    }}
                                >
                                    Edit
                                </Button>
                            </Space>
                        ),
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
