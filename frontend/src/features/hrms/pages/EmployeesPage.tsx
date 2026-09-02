import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Empty, Form, Input, Modal, Select, Space, Table, Typography } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
import { createEmployee, listAllEmployees, listEmployees, updateEmployee } from '@/features/hrms/api';
import { EMPLOYEE_LIST_SPEC, noMatchLine, pageRangeLine } from '@/features/hrms/list';
import type { Employee, EmployeeListParams, EmployeeStatus } from '@/features/hrms/types';
import { ListEmpty, ListReadAlert } from '@/lib/ListEmpty';
import { compactParams, narrowingKeys } from '@/lib/listParams';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { useListParams } from '@/lib/useListParams';

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

const editEmployeeSchema = employeeSchema.extend({
    status: z.enum(['active', 'inactive', 'terminated']).optional(),
});
type EditEmployeeFormValues = z.infer<typeof editEmployeeSchema>;

const statusOptions: { value: EmployeeStatus; label: string }[] = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'terminated', label: 'Terminated' },
];

const STATUS_FILTER: { value: EmployeeStatus | ''; label: string }[] = [{ value: '', label: 'All statuses' }, ...statusOptions];

/**
 * THE EMPLOYEE MASTER'S LIST. Search, status, page and page size live in
 * the URL (useListParams) and the SERVER does the narrowing
 * (ListEmployeesRequest): `q` finds an employee by code, name, department
 * or designation across the whole master, never just the rows on screen.
 * The pager is wired to the server's meta — the 21st employee was
 * unreachable from this page until it was.
 */
