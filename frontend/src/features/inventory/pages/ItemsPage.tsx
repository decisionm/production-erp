import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { createItem, listItems, updateItem } from '@/features/inventory/api';
import type { Item, ItemTrackingType } from '@/features/inventory/types';

const itemSchema = z.object({
    sku: z.string().min(1, 'SKU is required').max(64),
    name: z.string().min(1, 'Name is required').max(255),
    uom: z.string().min(1, 'UOM is required').max(16),
    hsn_sac_code: z.string().max(20).optional(),
    reorder_level: z.number().min(0).optional(),
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
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    const { data, isLoading } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['inventory', 'items'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<ItemFormValues>({
        resolver: zodResolver(itemSchema),
        defaultValues: { sku: '', name: '', uom: 'PCS', hsn_sac_code: '', reorder_level: 0, tracking_type: 'none' },
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

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Items</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Item</Button>
            </Space>

            <Table<Item>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'SKU', dataIndex: 'sku' },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'UOM', dataIndex: 'uom' },
                    { title: 'HSN/SAC', dataIndex: 'hsn_sac_code' },
                    { title: 'Reorder Level', dataIndex: 'reorder_level' },
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
                    <Form.Item label="Name" validateStatus={editErrors.name ? 'error' : ''} help={editErrors.name?.message}>
                        <Controller name="name" control={editControl} render={({ field }) => <Input {...field} />} />
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
                    <Form.Item label="Tracking Type">
                        <Controller
                            name="tracking_type"
                            control={editControl}
                            render={({ field }) => <Select {...field} options={trackingTypeOptions} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
