import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listItems } from '@/features/inventory/api';
import { createBom, listBoms } from '@/features/production/api';
import type { Bom } from '@/features/production/types';

const lineSchema = z.object({
    component_item_id: z.number({ error: 'Component is required' }),
    quantity_per: z.number().gt(0, 'Quantity must be greater than 0'),
});

const bomSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    name: z.string().min(1, 'Name is required').max(255),
    version: z.string().optional(),
    lines: z.array(lineSchema).min(1, 'At least one component is required'),
});
type BomFormValues = z.infer<typeof bomSchema>;

export default function BomsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['production', 'boms'], queryFn: () => listBoms() });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const itemOptions = items?.data.map((i) => ({ value: i.id, label: `${i.sku} — ${i.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<BomFormValues>({
        resolver: zodResolver(bomSchema),
        defaultValues: { name: '', lines: [{ component_item_id: undefined, quantity_per: undefined }] },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'lines' });

    const mutation = useMutation({
        mutationFn: createBom,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'boms'] });
            setModalOpen(false);
            reset({ name: '', lines: [{ component_item_id: undefined, quantity_per: undefined }] });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create BOM', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Bills of Material</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New BOM</Button>
            </Space>

            <Table<Bom>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Version', dataIndex: 'version' },
                    { title: 'Components', render: (_, row) => row.lines.map((l) => l.component.sku).join(', ') },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        render: (active: boolean) => <Tag color={active ? 'green' : 'default'}>{active ? 'active' : 'inactive'}</Tag>,
                    },
                ]}
            />

            <Modal
                title="New BOM"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
                width={700}
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
                    <Form.Item label="Version (optional, defaults to 1)">
                        <Controller name="version" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>

                    <Typography.Text strong>Components</Typography.Text>
                    {fields.map((field, index) => (
                        <Space key={field.id} align="baseline" style={{ display: 'flex', marginTop: 8 }}>
                            <Controller
                                name={`lines.${index}.component_item_id`}
                                control={control}
                                render={({ field }) => (
                                    <Select
                                        {...field}
                                        options={itemOptions}
                                        showSearch
                                        optionFilterProp="label"
                                        style={{ width: 280 }}
                                        placeholder="Component"
                                    />
                                )}
                            />
                            <Controller
                                name={`lines.${index}.quantity_per`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Qty per unit" />}
                            />
                            <Button danger onClick={() => remove(index)} disabled={fields.length <= 1}>Remove</Button>
                        </Space>
                    ))}
                    <Button
                        type="dashed"
                        style={{ marginTop: 8 }}
                        onClick={() => append({ component_item_id: undefined as unknown as number, quantity_per: undefined as unknown as number })}
                    >
                        Add Component
                    </Button>
                </Form>
            </Modal>
        </>
    );
}
