import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Switch, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createWarehouse, listWarehouses, updateWarehouse } from '@/features/inventory/api';
import type { Warehouse } from '@/features/inventory/types';

const warehouseSchema = z.object({
    code: z.string().min(1, 'Code is required').max(32),
    name: z.string().min(1, 'Name is required').max(255),
});

type WarehouseFormValues = z.infer<typeof warehouseSchema>;

export default function WarehousesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingWarehouse, setEditingWarehouse] = useState<Warehouse | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['inventory', 'warehouses'], queryFn: listWarehouses });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['inventory', 'warehouses'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<WarehouseFormValues>({
        resolver: zodResolver(warehouseSchema),
        defaultValues: { code: '', name: '' },
    });

    const mutation = useMutation({
        mutationFn: createWarehouse,
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
    } = useForm<WarehouseFormValues>({ resolver: zodResolver(warehouseSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & WarehouseFormValues) => updateWarehouse(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingWarehouse(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update warehouse', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const activeMutation = useMutation({
        mutationFn: ({ id, is_active }: { id: number; is_active: boolean }) => updateWarehouse(id, { is_active }),
        onSuccess: invalidate,
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Warehouses</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Warehouse</Button>
            </Space>

            <Table<Warehouse>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
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
                            <Button
                                size="small"
                                onClick={() => {
                                    setEditingWarehouse(row);
                                    resetEdit({ code: row.code, name: row.name });
                                }}
                            >
                                Edit
                            </Button>
                        ),
                    },
                ]}
            />

            <Modal
                title="New Warehouse"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={errors.code ? 'error' : ''} help={errors.code?.message}>
                        <Controller name="code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title={`Edit "${editingWarehouse?.name}"`}
                open={editingWarehouse !== null}
                onCancel={() => setEditingWarehouse(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingWarehouse) editMutation.mutate({ id: editingWarehouse.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={editErrors.code ? 'error' : ''} help={editErrors.code?.message}>
                        <Controller name="code" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={editErrors.name ? 'error' : ''} help={editErrors.name?.message}>
                        <Controller name="name" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
