import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createAsset, listAssets, updateAsset } from '@/features/maintenance/api';
import type { Asset, AssetStatus } from '@/features/maintenance/types';

const assetSchema = z.object({
    code: z.string().min(1, 'Code is required').max(32),
    name: z.string().min(1, 'Name is required').max(255),
    category: z.string().optional(),
    location: z.string().optional(),
    purchase_date: z.string().optional(),
    purchase_cost: z.number().min(0).optional(),
});
type AssetFormValues = z.infer<typeof assetSchema>;

const statusColor: Record<AssetStatus, string> = {
    active: 'green',
    under_maintenance: 'orange',
    retired: 'default',
};

const statusOptions: { value: AssetStatus; label: string }[] = [
    { value: 'active', label: 'Active' },
    { value: 'under_maintenance', label: 'Under Maintenance' },
    { value: 'retired', label: 'Retired' },
];

export default function AssetsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['maintenance', 'assets'], queryFn: listAssets });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<AssetFormValues>({
        resolver: zodResolver(assetSchema),
        defaultValues: { code: '', name: '' },
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['maintenance', 'assets'] });

    const createMutation = useMutation({
        mutationFn: createAsset,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset({ code: '', name: '' });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create asset', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const statusMutation = useMutation({
        mutationFn: ({ id, status }: { id: number; status: AssetStatus }) => updateAsset(id, { status }),
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not update asset status', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Assets</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Asset</Button>
            </Space>

            <Table<Asset>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Category', dataIndex: 'category' },
                    { title: 'Location', dataIndex: 'location' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: AssetStatus, row) => (
                            <Select
                                value={status}
                                options={statusOptions}
                                size="small"
                                variant="borderless"
                                style={{ width: 160 }}
                                onChange={(value) => statusMutation.mutate({ id: row.id, status: value })}
                                labelRender={() => <Tag color={statusColor[status]}>{status}</Tag>}
                            />
                        ),
                    },
                ]}
            />

            <Modal
                title="New Asset"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={errors.code ? 'error' : ''} help={errors.code?.message}>
                        <Controller name="code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Category">
                        <Controller name="category" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Location">
                        <Controller name="location" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Purchase Date">
                        <Controller
                            name="purchase_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Purchase Cost">
                        <Controller
                            name="purchase_cost"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
