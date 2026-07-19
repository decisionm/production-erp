import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, Input, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listAttendance, listEmployees, markAttendance } from '@/features/hrms/api';
import type { Attendance, AttendanceStatus } from '@/features/hrms/types';

const attendanceSchema = z.object({
    employee_id: z.number({ error: 'Employee is required' }),
    date: z.string({ error: 'Date is required' }),
    status: z.enum(['present', 'absent', 'half_day', 'on_leave'], { error: 'Status is required' }),
    notes: z.string().optional(),
});
type AttendanceFormValues = z.infer<typeof attendanceSchema>;

const statusColor: Record<AttendanceStatus, string> = {
    present: 'green',
    absent: 'red',
    half_day: 'orange',
    on_leave: 'blue',
};

const statusOptions: { value: AttendanceStatus; label: string }[] = [
    { value: 'present', label: 'Present' },
    { value: 'absent', label: 'Absent' },
    { value: 'half_day', label: 'Half Day' },
    { value: 'on_leave', label: 'On Leave' },
];

export default function AttendancePage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['hrms', 'attendance'], queryFn: listAttendance });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees'], queryFn: listEmployees });
    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<AttendanceFormValues>({
        resolver: zodResolver(attendanceSchema),
        defaultValues: { status: 'present', notes: '' },
    });

    const mutation = useMutation({
        mutationFn: markAttendance,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['hrms', 'attendance'] });
            setModalOpen(false);
            reset({ status: 'present', notes: '' });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not mark attendance', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Attendance</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>Mark Attendance</Button>
            </Space>

            <Table<Attendance>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Employee', render: (_, row) => row.employee?.name },
                    { title: 'Date', dataIndex: 'date' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: AttendanceStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Notes', dataIndex: 'notes' },
                ]}
            />

            <Modal
                title="Mark Attendance"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Employee"
                        validateStatus={errors.employee_id ? 'error' : ''}
                        help={errors.employee_id?.message}
                    >
                        <Controller
                            name="employee_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={employeeOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Date" validateStatus={errors.date ? 'error' : ''} help={errors.date?.message}>
                        <Controller
                            name="date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Status" validateStatus={errors.status ? 'error' : ''} help={errors.status?.message}>
                        <Controller
                            name="status"
                            control={control}
                            render={({ field }) => <Select {...field} options={statusOptions} />}
                        />
                    </Form.Item>
                    <Form.Item label="Notes">
                        <Controller name="notes" control={control} render={({ field }) => <Input.TextArea {...field} rows={2} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