export default function EmployeesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingEmployee, setEditingEmployee] = useState<Employee | null>(null);
    const queryClient = useQueryClient();

    const { params, setParams, setPage, reset } = useListParams<EmployeeListParams>(EMPLOYEE_LIST_SPEC);
    const listParams = useMemo(() => compactParams(params), [params]);
    const narrowed = narrowingKeys(params).length > 0;

    // The box's text as typed; it becomes `q` on Enter / the search button,
    // never per keystroke. Re-seeded when the URL's q changes under it.
    const [qDraft, setQDraft] = useState(params.q ?? '');
    useEffect(() => setQDraft(params.q ?? ''), [params.q]);

    const query = useQuery({
        // The params are part of the key, and the key still STARTS with the
        // prefix the invalidate uses, so a create or edit refreshes
        // whichever view is on screen.
        queryKey: ['hrms', 'employees', 'list', listParams],
        queryFn: () => listEmployees(listParams),
        placeholderData: (previous) => previous,
    });
    // The manager picker needs EVERY employee, not the page on screen — a
    // searched or paged list would otherwise offer only the rows that
    // happened to match.
    const { data: allEmployees } = useQuery({ queryKey: ['hrms', 'employees', 'all'], queryFn: listAllEmployees });
    const managerOptions = allEmployees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['hrms', 'employees'] });

    const { control, handleSubmit, reset: resetForm, formState: { errors } } = useForm<EmployeeFormValues>({
        resolver: zodResolver(employeeSchema),
        defaultValues: { employee_code: '', name: '', email: '', phone: '', designation: '', department: '' },
    });

    const mutation = useMutation({
        mutationFn: createEmployee,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            resetForm();
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<EditEmployeeFormValues>({ resolver: zodResolver(editEmployeeSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & EditEmployeeFormValues) => updateEmployee(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingEmployee(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update employee', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    // Three different empty tables: a term that matched nothing names the
    // term; a status that holds nothing offers the filter back; only the
    // bare page may say there are no employees at all.
    const emptyText = params.q ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={noMatchLine('employees', params.q)}>
            <Button size="small" onClick={() => setParams({ q: undefined })}>
                Clear search
            </Button>
        </Empty>
    ) : narrowed ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No employees match these filters.">
            <Button size="small" onClick={reset}>
                Clear filters
            </Button>
        </Empty>
    ) : (
        'No employees yet.'
    );

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }} wrap>
                <Typography.Title level={3} style={{ margin: 0 }}>Employees</Typography.Title>
                <Space wrap>
                    <Input.Search
                        allowClear
                        placeholder="Code, name, department, designation"
                        style={{ width: 280 }}
                        value={qDraft}
                        onChange={(event) => setQDraft(event.target.value)}
                        onSearch={(value) => setParams({ q: value.trim() || undefined })}
                    />
                    <Select<EmployeeStatus | ''>
                        value={params.status ?? ''}
                        style={{ width: 160 }}
                        options={STATUS_FILTER}
                        onChange={(value) => setParams({ status: value || undefined })}
                    />
                    <Button type="primary" onClick={() => setModalOpen(true)}>New Employee</Button>
                </Space>
            </Space>

            <Space style={{ marginBottom: 8 }} wrap>
                <Typography.Text type="secondary">{pageRangeLine(query.data?.meta, 'employees')}</Typography.Text>
                {narrowed ? (
                    <Button size="small" onClick={reset}>
                        Clear
                    </Button>
                ) : null}
            </Space>

            {/* placeholderData keeps stale rows on a failed refetch, so
                emptyText never shows the failure — this line does. */}
            <ListReadAlert state={query} entity="employees" />

            <Table<Employee>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={query.isFetching}
                dataSource={query.data?.data}
                pagination={serverPagination(query.data?.meta, setPage, 'employees')}
                locale={{ emptyText: <ListEmpty state={query} entity="employees" empty={emptyText} /> }}
                columns={[
                    { title: 'Code', dataIndex: 'employee_code' },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Designation', dataIndex: 'designation' },
                    { title: 'Department', dataIndex: 'department' },
                    { title: 'Manager', render: (_, row) => row.manager?.name },
                    { title: 'Joined', dataIndex: 'date_of_joining' },
                    {
                        // The same two words as every other master, plus the
                        // factory's own word for the case that is neither:
                        // `terminated` is an HR and payroll fact, and folding
                        // it into "Retired" would lose what payroll reads.
                        title: 'Status',
                        dataIndex: 'status',
                        render: (_: EmployeeStatus, row) => <ConfigurationStatusTag entity="employee" row={row} />,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => {
                            const edit = () => {
                                setEditingEmployee(row);
                                resetEdit({
                                    employee_code: row.employee_code,
                                    name: row.name,
                                    email: row.email ?? '',
                                    phone: row.phone ?? '',
                                    date_of_joining: row.date_of_joining,
                                    designation: row.designation ?? '',
                                    department: row.department ?? '',
                                    manager_id: row.manager?.id,
                                    status: row.status,
                                });
                            };
                            return (
                                <ConfigurationActionsCell
                                    entity="employee"
                                    id={row.id}
                                    can={row.can}
                                    recordName={`${row.employee_code} — ${row.name}`}
                                    onEdit={edit}
                                />
                            );
                        },
                    },
                ]}
            />

            <Modal
                maskClosable={false}
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

            <Modal
                maskClosable={false}
                title={`Edit "${editingEmployee?.name}"`}
                open={editingEmployee !== null}
                onCancel={() => setEditingEmployee(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingEmployee) editMutation.mutate({ id: editingEmployee.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Employee Code"
                        validateStatus={editErrors.employee_code ? 'error' : ''}
                        help={editErrors.employee_code?.message}
                    >
                        <Controller name="employee_code" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={editErrors.name ? 'error' : ''} help={editErrors.name?.message}>
                        <Controller name="name" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Email" validateStatus={editErrors.email ? 'error' : ''} help={editErrors.email?.message}>
                        <Controller name="email" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Phone">
                        <Controller name="phone" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Date of Joining"
                        validateStatus={editErrors.date_of_joining ? 'error' : ''}
                        help={editErrors.date_of_joining?.message}
                    >
                        <Controller
                            name="date_of_joining"
                            control={editControl}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    value={field.value ? dayjs(field.value) : undefined}
                                    onChange={(_, dateString) => field.onChange((dateString as string) || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Designation">
                        <Controller name="designation" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Department">
                        <Controller name="department" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Manager">
                        <Controller
                            name="manager_id"
                            control={editControl}
                            render={({ field }) => (
                                <Select {...field} options={managerOptions} showSearch optionFilterProp="label" allowClear />
                            )}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Status"
                        extra="Taking an employee out of service is done with Archive on the row — that path checks the attendance, leave and payroll history first, takes a reason, and can be undone. Set a status here only to correct one."
                    >
                        <Controller
                            name="status"
                            control={editControl}
                            render={({ field }) => <Select {...field} options={statusOptions} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
