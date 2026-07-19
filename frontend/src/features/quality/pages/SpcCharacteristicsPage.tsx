import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Select, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { listItems } from '@/features/inventory/api';
import { createSpcCharacteristic, listSpcCharacteristics } from '@/features/quality/api';
import type { SpcCharacteristic } from '@/features/quality/types';

const characteristicSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    name: z.string().min(1, 'Name is required').max(255),
    unit_of_measure: z.string().optional(),
    target_value: z.number().optional(),
    lower_spec_limit: z.number().optional(),
    upper_spec_limit: z.number().optional(),
});
type CharacteristicFormValues = z.infer<typeof characteristicSchema>;

export default function SpcCharacteristicsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    const { data, isLoading } = useQuery({ queryKey: ['quality', 'spc-characteristics'], queryFn: () => listSpcCharacteristics() });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const itemOptions = items?.data.map((i) => ({ value: i.id, label: `${i.sku} — ${i.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<CharacteristicFormValues>({
        resolver: zodResolver(characteristicSchema),
    });

    const createMutation = useMutation({
        mutationFn: createSpcCharacteristic,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['quality', 'spc-characteristics'] });
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create characteristic', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>SPC Characteristics</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Characteristic</Button>
            </Space>

            <Table<SpcCharacteristic>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Characteristic', dataIndex: 'name' },
                    { title: 'Unit', dataIndex: 'unit_of_measure' },
                    { title: 'Target', dataIndex: 'target_value' },
                    { title: 'LSL', dataIndex: 'lower_spec_limit' },
                    { title: 'USL', dataIndex: 'upper_spec_limit' },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button size="small" onClick={() => navigate(`/quality/spc/${row.id}`)}>View Chart</Button>
                        ),
                    },
                ]}
            />

            <Modal
                title="New SPC Characteristic"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Item" validateStatus={errors.item_id ? 'error' : ''} help={errors.item_id?.message}>
                        <Controller
                            name="item_id"
                            control={control}
                            render={({ field }) => <Select {...field} options={itemOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Characteristic Name"
                        validateStatus={errors.name ? 'error' : ''}
                        help={errors.name?.message}
                    >
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} placeholder="e.g. Outer Diameter" />} />
                    </Form.Item>
                    <Form.Item label="Unit of Measure">
                        <Controller name="unit_of_measure" control={control} render={({ field }) => <Input {...field} placeholder="e.g. mm" />} />
                    </Form.Item>
                    <Form.Item label="Target Value">
                        <Controller
                            name="target_value"
                            control={control}
                            render={({ field }) => <InputNumber {...field} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Lower Spec Limit (LSL)">
                        <Controller
                            name="lower_spec_limit"
                            control={control}
                            render={({ field }) => <InputNumber {...field} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Upper Spec Limit (USL)">
                        <Controller
                            name="upper_spec_limit"
                            control={control}
                            render={({ field }) => <InputNumber {...field} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
