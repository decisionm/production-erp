import { zodResolver } from '@hookform/resolvers/zod';
import { MailOutlined, PhoneOutlined, PlusOutlined, TeamOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Descriptions, Drawer, Empty, Form, Input, Modal, Select, Space, Table, Tag, Timeline, Typography } from 'antd';
import dayjs from 'dayjs';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import {
    convertLead,
    createLead,
    createLeadActivity,
    listLeadActivities,
    listLeads,
    updateLeadNotes,
    updateLeadStatus,
} from '@/features/crm/api';
import type { Lead, LeadActivityType, LeadStatus } from '@/features/crm/types';

const leadSchema = z.object({
    name: z.string().min(1, 'Name is required').max(255),
    email: z.string().email('Enter a valid email').optional().or(z.literal('')),
    phone: z.string().optional(),
    company: z.string().optional(),
    source: z.string().optional(),
    notes: z.string().optional(),
});
type LeadFormValues = z.infer<typeof leadSchema>;

const convertSchema = z.object({
    code: z.string().min(1, 'Customer code is required').max(32),
});
type ConvertFormValues = z.infer<typeof convertSchema>;

const requirementSchema = z.object({
    notes: z.string().optional(),
});
type RequirementFormValues = z.infer<typeof requirementSchema>;

const activitySchema = z.object({
    type: z.enum(['call', 'email', 'meeting', 'note']),
    notes: z.string().min(1, 'Notes are required'),
    next_follow_up_date: z.string().optional(),
});
type ActivityFormValues = z.infer<typeof activitySchema>;

const statusColor: Record<LeadStatus, string> = {
    new: 'default',
    contacted: 'blue',
    qualified: 'gold',
    disqualified: 'red',
    converted: 'green',
};

const activityIcon: Record<LeadActivityType, ReactNode> = {
    call: <PhoneOutlined />,
    email: <MailOutlined />,
    meeting: <TeamOutlined />,
    note: <PlusOutlined />,
};

const activityColor: Record<LeadActivityType, string> = {
    call: 'blue',
    email: 'purple',
    meeting: 'gold',
    note: 'gray',
};

const activityTypeOptions: { value: LeadActivityType; label: string }[] = [
    { value: 'call', label: 'Call' },
    { value: 'email', label: 'Email' },
    { value: 'meeting', label: 'Meeting' },
    { value: 'note', label: 'Note' },
];

// The next forward status each status can move to via an explicit CTA.
// "Disqualified" and "Converted" are terminal; Convert is a separate action.
const nextStatusActions: Record<LeadStatus, { label: string; status: LeadStatus }[]> = {
    new: [
        { label: 'Mark Contacted', status: 'contacted' },
        { label: 'Disqualify', status: 'disqualified' },
    ],
    contacted: [
        { label: 'Mark Qualified', status: 'qualified' },
        { label: 'Disqualify', status: 'disqualified' },
    ],
    qualified: [{ label: 'Disqualify', status: 'disqualified' }],
    disqualified: [],
    converted: [],
};

