import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listItems } from '@/features/inventory/api';
import {
    closeNonConformanceReport,
    createNonConformanceReport,
    listIncomingInspections,
    listNonConformanceReports,
} from '@/features/quality/api';
import type { NonConformanceReport, NonConformanceSeverity, NonConformanceStatus } from '@/features/quality/types';

const ncrSchema = z.object({
    incoming_inspection_id: z.number().optional(),
    item_id: z.number().optional(),
    description: z.string().min(1, 'Description is required'),
    severity: z.enum(['minor', 'major', 'critical'], { error: 'Severity is required' }),
    quantity_affected: z.number().min(0).optional(),
    raised_date: z.string({ error: 'Raised date is required' }),
}).refine((data) => data.incoming_inspection_id !== undefined || data.item_id !== undefined, {
    message: 'Select either an incoming inspection or an item',
    path: ['item_id'],
});
type NCRFormValues = z.infer<typeof ncrSchema>;

const severityColor: Record<NonConformanceSeverity, string> = {
    minor: 'default',
    major: 'gold',
    critical: 'red',
};
const statusColor: Record<NonConformanceStatus, string> = {
    open: 'blue',
    closed: 'green',
};

export default function NonConformanceReportsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [closingReport, setClosingReport] = useState<NonConformanceReport | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['quality', 'ncrs'], queryFn: listNonConformanceReports });
    const { data: inspections } = useQuery({ queryKey: ['quality', 'incoming-inspections'], queryFn: listIncomingInspections });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });

    const inspectionOptions =
        inspections?.data.map((i) => ({ value: i.id, label: `Inspection #${i.id} — ${i.item.sku} (${i.result})` })) ?? [];
    const itemOptions = items?.data.map((item) => ({ value: item.id, label: `${item.sku} — ${item.name}` })) ?? [];

    const { control, handleSubmit, reset, watch, formState: { errors } } = useForm<NCRFormValues>({
        resolver: zodResolver(ncrSchema),
    });
    const selectedInspectionId = watch('incoming_inspection_id');

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['quality', 'ncrs'] });

    const createMutation = useMutation({
        mutationFn: createNonConformanceReport,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });

    const { control: closeControl, handleSubmit: handleCloseSubmit, reset: resetClose } = useForm<{ resolution: string }>({
        defaultValues: { resolution: '' },
    });
    const closeMutation = useMutation({
        mutationFn: ({ id, resolution }: { id: number; resolution: string }) => closeNonConformanceReport(id, resolution),
        onSuccess: () => {
            invalidate();
            setClosingReport(null);
            resetClose();
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Non-Conformance Reports</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New NCR</Button>
            </Space>

            <Table<NonConformanceReport>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'ID', dataIndex: 'id' },
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    {
                        title: 'Severity',
                        dataIndex: 'severity',
                        render: (s: NonConformanceSeverity) => <Tag color={severityColor[s]}>{s}</Tag>,
                    },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (s: NonConformanceStatus) => <Tag color={statusColor[s]}>{s}</Tag>,
                    },
                    { title: 'Raised', dataIndex: 'raised_date' },
                    {
                        title: 'Actions',
                        render: (_, row) =>
                            row.status === 'open' && (
                                <Button size="small" onClick={() => setClosingReport(row)}>Close</Button>
                            ),
                    },
                ]}
            />

            <Modal
                title="New Non-Conformance Report"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Incoming Inspection (optional)">
                        <Controller
                            name="incoming_inspection_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={inspectionOptions} showSearch optionFilterProp="label" allowClear />
                            )}
                        />
                    </Form.Item>
                    {!selectedInspectionId && (
                        <Form.Item
                            label="Item"
                            validateStatus={errors.item_id ? 'error' : ''}
                            help={errors.item_id?.message}
                        >
                            <Controller
                                name="item_id"
                                control={control}
                                render={({ field }) => (
                                    <Select {...field} options={itemOptions} showSearch optionFilterProp="label" />
                                )}
                            />
                        </Form.Item>
                    )}
                    <Form.Item
                        label="Description"
                        validateStatus={errors.description ? 'error' : ''}
                        help={errors.description?.message}
                    >
                        <Controller
                            name="description"
                            control={control}
                            render={({ field }) => <Input.TextArea {...field} rows={3} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Severity"
                        validateStatus={errors.severity ? 'error' : ''}
                        help={errors.severity?.message}
                    >
                        <Controller
                            name="severity"
                            control={control}
                            render={({ field }) => (
                                <Select
                                    {...field}
                                    options={[
                                        { value: 'minor', label: 'Minor' },
                                        { value: 'major', label: 'Major' },
                                        { value: 'critical', label: 'Critical' },
                                    ]}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Quantity Affected">
                        <Controller
                            name="quantity_affected"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Raised Date"
                        validateStatus={errors.raised_date ? 'error' : ''}
                        help={errors.raised_date?.message}
                    >
                        <Controller
                            name="raised_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title={`Close NCR #${closingReport?.id}`}
                open={closingReport !== null}
                onCancel={() => setClosingReport(null)}
                onOk={handleCloseSubmit((values) => {
                    if (closingReport) closeMutation.mutate({ id: closingReport.id, resolution: values.resolution });
                })}
                confirmLoading={closeMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Resolution">
                        <Controller
                            name="resolution"
                            control={closeControl}
                            render={({ field }) => <Input.TextArea {...field} rows={3} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
