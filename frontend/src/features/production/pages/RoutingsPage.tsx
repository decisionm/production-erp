import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Select, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listItems } from '@/features/inventory/api';
import { createRouting, listRoutings, listWorkCenters } from '@/features/production/api';
import type { Routing } from '@/features/production/types';

const operationSchema = z.object({
    work_center_id: z.number({ error: 'Work center is required' }),
    sequence: z.number().min(1, 'Sequence must be at least 1'),
    name: z.string().min(1, 'Name is required').max(255),
    standard_time_minutes: z.number().min(0).optional(),
});

const routingSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    name: z.string().min(1, 'Name is required').max(255),
    operations: z.array(operationSchema).min(1, 'At least one operation is required'),
});
type RoutingFormValues = z.infer<typeof routingSchema>;

export default function RoutingsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['production', 'routings'], queryFn: () => listRoutings() });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const { data: workCenters } = useQuery({ queryKey: ['production', 'work-centers'], queryFn: listWorkCenters });

    const itemOptions = items?.data.map((i) => ({ value: i.id, label: `${i.sku} — ${i.name}` })) ?? [];
    const workCenterOptions = workCenters?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<RoutingFormValues>({
        resolver: zodResolver(routingSchema),
        defaultValues: {
            name: '',
            operations: [{ work_center_id: undefined, sequence: 10, name: '', standard_time_minutes: undefined }],
        },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'operations' });

    const mutation = useMutation({
        mutationFn: createRouting,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'routings'] });
            setModalOpen(false);
            reset({ name: '', operations: [{ work_center_id: undefined, sequence: 10, name: '', standard_time_minutes: undefined }] });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create routing', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Routings</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Routing</Button>
            </Space>

            <Table<Routing>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Name', dataIndex: 'name' },
                    {
                        title: 'Operations',
                        render: (_, row) => row.operations.map((o) => `${o.sequence}. ${o.name} (${o.work_center.code})`).join(', '),
                    },
                ]}
            />

            <Modal
                title="New Routing"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
                width={800}
            >
                <Form layout="vertical">
                    <Form.Item label="Item" validateStatus={errors.item_id ? 'error' : ''} help={errors.item_id?.message}>
                        <Controller
                            name="item_id"
                            control={control}
                            render={({ field }) => <Select {...field} options={itemOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>

                    <Typography.Text strong>Operations</Typography.Text>
                    {fields.map((field, index) => (
                        <Space key={field.id} align="baseline" style={{ display: 'flex', marginTop: 8 }}>
                            <Controller
                                name={`operations.${index}.sequence`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={1} placeholder="Seq" style={{ width: 70 }} />}
                            />
                            <Controller
                                name={`operations.${index}.name`}
                                control={control}
                                render={({ field }) => <Input {...field} placeholder="Operation name" style={{ width: 180 }} />}
                            />
                            <Controller
                                name={`operations.${index}.work_center_id`}
                                control={control}
                                render={({ field }) => (
                                    <Select
                                        {...field}
                                        options={workCenterOptions}
                                        showSearch
                                        optionFilterProp="label"
                                        style={{ width: 220 }}
                                        placeholder="Work Center"
                                    />
                                )}
                            />
                            <Controller
                                name={`operations.${index}.standard_time_minutes`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Minutes" />}
                            />
                            <Button danger onClick={() => remove(index)} disabled={fields.length <= 1}>Remove</Button>
                        </Space>
                    ))}
                    <Button
                        type="dashed"
                        style={{ marginTop: 8 }}
                        onClick={() =>
                            append({
                                work_center_id: undefined as unknown as number,
                                sequence: (fields.length + 1) * 10,
                                name: '',
                                standard_time_minutes: undefined,
                            })
                        }
                    >
                        Add Operation
                    </Button>
                </Form>
            </Modal>
        </>
    );
}
