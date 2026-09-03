import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Descriptions, Drawer, Form, InputNumber, Modal, Select, Space, Table, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { activePickerOptions } from '@/components/configuration/pickerOptions';
import { listAllEmployees } from '@/features/hrms/api';
import { createSalaryStructure, listAllSalaryComponents, listSalaryStructures } from '@/features/payroll/api';
import {
    STRUCTURES_DEFAULT_SORT,
    STRUCTURES_LIST_SPEC,
    STRUCTURES_SORT_FIELDS,
    structuresQueryKey,
    structuresServerFilters,
} from '@/features/payroll/lists';
import type { SalaryStructure, SalaryStructureListFilters } from '@/features/payroll/types';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const lineSchema = z.object({
    salary_component_id: z.number({ error: 'Component is required' }),
    amount: z.number().min(0).optional(),
});

const structureSchema = z.object({
    employee_id: z.number({ error: 'Employee is required' }),
    effective_from: z.string({ error: 'Effective date is required' }),
    lines: z.array(lineSchema).min(1, 'At least one line is required'),
});
type StructureFormValues = z.infer<typeof structureSchema>;

/**
 * THE SALARY STRUCTURE LIST. Sort, page and page size live in the URL
 * (useListParams) — as does the `employee_id` filter this index has always
 * taken — and the SERVER orders and pages (ListSalaryStructuresRequest);
 * the pager is wired to the server's meta — this table drew the server's
 * first 20 with the pager off, so a 21st structure existed and nothing on
 * screen said so.
 */
