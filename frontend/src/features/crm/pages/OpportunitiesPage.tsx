import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createOpportunity, listOpportunities, updateOpportunityStage } from '@/features/crm/api';
import type { Opportunity, OpportunityStage } from '@/features/crm/types';
import { listCustomers } from '@/features/sales/api';

const stageColor: Record<OpportunityStage, string> = {
    prospecting: 'default',
    qualification: 'blue',
    proposal: 'gold',
    negotiation: 'orange',
    won: 'green',
    lost: 'red',
};

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
    const [detailOpportunity, setDetailOpportunity] = useState<Opportunity | null>(null);
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
                scroll={{ x: 'max-content' }}
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
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button size="small" onClick={() => setDetailOpportunity(row)}>
                                View
                            </Button>
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

            <Drawer
                title={detailOpportunity?.name}
                open={detailOpportunity !== null}
                onClose={() => setDetailOpportunity(null)}
                width={440}
                destroyOnHidden
            >
                {detailOpportunity && (
                    <Descriptions column={1} size="small" bordered>
                        <Descriptions.Item label="Stage">
                            <Tag color={stageColor[detailOpportunity.stage]}>{detailOpportunity.stage}</Tag>
                        </Descriptions.Item>
                        <Descriptions.Item label="Customer">{detailOpportunity.customer.name}</Descriptions.Item>
                        <Descriptions.Item label="Estimated Value">
                            {detailOpportunity.estimated_value ?? '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="Probability">
                            {detailOpportunity.probability ? `${detailOpportunity.probability}%` : '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="Expected Close">
                            {detailOpportunity.expected_close_date ?? '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="Source Lead">
                            {detailOpportunity.lead_id ? `#${detailOpportunity.lead_id}` : '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="Assigned To">{detailOpportunity.assigned_to ?? '—'}</Descriptions.Item>
                        <Descriptions.Item label="Notes">{detailOpportunity.notes ?? '—'}</Descriptions.Item>
                        <Descriptions.Item label="Created">
                            {new Date(detailOpportunity.created_at).toLocaleString()}
                        </Descriptions.Item>
                    </Descriptions>
                )}
            </Drawer>
        </>
    );
}
