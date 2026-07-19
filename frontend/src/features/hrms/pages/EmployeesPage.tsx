import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { DatePicker, Button, Form, Input, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createEmployee, listEmployees } from '@/features/hrms/api';
import type { Employee, EmployeeStatus } from '@/features/hrms/types';

const employeeSchema = z.object({
    employee_code: z.string().min(1, 'Code is required').max(32),
    name: z.string().min(1, 'Name is required').max(255),
    email: z.string().email('Enter a valid email').optional().or(z.literal('')),
    phone: z.string().optional(),
    date_of_joining: z.string({ error: 'Date of joining is required' }),
    designation: z.string().optional(),
    department: z.string().optional(),
    manager_id: z.number().optional(),
});
type EmployeeFormValues = z.infer<typeof employeeSchema>;

const statusColor: Record<EmployeeStatus, string> = {
    active: 'green',
    inactive: 'default',
    terminated: 'red',
};

export default function EmployeesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['hrms', 'employees'], queryFn: listEmployees });
    const managerOptions = data?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<EmployeeFormValues>({
        resolver: zodResolver(employeeSchema),
        defaultValues: { employee_code: '', name: '', email: '', phone: '', designation: '', department: '' },
    });

    const mutation = useMutation({
        mutationFn: createEmployee,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['hrms', 'employees'] });
            setModalOpen(false);
            reset();
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Employees</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Employee</Button>
            </Space>

            <Table<Employee>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Code', dataIndex: 'employee_code' },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Designation', dataIndex: 'designation' },
                    { title: 'Department', dataIndex: 'department' },
                    { title: 'Manager', render: (_, row) => row.manager?.name },
                    { title: 'Joined', dataIndex: 'date_of_joining' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: EmployeeStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                ]}
            />

            <Modal
                title="New Employee"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Employee Code"
                        validateStatus={errors.employee_code ? 'error' : ''}
                        help={errors.employee_code?.message}
                    >
                        <Controller name="employee_code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Email" validateStatus={errors.email ? 'error' : ''} help={errors.email?.message}>
                        <Controller name="email" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Phone">
                        <Controller name="phone" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Date of Joining"
                        validateStatus={errors.date_of_joining ? 'error' : ''}
                        help={errors.date_of_joining?.message}
                    >
                        <Controller
                            name="date_of_joining"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Designation">
                        <Controller name="designation" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Department">
                        <Controller name="department" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Manager">
                        <Controller
                            name="manager_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={managerOptions} showSearch optionFilterProp="label" allowClear />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
