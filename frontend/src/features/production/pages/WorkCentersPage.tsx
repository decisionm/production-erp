import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Switch, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createWorkCenter, listWorkCenters } from '@/features/production/api';
import type { WorkCenter } from '@/features/production/types';

const workCenterSchema = z.object({
    code: z.string().min(1, 'Code is required').max(32),
    name: z.string().min(1, 'Name is required').max(255),
});
type WorkCenterFormValues = z.infer<typeof workCenterSchema>;

export default function WorkCentersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['production', 'work-centers'], queryFn: listWorkCenters });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<WorkCenterFormValues>({
        resolver: zodResolver(workCenterSchema),
        defaultValues: { code: '', name: '' },
    });

    const mutation = useMutation({
        mutationFn: createWorkCenter,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'work-centers'] });
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create work center', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Work Centers</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Work Center</Button>
            </Space>

            <Table<WorkCenter>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        render: (active: boolean) => <Switch checked={active} disabled size="small" />,
                    },
                ]}
            />

            <Modal
                title="New Work Center"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={errors.code ? 'error' : ''} help={errors.code?.message}>
                        <Controller name="code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
