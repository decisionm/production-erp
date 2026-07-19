import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Space, Switch, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createGstRate, listGstRates, updateGstRate } from '@/features/compliance/api';
import type { GstRate } from '@/features/compliance/types';

const rateSchema = z.object({
    hsn_sac_code: z.string().min(1, 'HSN/SAC code is required').max(20),
    description: z.string().optional(),
    rate_percent: z.number().min(0).max(100),
});
type RateFormValues = z.infer<typeof rateSchema>;

export default function GstRatesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingRate, setEditingRate] = useState<GstRate | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['compliance', 'gst-rates'], queryFn: listGstRates });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['compliance', 'gst-rates'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<RateFormValues>({
        resolver: zodResolver(rateSchema),
        defaultValues: { hsn_sac_code: '', description: '', rate_percent: 0 },
    });

    const mutation = useMutation({
        mutationFn: createGstRate,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<RateFormValues>({ resolver: zodResolver(rateSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & RateFormValues) => updateGstRate(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingRate(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update rate', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const activeMutation = useMutation({
        mutationFn: ({ id, is_active }: { id: number; is_active: boolean }) => updateGstRate(id, { is_active }),
        onSuccess: invalidate,
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>GST Rates</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Rate</Button>
            </Space>

            <Table<GstRate>
                scroll={{ x: 'max-content' }}
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
                        render: (active: boolean, row) => (
                            <Switch
                                checked={active}
                                size="small"
                                loading={activeMutation.isPending}
                                onChange={(checked) => activeMutation.mutate({ id: row.id, is_active: checked })}
                            />
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button
                                size="small"
                                onClick={() => {
                                    setEditingRate(row);
                                    resetEdit({
                                        hsn_sac_code: row.hsn_sac_code,
                                        description: row.description ?? '',
                                        rate_percent: Number(row.rate_percent),
                                    });
                                }}
                            >
                                Edit
                            </Button>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
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

            <Modal
                maskClosable={false}
                title={`Edit "${editingRate?.hsn_sac_code}"`}
                open={editingRate !== null}
                onCancel={() => setEditingRate(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingRate) editMutation.mutate({ id: editingRate.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="HSN/SAC Code"
                        validateStatus={editErrors.hsn_sac_code ? 'error' : ''}
                        help={editErrors.hsn_sac_code?.message}
                    >
                        <Controller name="hsn_sac_code" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Description">
                        <Controller name="description" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Rate %"
                        validateStatus={editErrors.rate_percent ? 'error' : ''}
                        help={editErrors.rate_percent?.message}
                    >
                        <Controller
                            name="rate_percent"
                            control={editControl}
                            render={({ field }) => <InputNumber {...field} min={0} max={100} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
