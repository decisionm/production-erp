import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useSearchParams } from 'react-router-dom';
import { z } from 'zod';
import { createIncomingInspection, listIncomingInspections } from '@/features/quality/api';
import type { IncomingInspection, InspectionResult } from '@/features/quality/types';
import { listGoodsReceipts } from '@/features/procurement/api';
import { ListEmpty } from '@/lib/ListEmpty';
import { inspectionPreview, resultTag } from '@/features/quality/words';

const inspectionSchema = z.object({
    goods_receipt_note_line_id: z.number({ error: 'GRN line is required' }),
    inspected_quantity: z.number().gt(0, 'Must be greater than 0'),
    accepted_quantity: z.number().min(0),
    rejected_quantity: z.number().min(0),
    inspection_date: z.string({ error: 'Inspection date is required' }),
    notes: z.string().optional(),
});
type InspectionFormValues = z.infer<typeof inspectionSchema>;

export default function IncomingInspectionsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailRow, setDetailRow] = useState<IncomingInspection | null>(null);
    const queryClient = useQueryClient();
    // ?line={grn_line_id}: the goods-receipt drawer's "Record inspection"
    // road (audit finding 9) — the modal opens with that line pre-selected.
    const [searchParams, setSearchParams] = useSearchParams();
    const linkedLineId = Number(searchParams.get('line')) || null;

    const { data, isLoading, isPending, isError, error, refetch } = useQuery({ queryKey: ['quality', 'incoming-inspections'], queryFn: listIncomingInspections });
    // THE WHOLE REGISTER, NOT THE FIRST PAGE. This picker is the only control
    // anywhere that releases a bag from waiting_qc. Asking for the default page
    // capped it at the newest 20 receipts, so material on the twenty-first
    // oldest arrival could never be inspected: its bags stay waiting_qc, the
    // scanner refuses them, and incoming-QC hold keeps subtracting their
    // kilograms from every outflow of that item — permanently, with nothing on
    // screen to say why. The 'all' key matches the request the goods-receipt
    // page already makes for its deep-link read, so the two share a cache
    // entry rather than collide, and it stays under the prefix that page
    // invalidates.
    const { data: receipts } = useQuery({
        queryKey: ['procurement', 'goods-receipts', 'all'],
        queryFn: () => listGoodsReceipts({ per_page: 1000 }),
    });

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

    // Arriving via ?line=: open the modal with the line chosen, then clear
    // the param so closing the modal does not reopen it. Runs once per link.
    useEffect(() => {
        if (linkedLineId !== null) {
            reset({
                goods_receipt_note_line_id: linkedLineId,
                inspected_quantity: 0,
                accepted_quantity: 0,
                rejected_quantity: 0,
            });
            setModalOpen(true);
            setSearchParams((current) => {
                const next = new URLSearchParams(current);
                next.delete('line');
                return next;
            }, { replace: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [linkedLineId]);

    const [inspected, accepted, rejected] = watch(['inspected_quantity', 'accepted_quantity', 'rejected_quantity']);
    // inspectionPreview (quality/words.ts): empty or all-zero is INCOMPLETE,
    // never a green "pass" over material nobody has inspected (28-Aug audit).
    const preview = useMemo(
        () => inspectionPreview({ inspected: Number(inspected) || null, accepted: Number(accepted) || 0, rejected: Number(rejected) || 0 }),
        [inspected, accepted, rejected],
    );

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
                locale={{
                    emptyText: (
                        <ListEmpty
                            state={{ isPending, isError, error, refetch }}
                            entity="incoming inspections"
                            empty="No incoming inspections recorded yet."
                        />
                    ),
                }}
                columns={[
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Inspected', dataIndex: 'inspected_quantity' },
                    { title: 'Accepted', dataIndex: 'accepted_quantity' },
                    { title: 'Rejected', dataIndex: 'rejected_quantity' },
                    {
                        title: 'Result',
                        dataIndex: 'result',
                        render: (result: InspectionResult) => {
                            const tag = resultTag(result);
                            return <Tag color={tag.color}>{tag.label}</Tag>;
                        },
                    },
                    { title: 'Date', dataIndex: 'inspection_date' },
                    {
                        title: 'Disposition',
                        render: (_, row) => (
                            <>
                                {row.bag_disposition_note && (
                                    <Typography.Text style={{ display: 'block', fontSize: 12 }}>
                                        {row.bag_disposition_note}
                                    </Typography.Text>
                                )}
                                {row.rejections_out_reference && (
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        Rejections Out ref {row.rejections_out_reference} — recorded; no Tally voucher until its shape is proven.
                                    </Typography.Text>
                                )}
                                {!row.bag_disposition_note && !row.rejections_out_reference && '—'}
                            </>
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button size="small" onClick={() => setDetailRow(row)}>View</Button>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Incoming Inspection"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                okButtonProps={{ disabled: preview.kind !== 'result' }}
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
                        type={preview.kind === 'result' ? 'success' : preview.kind === 'incomplete' ? 'info' : 'warning'}
                        message={
                            preview.kind === 'result'
                                ? `Result: ${resultTag(preview.result).label}`
                                : preview.kind === 'unbalanced'
                                    ? 'Accepted + rejected must equal inspected quantity'
                                    : 'Type the inspected, accepted and rejected quantities — no verdict until they are in.'
                        }
                        showIcon
                    />
                </Form>
            </Modal>

            <Drawer
                title={`Inspection #${detailRow?.id}`}
                open={detailRow !== null}
                onClose={() => setDetailRow(null)}
                width="min(100vw, 480px)"
                destroyOnHidden
            >
                {detailRow && (
                    <Descriptions column={1} size="small" bordered>
                        <Descriptions.Item label="Item">
                            {detailRow.item.sku} — {detailRow.item.name}
                        </Descriptions.Item>
                        <Descriptions.Item label="Result">
                            {(() => {
                                const tag = resultTag(detailRow.result);
                                return <Tag color={tag.color}>{tag.label}</Tag>;
                            })()}
                        </Descriptions.Item>
                        <Descriptions.Item label="Inspected Quantity">{detailRow.inspected_quantity}</Descriptions.Item>
                        <Descriptions.Item label="Accepted Quantity">{detailRow.accepted_quantity}</Descriptions.Item>
                        <Descriptions.Item label="Rejected Quantity">{detailRow.rejected_quantity}</Descriptions.Item>
                        <Descriptions.Item label="Inspection Date">{detailRow.inspection_date}</Descriptions.Item>
                        <Descriptions.Item label="Inspected By">{detailRow.inspected_by ?? '—'}</Descriptions.Item>
                        <Descriptions.Item label="Notes">{detailRow.notes ?? '—'}</Descriptions.Item>
                    </Descriptions>
                )}
            </Drawer>
        </>
    );
}
