import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { convertLead, createLead, listLeads } from '@/features/crm/api';
import type { Lead, LeadStatus } from '@/features/crm/types';

const leadSchema = z.object({
    name: z.string().min(1, 'Name is required').max(255),
    email: z.string().email('Enter a valid email').optional().or(z.literal('')),
    phone: z.string().optional(),
    company: z.string().optional(),
    source: z.string().optional(),
});
type LeadFormValues = z.infer<typeof leadSchema>;

const convertSchema = z.object({
    code: z.string().min(1, 'Customer code is required').max(32),
});
type ConvertFormValues = z.infer<typeof convertSchema>;

const statusColor: Record<LeadStatus, string> = {
    new: 'default',
    contacted: 'blue',
    qualified: 'gold',
    disqualified: 'red',
    converted: 'green',
};

export default function LeadsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [convertingLead, setConvertingLead] = useState<Lead | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['crm', 'leads'], queryFn: listLeads });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<LeadFormValues>({
        resolver: zodResolver(leadSchema),
        defaultValues: { name: '', email: '', phone: '', company: '', source: '' },
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['crm', 'leads'] });

    const createMutation = useMutation({
        mutationFn: createLead,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });

    const {
        control: convertControl,
        handleSubmit: handleConvertSubmit,
        reset: resetConvert,
        formState: { errors: convertErrors },
    } = useForm<ConvertFormValues>({ resolver: zodResolver(convertSchema), defaultValues: { code: '' } });

    const convertMutation = useMutation({
        mutationFn: ({ id, code }: { id: number; code: string }) => convertLead(id, code),
        onSuccess: () => {
            invalidate();
            queryClient.invalidateQueries({ queryKey: ['sales', 'customers'] });
            setConvertingLead(null);
            resetConvert();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not convert lead', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Leads</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Lead</Button>
            </Space>

            <Table<Lead>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Company', dataIndex: 'company' },
                    { title: 'Email', dataIndex: 'email' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: LeadStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) =>
                            row.status !== 'converted' && (
                                <Button size="small" onClick={() => setConvertingLead(row)}>Convert</Button>
                            ),
                    },
                ]}
            />

            <Modal
                title="New Lead"
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
                    <Form.Item label="Email" validateStatus={errors.email ? 'error' : ''} help={errors.email?.message}>
                        <Controller name="email" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Phone">
                        <Controller name="phone" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Company">
                        <Controller name="company" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Source">
                        <Controller name="source" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title={`Convert "${convertingLead?.name}" to a Customer`}
                open={convertingLead !== null}
                onCancel={() => setConvertingLead(null)}
                onOk={handleConvertSubmit((values) => {
                    if (convertingLead) convertMutation.mutate({ id: convertingLead.id, code: values.code });
                })}
                confirmLoading={convertMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Customer Code"
                        validateStatus={convertErrors.code ? 'error' : ''}
                        help={convertErrors.code?.message}
                    >
                        <Controller name="code" control={convertControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
