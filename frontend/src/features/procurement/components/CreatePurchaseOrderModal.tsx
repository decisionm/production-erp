import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { Alert, DatePicker, Form, Input, Modal, Select, Space, Switch } from 'antd';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createPurchaseOrder } from '@/features/procurement/api';
import PurchaseOrderLinesFields, { emptyPurchaseOrderLine, purchaseOrderLinesSchema } from '@/features/procurement/components/PurchaseOrderLinesFields';
import { apiMessage } from '@/features/procurement/components/apiMessage';
import type { PurchaseOrder } from '@/features/procurement/types';

const orderSchema = z.object({
    vendor_id: z.number({ error: 'Vendor is required' }),
    order_date: z.string({ error: 'Order date is required' }),
    expected_date: z.string().optional(),
    notes: z.string().optional(),
    // A Tally mirror: the real order lives in Tally (the PO/schedule source
    // of truth); this entry records its exact identities read-only.
    is_tally_mirror: z.boolean().optional(),
    tally_order_no: z.string().trim().max(64).optional(),
    lines: purchaseOrderLinesSchema,
}).refine(
    (values) => !values.is_tally_mirror || (values.tally_order_no ?? '') !== '',
    { message: 'A Tally mirror needs the exact Tally order number', path: ['tally_order_no'] },
);
type OrderFormValues = z.infer<typeof orderSchema>;

interface CreatePurchaseOrderModalProps {
    open: boolean;
    onClose: () => void;
    onCreated: (order: PurchaseOrder) => void;
    vendorOptions: { value: number; label: string }[];
    itemOptions: { value: number; label: string }[];
}

/**
 * "New Purchase Order" — the create form as it was on the page, moved here
 * so the page reads as a list. Creates a DRAFT (or, with the Tally-mirror
 * switch, a read-only mirror of an order that lives in Tally, which arrives
 * already Sent). Nothing here moves stock or queues anything for Tally:
 * Tally staging happens on Send, and only when the owner's gate is open.
 */
export default function CreatePurchaseOrderModal({ open, onClose, onCreated, vendorOptions, itemOptions }: CreatePurchaseOrderModalProps) {
    const { control, handleSubmit, reset, formState: { errors } } = useForm<OrderFormValues>({
        resolver: zodResolver(orderSchema),
        defaultValues: { lines: [emptyPurchaseOrderLine()] },
    });

    const createMutation = useMutation({
        mutationFn: createPurchaseOrder,
        onSuccess: (order) => {
            reset();
            onCreated(order);
        },
    });

    return (
        <Modal
            maskClosable={false}
            title="New Purchase Order"
            open={open}
            onCancel={onClose}
            onOk={handleSubmit(({ is_tally_mirror, tally_order_no, ...values }) =>
                createMutation.mutate({
                    ...values,
                    ...(is_tally_mirror ? { source: 'tally' as const, tally_order_no } : {}),
                }),
            )}
            confirmLoading={createMutation.isPending}
            destroyOnHidden
            width={760}
        >
            {createMutation.isError && (
                <Alert
                    type="error"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message="Not created"
                    description={apiMessage(createMutation.error, 'The order could not be created.')}
                />
            )}
            <Form layout="vertical">
                <Form.Item label="Vendor" validateStatus={errors.vendor_id ? 'error' : ''} help={errors.vendor_id?.message}>
                    <Controller
                        name="vendor_id"
                        control={control}
                        render={({ field }) => (
                            <Select {...field} options={vendorOptions} showSearch optionFilterProp="label" />
                        )}
                    />
                </Form.Item>
                <Form.Item label="Order Date" validateStatus={errors.order_date ? 'error' : ''} help={errors.order_date?.message}>
                    <Controller
                        name="order_date"
                        control={control}
                        render={({ field }) => (
                            <DatePicker
                                style={{ width: '100%' }}
                                onChange={(_, dateString) => field.onChange(dateString || undefined)}
                            />
                        )}
                    />
                </Form.Item>
                <Form.Item label="Expected Date">
                    <Controller
                        name="expected_date"
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

                {/* Tally is the PO and delivery-schedule source of truth for
                    an order raised THERE. A mirror records the real order's
                    exact identities; it arrives already sent and is
                    corrected in Tally, never edited here. */}
                <Form.Item label="Mirrored from Tally">
                    <Space>
                        <Controller
                            name="is_tally_mirror"
                            control={control}
                            render={({ field }) => (
                                <Switch checked={field.value ?? false} onChange={field.onChange} />
                            )}
                        />
                        <Controller
                            name="tally_order_no"
                            control={control}
                            render={({ field }) => (
                                <Input {...field} placeholder="Exact Tally order no. (e.g. PO/2026/041)" style={{ width: 280 }} />
                            )}
                        />
                    </Space>
                    {errors.tally_order_no && (
                        <div style={{ color: '#ff4d4f' }}>{errors.tally_order_no.message}</div>
                    )}
                </Form.Item>

                <PurchaseOrderLinesFields control={control} errors={errors} itemOptions={itemOptions} />
            </Form>
        </Modal>
    );
}
