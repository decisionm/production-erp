import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Select, Space, Table, Typography } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listWarehouses } from '@/features/inventory/api';
import { createDelivery, listDeliveries, listSalesOrders } from '@/features/sales/api';
import type { Delivery } from '@/features/sales/types';

const deliverySchema = z.object({
    sales_order_id: z.number({ error: 'Sales order is required' }),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    reference: z.string().optional(),
    lines: z
        .array(
            z.object({
                sales_order_line_id: z.number(),
                item_label: z.string(),
                quantity: z.number().gt(0, 'Quantity must be greater than 0'),
            }),
        )
        .min(1, 'Selected sales order has nothing left to deliver'),
});
type DeliveryFormValues = z.infer<typeof deliverySchema>;

export default function DeliveriesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['sales', 'deliveries'], queryFn: listDeliveries });
    const { data: orders } = useQuery({ queryKey: ['sales', 'sales-orders'], queryFn: listSalesOrders });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses'], queryFn: listWarehouses });

    const deliverableOrders = useMemo(
        () => orders?.data.filter((o) => o.status === 'confirmed' || o.status === 'partially_delivered') ?? [],
        [orders],
    );
    const orderOptions = deliverableOrders.map((o) => ({ value: o.id, label: `SO #${o.id} — ${o.customer.name}` }));
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];

    const { control, handleSubmit, reset, watch, formState: { errors } } = useForm<DeliveryFormValues>({
        resolver: zodResolver(deliverySchema),
        defaultValues: { lines: [] },
    });
    const { fields, replace } = useFieldArray({ control, name: 'lines' });
    const selectedOrderId = watch('sales_order_id');

    useEffect(() => {
        const order = deliverableOrders.find((o) => o.id === selectedOrderId);
        if (!order) {
            replace([]);
            return;
        }

        const remainingLines = order.lines
            .filter((line) => Number(line.quantity) - Number(line.quantity_delivered) > 0)
            .map((line) => ({
                sales_order_line_id: line.id,
                item_label: `${line.item.sku} — ${line.item.name}`,
                quantity: Number(line.quantity) - Number(line.quantity_delivered),
            }));

        replace(remainingLines);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedOrderId]);

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['sales', 'deliveries'] });
        queryClient.invalidateQueries({ queryKey: ['sales', 'sales-orders'] });
        queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
    };

    const mutation = useMutation({
        mutationFn: createDelivery,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset({ lines: [] });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not post delivery', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Deliveries</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Delivery</Button>
            </Space>

            <Table<Delivery>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'ID', dataIndex: 'id' },
                    { title: 'SO', render: (_, row) => `SO #${row.sales_order_id}` },
                    { title: 'Warehouse', render: (_, row) => `${row.warehouse.code} — ${row.warehouse.name}` },
                    { title: 'Delivered', dataIndex: 'delivered_date' },
                    { title: 'Reference', dataIndex: 'reference' },
                    { title: 'Lines', render: (_, row) => row.lines.length },
                ]}
            />

            <Modal
                title="New Delivery"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) =>
                    mutation.mutate({
                        sales_order_id: values.sales_order_id,
                        warehouse_id: values.warehouse_id,
                        reference: values.reference,
                        lines: values.lines.map((l) => ({
                            sales_order_line_id: l.sales_order_line_id,
                            quantity: l.quantity,
                        })),
                    }),
                )}
                confirmLoading={mutation.isPending}
                destroyOnHidden
                width={700}
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Sales Order"
                        validateStatus={errors.sales_order_id ? 'error' : ''}
                        help={errors.sales_order_id?.message}
                    >
                        <Controller
                            name="sales_order_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={orderOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Warehouse"
                        validateStatus={errors.warehouse_id ? 'error' : ''}
                        help={errors.warehouse_id?.message}
                    >
                        <Controller
                            name="warehouse_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={warehouseOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Reference">
                        <Controller name="reference" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>

                    <Typography.Text strong>Lines to Deliver</Typography.Text>
                    {errors.lines?.root && (
                        <div style={{ color: '#ff4d4f', marginBottom: 8 }}>{errors.lines.root.message}</div>
                    )}
                    {fields.length === 0 && (
                        <Typography.Paragraph type="secondary" style={{ marginTop: 8 }}>
                            Select a sales order with remaining quantity to deliver.
                        </Typography.Paragraph>
                    )}
                    {fields.map((field, index) => (
                        <Space key={field.id} align="baseline" style={{ display: 'flex', marginTop: 8 }}>
                            <span style={{ width: 220, display: 'inline-block' }}>{field.item_label}</span>
                            <Controller
                                name={`lines.${index}.quantity`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Quantity" />}
                            />
                        </Space>
                    ))}
                </Form>
            </Modal>
        </>
    );
}