export default function SalaryStructuresPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailStructure, setDetailStructure] = useState<SalaryStructure | null>(null);
    const queryClient = useQueryClient();

    const { params, setParams, setPage } = useListParams<SalaryStructureListFilters>(STRUCTURES_LIST_SPEC);
    const filters = useMemo(() => structuresServerFilters(params), [params]);

    const { data, isFetching } = useQuery({
        // Still under the ['payroll', 'salary-structures'] prefix the create invalidates.
        queryKey: structuresQueryKey(filters),
        queryFn: () => listSalaryStructures(filters),
        placeholderData: (previous) => previous,
    });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees', 'all'], queryFn: listAllEmployees });
    const { data: components } = useQuery({ queryKey: ['payroll', 'salary-components', 'all'], queryFn: listAllSalaryComponents });

    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];
    // WS-B: a WITHDRAWN component joins no NEW structure. Structures already
    // carrying it keep it — payroll history is never rewritten.
    const componentOptions = activePickerOptions(components?.data, {
        isActive: (c) => c.is_active,
        option: (c) => ({ value: c.id, label: `${c.code} — ${c.name}` }),
    });
    const componentsById = new Map(components?.data.map((c) => [c.id, c]));

    const { control, handleSubmit, reset, watch, formState: { errors } } = useForm<StructureFormValues>({
        resolver: zodResolver(structureSchema),
        defaultValues: { lines: [{ salary_component_id: undefined, amount: undefined }] },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'lines' });
    const watchedLines = watch('lines');

    const mutation = useMutation({
        mutationFn: createSalaryStructure,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['payroll', 'salary-structures'] });
            setModalOpen(false);
            reset({ lines: [{ salary_component_id: undefined, amount: undefined }] });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create salary structure', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Salary Structures</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Structure</Button>
            </Space>

            <Table<SalaryStructure>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isFetching}
                dataSource={data?.data}
                // SORTED BY THE SERVER: sortOrder-controlled, re-queried. The
                // drawer's component lines keep the structure's own order.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, STRUCTURES_SORT_FIELDS, STRUCTURES_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'structures')}
                columns={[
                    // A name through the relation, not a column of this table:
                    // no server sort. Components and Gross are composed from
                    // the lines, so neither is a column either.
                    { title: 'Employee', render: (_, row) => row.employee?.name },
                    {
                        title: 'Effective From',
                        dataIndex: 'effective_from',
                        key: 'effective_from',
                        sorter: true,
                        sortOrder: columnSortOrder('effective_from', params.sort, STRUCTURES_DEFAULT_SORT),
                    },
                    {
                        title: 'Components',
                        render: (_, row) => row.lines.map((l) => l.component.code).join(', '),
                    },
                    {
                        title: 'Gross (fixed + resolved)',
                        render: (_, row) => row.lines.reduce((sum, l) => sum + Number(l.amount), 0).toFixed(2),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button size="small" onClick={() => setDetailStructure(row)}>View</Button>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Salary Structure"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) =>
                    mutation.mutate({
                        employee_id: values.employee_id,
                        effective_from: values.effective_from,
                        lines: values.lines.map((l) => ({ salary_component_id: l.salary_component_id, amount: l.amount })),
                    }),
                )}
                confirmLoading={mutation.isPending}
                destroyOnHidden
                width={700}
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
                    <Form.Item
                        label="Effective From"
                        validateStatus={errors.effective_from ? 'error' : ''}
                        help={errors.effective_from?.message}
                    >
                        <Controller
                            name="effective_from"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>

                    <Typography.Text strong>Components</Typography.Text>
                    {fields.map((field, index) => {
                        const selectedComponentId = watchedLines?.[index]?.salary_component_id;
                        const selectedComponent = selectedComponentId ? componentsById.get(selectedComponentId) : undefined;
                        const isPercentage = selectedComponent?.calculation_type === 'percentage_of_basic';

                        return (
                            <Space key={field.id} align="baseline" style={{ display: 'flex', marginTop: 8 }}>
                                <Controller
                                    name={`lines.${index}.salary_component_id`}
                                    control={control}
                                    render={({ field }) => (
                                        <Select
                                            {...field}
                                            options={componentOptions}
                                            showSearch
                                            optionFilterProp="label"
                                            style={{ width: 260 }}
                                            placeholder="Component"
                                        />
                                    )}
                                />
                                {isPercentage ? (
                                    <Typography.Text type="secondary">
                                        {selectedComponent?.percentage}% of Basic (auto-calculated)
                                    </Typography.Text>
                                ) : (
                                    <Controller
                                        name={`lines.${index}.amount`}
                                        control={control}
                                        render={({ field }) => <InputNumber {...field} min={0} placeholder="Amount" />}
                                    />
                                )}
                                <Button danger onClick={() => remove(index)} disabled={fields.length <= 1}>Remove</Button>
                            </Space>
                        );
                    })}
                    <Button
                        type="dashed"
                        style={{ marginTop: 8 }}
                        onClick={() => append({ salary_component_id: undefined as unknown as number, amount: undefined })}
                    >
                        Add Component
                    </Button>
                </Form>
            </Modal>

            <Drawer
                title={`Salary Structure — ${detailStructure?.employee?.name}`}
                open={detailStructure !== null}
                onClose={() => setDetailStructure(null)}
                width="min(100vw, 520px)"
                destroyOnHidden
            >
                {detailStructure && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Employee">{detailStructure.employee?.name}</Descriptions.Item>
                            <Descriptions.Item label="Effective From">{detailStructure.effective_from}</Descriptions.Item>
                        </Descriptions>

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            Components
                        </Typography.Title>
                        <Table
                            rowKey="id"
                            size="small"
                            pagination={false}
                            dataSource={detailStructure.lines}
                            scroll={{ x: 'max-content' }}
                            columns={[
                                {
                                    title: 'Component',
                                    render: (_, line) => `${line.component.code} — ${line.component.name}`,
                                },
                                {
                                    title: 'Calculation',
                                    render: (_, line) =>
                                        line.component.calculation_type === 'percentage_of_basic'
                                            ? `${line.component.percentage}% of Basic`
                                            : 'Fixed Amount',
                                },
                                { title: 'Amount', dataIndex: 'amount' },
                            ]}
                        />
                    </>
                )}
            </Drawer>
        </>
    );
}
