import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
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

    // Server-side paging. The table showed the first twenty stores and said
    // nothing about the rest, which is the same defect the item picker had.
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(20);

    const { data, isLoading } = useQuery({
        queryKey: ['inventory', 'warehouses', page, perPage],
        queryFn: () => listWarehouses({ page, per_page: perPage }),
    });

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
                pagination={{
                    current: page,
                    pageSize: perPage,
                    // The server's count, not the page's length.
                    total: data?.meta?.total ?? data?.data?.length ?? 0,
                    showSizeChanger: true,
                    pageSizeOptions: [20, 50, 100, 200],
                    showTotal: (total, range) => `${range[0]}-${range[1]} of ${total} warehouses`,
                    onChange: (nextPage, nextSize) => {
                        setPage(nextPage);
                        setPerPage(nextSize);
                    },
                }}
                columns={[
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
                    {
                        // ONE status vocabulary, product-wide. The old control
                        // was a Switch that PUT `is_active` straight onto the
                        // record — a deactivate path around the mechanism, with
                        // no dependency report, no lock and no audit line. The
                        // state is now shown here and CHANGED through Archive /
                        // Reactivate below, which is the contract's own path.
                        title: 'Status',
                        dataIndex: 'is_active',
                        render: (_: boolean, row) => <ConfigurationStatusTag entity="warehouse" row={row} />,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => {
                            const edit = () => {
                                setEditingWarehouse(row);
                                resetEdit({ code: row.code, name: row.name });
                            };
                            return (
                                <ConfigurationActionsCell
                                    entity="warehouse"
                                    id={row.id}
                                    can={row.can}
                                    recordName={`${row.code} — ${row.name}`}
                                    onEdit={edit}
                                />
                            );
                        },
                    },
                ]}
            />

            <Modal
                maskClosable={false}
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
                maskClosable={false}
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
