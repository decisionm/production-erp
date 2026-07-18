import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Space, Switch, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createItem, listItems } from '@/features/inventory/api';
import type { Item } from '@/features/inventory/types';

const itemSchema = z.object({
    sku: z.string().min(1, 'SKU is required').max(64),
    name: z.string().min(1, 'Name is required').max(255),
    uom: z.string().min(1, 'UOM is required').max(16),
    reorder_level: z.number().min(0).optional(),
});

type ItemFormValues = z.infer<typeof itemSchema>;

export default function ItemsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<ItemFormValues>({
        resolver: zodResolver(itemSchema),
        defaultValues: { sku: '', name: '', uom: 'PCS', reorder_level: 0 },
    });

    const mutation = useMutation({
        mutationFn: createItem,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['inventory', 'items'] });
            setModalOpen(false);
            reset();
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Items</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Item</Button>
            </Space>

            <Table<Item>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'SKU', dataIndex: 'sku' },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'UOM', dataIndex: 'uom' },
                    { title: 'Reorder Level', dataIndex: 'reorder_level' },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        render: (active: boolean) => <Switch checked={active} disabled size="small" />,
                    },
                ]}
            />

            <Modal
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
                    <Form.Item label="Reorder Level">
                        <Controller
                            name="reorder_level"
                            control={control}
                            render={({ field }) => (
                                <InputNumber {...field} min={0} style={{ width: '100%' }} />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
