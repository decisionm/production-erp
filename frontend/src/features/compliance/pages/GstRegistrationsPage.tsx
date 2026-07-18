import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Switch, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createGstRegistration, listGstRegistrations } from '@/features/compliance/api';
import type { GstRegistration } from '@/features/compliance/types';

const registrationSchema = z.object({
    gstin: z
        .string()
        .regex(/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/, 'Enter a valid 15-character GSTIN'),
    state_code: z.string().regex(/^[0-9]{2}$/, 'Enter a 2-digit GST state code'),
    state_name: z.string().min(1, 'State name is required').max(255),
    is_primary: z.boolean().optional(),
});
type RegistrationFormValues = z.infer<typeof registrationSchema>;

export default function GstRegistrationsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['compliance', 'gst-registrations'], queryFn: listGstRegistrations });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<RegistrationFormValues>({
        resolver: zodResolver(registrationSchema),
        defaultValues: { gstin: '', state_code: '', state_name: '', is_primary: false },
    });

    const mutation = useMutation({
        mutationFn: createGstRegistration,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['compliance', 'gst-registrations'] });
            setModalOpen(false);
            reset();
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>GST Registrations</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Registration</Button>
            </Space>

            <Table<GstRegistration>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'GSTIN', dataIndex: 'gstin' },
                    { title: 'State', dataIndex: 'state_name' },
                    { title: 'State Code', dataIndex: 'state_code' },
                    {
                        title: 'Primary',
                        dataIndex: 'is_primary',
                        render: (primary: boolean) => primary && <Tag color="blue">Primary</Tag>,
                    },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        render: (active: boolean) => <Switch checked={active} disabled size="small" />,
                    },
                ]}
            />

            <Modal
                title="New GST Registration"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="GSTIN" validateStatus={errors.gstin ? 'error' : ''} help={errors.gstin?.message}>
                        <Controller name="gstin" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="State Code"
                        validateStatus={errors.state_code ? 'error' : ''}
                        help={errors.state_code?.message}
                    >
                        <Controller name="state_code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="State Name"
                        validateStatus={errors.state_name ? 'error' : ''}
                        help={errors.state_name?.message}
                    >
                        <Controller name="state_name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Primary Registration">
                        <Controller
                            name="is_primary"
                            control={control}
                            render={({ field: { value, onChange } }) => (
                                <Switch checked={value} onChange={onChange} />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