export default function LeadsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [convertingLead, setConvertingLead] = useState<Lead | null>(null);
    const [detailLead, setDetailLead] = useState<Lead | null>(null);
    const [editingRequirement, setEditingRequirement] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['crm', 'leads'], queryFn: listLeads });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<LeadFormValues>({
        resolver: zodResolver(leadSchema),
        defaultValues: { name: '', email: '', phone: '', company: '', source: '', notes: '' },
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
            setDetailLead(null);
            resetConvert();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not convert lead', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const statusMutation = useMutation({
        mutationFn: ({ id, status }: { id: number; status: LeadStatus }) => updateLeadStatus(id, status),
        onSuccess: (updated) => {
            invalidate();
            setDetailLead(updated);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update lead status', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: requirementControl,
        handleSubmit: handleRequirementSubmit,
        reset: resetRequirement,
        formState: { errors: requirementErrors },
    } = useForm<RequirementFormValues>({ resolver: zodResolver(requirementSchema), defaultValues: { notes: '' } });

    const requirementMutation = useMutation({
        mutationFn: ({ id, notes }: { id: number; notes: string }) => updateLeadNotes(id, notes),
        onSuccess: (updated) => {
            invalidate();
            setDetailLead(updated);
            setEditingRequirement(false);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update requirement', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const { data: activities, isLoading: activitiesLoading } = useQuery({
        queryKey: ['crm', 'leads', detailLead?.id, 'activities'],
        queryFn: () => listLeadActivities(detailLead!.id),
        enabled: detailLead !== null,
    });

    const {
        control: activityControl,
        handleSubmit: handleActivitySubmit,
        reset: resetActivity,
        formState: { errors: activityErrors },
    } = useForm<ActivityFormValues>({
        resolver: zodResolver(activitySchema),
        defaultValues: { type: 'call', notes: '', next_follow_up_date: undefined },
    });

    const addActivityMutation = useMutation({
        mutationFn: (values: ActivityFormValues) => createLeadActivity(detailLead!.id, values),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['crm', 'leads', detailLead?.id, 'activities'] });
            invalidate();
            resetActivity({ type: 'call', notes: '', next_follow_up_date: undefined });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Leads</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Lead</Button>
            </Space>

            <Table<Lead>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                onRow={(row) => ({
                    onClick: () => {
                        setEditingRequirement(false);
                        setDetailLead(row);
                    },
                    style: { cursor: 'pointer' },
                })}
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
                        title: 'Last Contact',
                        render: (_, row) =>
                            row.latest_activity ? dayjs(row.latest_activity.activity_date).format('DD MMM YYYY') : '—',
                    },
                    {
                        title: 'Next Follow-up',
                        render: (_, row) => {
                            const due = row.latest_activity?.next_follow_up_date;
                            if (!due) return '—';
                            const overdue = dayjs(due).isBefore(dayjs(), 'day');
                            return <Typography.Text type={overdue ? 'danger' : undefined}>{due}</Typography.Text>;
                        },
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button
                                size="small"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    setEditingRequirement(false);
                                    setDetailLead(row);
                                }}
                            >
                                View
                            </Button>
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
                    <Form.Item label="Requirement / Enquiry">
                        <Controller
                            name="notes"
                            control={control}
                            render={({ field }) => (
                                <Input.TextArea
                                    {...field}
                                    rows={3}
                                    placeholder="What are they looking for? e.g. 50,000 units/month of 500ml PET bottles for a new juice launch"
                                />
                            )}
                        />
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

            <Drawer
                title={detailLead?.name}
                open={detailLead !== null}
                onClose={() => {
                    setDetailLead(null);
                    setEditingRequirement(false);
                }}
                width={480}
                destroyOnHidden
            >
                {detailLead && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Status">
                                <Tag color={statusColor[detailLead.status]}>{detailLead.status}</Tag>
                            </Descriptions.Item>
                            <Descriptions.Item label="Company">{detailLead.company ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Email">{detailLead.email ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Phone">{detailLead.phone ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Source">{detailLead.source ?? '—'}</Descriptions.Item>
                        </Descriptions>

                        <div style={{ marginTop: 16 }}>
                            <Space style={{ justifyContent: 'space-between', width: '100%' }}>
                                <Typography.Text strong>Requirement / Enquiry</Typography.Text>
                                {!editingRequirement && (
                                    <Button
                                        size="small"
                                        type="link"
                                        onClick={() => {
                                            resetRequirement({ notes: detailLead.notes ?? '' });
                                            setEditingRequirement(true);
                                        }}
                                    >
                                        Edit
                                    </Button>
                                )}
                            </Space>
                            {editingRequirement ? (
                                <Form
                                    layout="vertical"
                                    onFinish={handleRequirementSubmit((values) =>
                                        requirementMutation.mutate({ id: detailLead.id, notes: values.notes ?? '' })
                                    )}
                                >
                                    <Form.Item
                                        validateStatus={requirementErrors.notes ? 'error' : ''}
                                        help={requirementErrors.notes?.message}
                                        style={{ marginTop: 8 }}
                                    >
                                        <Controller
                                            name="notes"
                                            control={requirementControl}
                                            render={({ field }) => (
                                                <Input.TextArea {...field} rows={3} placeholder="What are they looking for?" />
                                            )}
                                        />
                                    </Form.Item>
                                    <Space>
                                        <Button type="primary" htmlType="submit" size="small" loading={requirementMutation.isPending}>
                                            Save
                                        </Button>
                                        <Button size="small" onClick={() => setEditingRequirement(false)}>
                                            Cancel
                                        </Button>
                                    </Space>
                                </Form>
                            ) : (
                                <Typography.Paragraph
                                    type={detailLead.notes ? undefined : 'secondary'}
                                    style={{ marginTop: 8, whiteSpace: 'pre-wrap' }}
                                >
                                    {detailLead.notes || 'No requirement captured yet.'}
                                </Typography.Paragraph>
                            )}
                        </div>

                        <Space wrap style={{ marginTop: 16 }}>
                            {nextStatusActions[detailLead.status].map((action) => (
                                <Button
                                    key={action.status}
                                    danger={action.status === 'disqualified'}
                                    loading={statusMutation.isPending}
                                    onClick={() => statusMutation.mutate({ id: detailLead.id, status: action.status })}
                                >
                                    {action.label}
                                </Button>
                            ))}
                            {detailLead.status !== 'converted' && (
                                <Button type="primary" onClick={() => setConvertingLead(detailLead)}>
                                    Convert to Customer
                                </Button>
                            )}
                        </Space>

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            Log a Follow-up
                        </Typography.Title>
                        <Form
                            layout="vertical"
                            onFinish={handleActivitySubmit((values) => addActivityMutation.mutate(values))}
                        >
                            <Space.Compact block>
                                <Controller
                                    name="type"
                                    control={activityControl}
                                    render={({ field }) => (
                                        <Select {...field} options={activityTypeOptions} style={{ width: 140 }} />
                                    )}
                                />
                                <Controller
                                    name="next_follow_up_date"
                                    control={activityControl}
                                    render={({ field }) => (
                                        <DatePicker
                                            placeholder="Next follow-up"
                                            style={{ flex: 1 }}
                                            onChange={(_, dateString) => field.onChange((dateString as string) || undefined)}
                                        />
                                    )}
                                />
                            </Space.Compact>
                            <Form.Item
                                validateStatus={activityErrors.notes ? 'error' : ''}
                                help={activityErrors.notes?.message}
                                style={{ marginTop: 12 }}
                            >
                                <Controller
                                    name="notes"
                                    control={activityControl}
                                    render={({ field }) => (
                                        <Input.TextArea {...field} rows={3} placeholder="What happened / what's next?" />
                                    )}
                                />
                            </Form.Item>
                            <Button type="primary" htmlType="submit" loading={addActivityMutation.isPending}>
                                Add Follow-up
                            </Button>
                        </Form>

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            History
                        </Typography.Title>
                        {activitiesLoading ? (
                            <Typography.Text type="secondary">Loading…</Typography.Text>
                        ) : activities && activities.length > 0 ? (
                            <Timeline
                                style={{ marginTop: 16 }}
                                items={activities.map((activity) => ({
                                    color: activityColor[activity.type],
                                    dot: activityIcon[activity.type],
                                    children: (
                                        <div key={activity.id}>
                                            <Space>
                                                <Tag color={activityColor[activity.type]}>{activity.type}</Tag>
                                                <Typography.Text type="secondary">
                                                    {dayjs(activity.activity_date).format('DD MMM YYYY, HH:mm')}
                                                </Typography.Text>
                                            </Space>
                                            <div>{activity.notes}</div>
                                            {activity.next_follow_up_date && (
                                                <Typography.Text type="warning">
                                                    Next follow-up: {activity.next_follow_up_date}
                                                </Typography.Text>
                                            )}
                                            {activity.created_by && (
                                                <div>
                                                    <Typography.Text type="secondary">— {activity.created_by}</Typography.Text>
                                                </div>
                                            )}
                                        </div>
                                    ),
                                }))}
                            />
                        ) : (
                            <Empty description="No follow-ups logged yet" />
                        )}
                    </>
                )}
            </Drawer>
        </>
    );
}
