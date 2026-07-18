import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createOpportunity, listOpportunities, updateOpportunityStage } from '@/features/crm/api';
import type { Opportunity, OpportunityStage } from '@/features/crm/types';
import { listCustomers } from '@/features/sales/api';

const opportunitySchema = z.object({
    name: z.string().min(1, 'Name is required').max(255),
    customer_id: z.number({ error: 'Customer is required' }),
    estimated_value: z.number().min(0).optional(),
    probability: z.number().min(0).max(100).optional(),
    expected_close_date: z.string().optional(),
    notes: z.string().optional(),
});
type OpportunityFormValues = z.infer<typeof opportunitySchema>;

const stageOptions: { value: OpportunityStage; label: string }[] = [
    { value: 'prospecting', label: 'Prospecting' },
    { value: 'qualification', label: 'Qualification' },
    { value: 'proposal', label: 'Proposal' },
    { value: 'negotiation', label: 'Negotiation' },
    { value: 'won', label: 'Won' },
    { value: 'lost', label: 'Lost' },
];

export default function OpportunitiesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['crm', 'opportunities'], queryFn: listOpportunities });
    const { data: customers } = useQuery({ queryKey: ['sales', 'customers'], queryFn: listCustomers });
    const customerOptions = customers?.data.map((c) => ({ value: c.id, label: `${c.code} — ${c.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<OpportunityFormValues>({
        resolver: zodResolver(opportunitySchema),
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['crm', 'opportunities'] });

    const createMutation = useMutation({
        mutationFn: createOpportunity,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });

    const stageMutation = useMutation({
        mutationFn: ({ id, stage }: { id: number; stage: OpportunityStage }) => updateOpportunityStage(id, stage),
        onSuccess: invalidate,
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Opportunities</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Opportunity</Button>
            </Space>

            <Table<Opportunity>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Customer', render: (_, row) => row.customer.name },
                    { title: 'Estimated Value', dataIndex: 'estimated_value' },
                    { title: 'Probability %', dataIndex: 'probability' },
                    { title: 'Expected Close', dataIndex: 'expected_close_date' },
                    {
                        title: 'Stage',
                        dataIndex: 'stage',
                        render: (stage: OpportunityStage, row) => (
                            <Select
                                value={stage}
                                options={stageOptions}
                                style={{ width: 160 }}
                                onChange={(value) => stageMutation.mutate({ id: row.id, stage: value })}
                            />
                        ),
                    },
                ]}
            />

            <Modal
                title="New Opportunity"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Customer"
                        validateStatus={errors.customer_id ? 'error' : ''}
                        help={errors.customer_id?.message}
                    >
                        <Controller
                            name="customer_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={customerOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Estimated Value">
                        <Controller
                            name="estimated_value"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Probability %">
                        <Controller
                            name="probability"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} max={100} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Expected Close Date">
                        <Controller
                            name="expected_close_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Notes">
                        <Controller name="notes" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
