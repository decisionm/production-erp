import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createIncomingInspection, listIncomingInspections } from '@/features/quality/api';
import type { IncomingInspection, InspectionResult } from '@/features/quality/types';
import { listGoodsReceipts } from '@/features/procurement/api';

const inspectionSchema = z.object({
    goods_receipt_note_line_id: z.number({ error: 'GRN line is required' }),
    inspected_quantity: z.number().gt(0, 'Must be greater than 0'),
    accepted_quantity: z.number().min(0),
    rejected_quantity: z.number().min(0),
    inspection_date: z.string({ error: 'Inspection date is required' }),
    notes: z.string().optional(),
});
type InspectionFormValues = z.infer<typeof inspectionSchema>;

const resultColor: Record<InspectionResult, string> = {
    pass: 'green',
    fail: 'red',
    partial: 'gold',
};

export default function IncomingInspectionsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['quality', 'incoming-inspections'], queryFn: listIncomingInspections });
    const { data: receipts } = useQuery({ queryKey: ['procurement', 'goods-receipts'], queryFn: listGoodsReceipts });

    const lineOptions = useMemo(
        () =>
            receipts?.data.flatMap((grn) =>
                grn.lines.map((line) => ({
                    value: line.id,
                    label: `GRN #${grn.id} — ${line.item.sku} — received ${line.quantity}`,
                })),
            ) ?? [],
        [receipts],
    );

    const { control, handleSubmit, reset, watch, formState: { errors } } = useForm<InspectionFormValues>({
        resolver: zodResolver(inspectionSchema),
        defaultValues: { inspected_quantity: 0, accepted_quantity: 0, rejected_quantity: 0 },
    });

    const [inspected, accepted, rejected] = watch(['inspected_quantity', 'accepted_quantity', 'rejected_quantity']);
    const preview = useMemo(() => {
        const i = Number(inspected) || 0;
        const a = Number(accepted) || 0;
        const r = Number(rejected) || 0;
        const balanced = Math.abs(a + r - i) < 0.0001;
        const result: InspectionResult | null = !balanced ? null : r === 0 ? 'pass' : a === 0 ? 'fail' : 'partial';
        return { balanced, result };
    }, [inspected, accepted, rejected]);

    const mutation = useMutation({
        mutationFn: createIncomingInspection,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['quality', 'incoming-inspections'] });
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not record inspection', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Incoming Inspections</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Inspection</Button>
            </Space>

            <Table<IncomingInspection>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Inspected', dataIndex: 'inspected_quantity' },
                    { title: 'Accepted', dataIndex: 'accepted_quantity' },
                    { title: 'Rejected', dataIndex: 'rejected_quantity' },
                    {
                        title: 'Result',
                        dataIndex: 'result',
                        render: (result: InspectionResult) => <Tag color={resultColor[result]}>{result}</Tag>,
                    },
                    { title: 'Date', dataIndex: 'inspection_date' },
                ]}
            />

            <Modal
                title="New Incoming Inspection"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                okButtonProps={{ disabled: !preview.balanced }}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="GRN Line"
                        validateStatus={errors.goods_receipt_note_line_id ? 'error' : ''}
                        help={errors.goods_receipt_note_line_id?.message}
                    >
                        <Controller
                            name="goods_receipt_note_line_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={lineOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Inspected Quantity">
                        <Controller
                            name="inspected_quantity"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Accepted Quantity">
                        <Controller
                            name="accepted_quantity"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Rejected Quantity">
                        <Controller
                            name="rejected_quantity"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Inspection Date"
                        validateStatus={errors.inspection_date ? 'error' : ''}
                        help={errors.inspection_date?.message}
                    >
                        <Controller
                            name="inspection_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Notes">
                        <Controller name="notes" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>

                    <Alert
                        type={preview.balanced ? 'success' : 'warning'}
                        message={
                            preview.balanced
                                ? `Result: ${preview.result}`
                                : 'Accepted + rejected must equal inspected quantity'
                        }
                        showIcon
                    />
                </Form>
            </Modal>
        </>
    );
}
