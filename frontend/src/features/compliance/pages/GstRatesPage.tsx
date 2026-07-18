import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Space, Switch, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createGstRate, listGstRates } from '@/features/compliance/api';
import type { GstRate } from '@/features/compliance/types';

const rateSchema = z.object({
    hsn_sac_code: z.string().min(1, 'HSN/SAC code is required').max(20),
    description: z.string().optional(),
    rate_percent: z.number().min(0).max(100),
});
type RateFormValues = z.infer<typeof rateSchema>;

export default function GstRatesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['compliance', 'gst-rates'], queryFn: listGstRates });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<RateFormValues>({
        resolver: zodResolver(rateSchema),
        defaultValues: { hsn_sac_code: '', description: '', rate_percent: 0 },
    });

    const mutation = useMutation({
        mutationFn: createGstRate,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['compliance', 'gst-rates'] });
            setModalOpen(false);
            reset();
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>GST Rates</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Rate</Button>
            </Space>

            <Table<GstRate>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'HSN/SAC Code', dataIndex: 'hsn_sac_code' },
                    { title: 'Description', dataIndex: 'description' },
                    { title: 'Rate %', dataIndex: 'rate_percent' },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        render: (active: boolean) => <Switch checked={active} disabled size="small" />,
                    },
                ]}
            />

            <Modal
                title="New GST Rate"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="HSN/SAC Code"
                        validateStatus={errors.hsn_sac_code ? 'error' : ''}
                        help={errors.hsn_sac_code?.message}
                    >
                        <Controller name="hsn_sac_code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Description">
                        <Controller name="description" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Rate %"
                        validateStatus={errors.rate_percent ? 'error' : ''}
                        help={errors.rate_percent?.message}
                    >
                        <Controller
                            name="rate_percent"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} max={100} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
