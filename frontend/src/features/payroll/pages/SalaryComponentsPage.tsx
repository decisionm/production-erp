import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createSalaryComponent, listSalaryComponents } from '@/features/payroll/api';
import type { SalaryCalculationType, SalaryComponent, SalaryComponentKind } from '@/features/payroll/types';

const componentSchema = z
    .object({
        code: z.string().min(1, 'Code is required').max(32),
        name: z.string().min(1, 'Name is required').max(255),
        type: z.enum(['earning', 'deduction'], { error: 'Type is required' }),
        calculation_type: z.enum(['fixed_amount', 'percentage_of_basic'], { error: 'Calculation type is required' }),
        percentage: z.number().min(0).max(100).optional(),
    })
    .refine((data) => data.calculation_type !== 'percentage_of_basic' || data.percentage !== undefined, {
        message: 'Percentage is required for a percentage-of-basic component',
        path: ['percentage'],
    });
type ComponentFormValues = z.infer<typeof componentSchema>;

const typeColor: Record<SalaryComponentKind, string> = { earning: 'green', deduction: 'red' };

const typeOptions: { value: SalaryComponentKind; label: string }[] = [
    { value: 'earning', label: 'Earning' },
    { value: 'deduction', label: 'Deduction' },
];
const calculationTypeOptions: { value: SalaryCalculationType; label: string }[] = [
    { value: 'fixed_amount', label: 'Fixed Amount' },
    { value: 'percentage_of_basic', label: 'Percentage of Basic' },
];

export default function SalaryComponentsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['payroll', 'salary-components'], queryFn: listSalaryComponents });

    const { control, handleSubmit, reset, watch, formState: { errors } } = useForm<ComponentFormValues>({
        resolver: zodResolver(componentSchema),
        defaultValues: { code: '', name: '', type: 'earning', calculation_type: 'fixed_amount' },
    });
    const calculationType = watch('calculation_type');

    const mutation = useMutation({
        mutationFn: createSalaryComponent,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['payroll', 'salary-components'] });
            setModalOpen(false);
            reset({ code: '', name: '', type: 'earning', calculation_type: 'fixed_amount' });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create salary component', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Salary Components</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Component</Button>
            </Space>

            <Table<SalaryComponent>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
                    {
                        title: 'Type',
                        dataIndex: 'type',
                        render: (type: SalaryComponentKind) => <Tag color={typeColor[type]}>{type}</Tag>,
                    },
                    {
                        title: 'Calculation',
                        render: (_, row) =>
                            row.calculation_type === 'percentage_of_basic'
                                ? `${row.percentage}% of Basic`
                                : 'Fixed Amount',
                    },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        render: (active: boolean) => <Switch checked={active} disabled size="small" />,
                    },
                ]}
            />

            <Modal
                title="New Salary Component"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={errors.code ? 'error' : ''} help={errors.code?.message}>
                        <Controller name="code" control={control} render={({ field }) => <Input {...field} placeholder="e.g. BASIC" />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Type" validateStatus={errors.type ? 'error' : ''} help={errors.type?.message}>
                        <Controller name="type" control={control} render={({ field }) => <Select {...field} options={typeOptions} />} />
                    </Form.Item>
                    <Form.Item
                        label="Calculation Type"
                        validateStatus={errors.calculation_type ? 'error' : ''}
                        help={errors.calculation_type?.message}
                    >
                        <Controller
                            name="calculation_type"
                            control={control}
                            render={({ field }) => <Select {...field} options={calculationTypeOptions} />}
                        />
                    </Form.Item>
                    {calculationType === 'percentage_of_basic' && (
                        <Form.Item
                            label="Percentage of Basic"
                            validateStatus={errors.percentage ? 'error' : ''}
                            help={errors.percentage?.message}
                        >
                            <Controller
                                name="percentage"
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} max={100} style={{ width: '100%' }} />}
                            />
                        </Form.Item>
                    )}
                </Form>
            </Modal>
        </>
    );
}
