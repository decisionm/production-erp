import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listEmployees } from '@/features/hrms/api';
import { listItems, listWarehouses } from '@/features/inventory/api';
import {
    addMaintenanceWorkOrderPart,
    cancelMaintenanceWorkOrder,
    completeMaintenanceWorkOrder,
    createMaintenanceWorkOrder,
    listAssets,
    listMaintenanceWorkOrders,
    startMaintenanceWorkOrder,
} from '@/features/maintenance/api';
import type { MaintenanceWorkOrder, MaintenanceWorkOrderStatus, MaintenanceWorkOrderType } from '@/features/maintenance/types';

const createSchema = z.object({
    asset_id: z.number({ error: 'Asset is required' }),
    type: z.enum(['preventive', 'corrective'], { error: 'Type is required' }),
    description: z.string().optional(),
    assigned_to: z.number().optional(),
});
type CreateFormValues = z.infer<typeof createSchema>;

const partSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    quantity: z.number().gt(0, 'Quantity must be greater than 0'),
});
type PartFormValues = z.infer<typeof partSchema>;

const completeSchema = z.object({
    labor_cost: z.number().min(0).optional(),
});
type CompleteFormValues = z.infer<typeof completeSchema>;

const statusColor: Record<MaintenanceWorkOrderStatus, string> = {
    open: 'default',
    in_progress: 'blue',
    completed: 'green',
    cancelled: 'red',
};

const typeOptions: { value: MaintenanceWorkOrderType; label: string }[] = [
    { value: 'preventive', label: 'Preventive' },
    { value: 'corrective', label: 'Corrective (Breakdown)' },
];

export default function WorkOrdersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [partsForId, setPartsForId] = useState<number | null>(null);
    const [completingId, setCompletingId] = useState<number | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['maintenance', 'work-orders'], queryFn: () => listMaintenanceWorkOrders() });
    const { data: assets } = useQuery({ queryKey: ['maintenance', 'assets'], queryFn: listAssets });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees'], queryFn: listEmployees });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses'], queryFn: listWarehouses });

    const assetOptions = assets?.data.map((a) => ({ value: a.id, label: `${a.code} — ${a.name}` })) ?? [];
    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];
    const itemOptions = items?.data.map((i) => ({ value: i.id, label: `${i.sku} — ${i.name}` })) ?? [];
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<CreateFormValues>({
        resolver: zodResolver(createSchema),
        defaultValues: { type: 'corrective' },
    });

    const createMutation = useMutation({
        mutationFn: createMaintenanceWorkOrder,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset({ type: 'corrective' });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create work order', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const startMutation = useMutation({
        mutationFn: startMaintenanceWorkOrder,
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not start work order', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const cancelMutation = useMutation({
        mutationFn: cancelMaintenanceWorkOrder,
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not cancel work order', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: partControl,
        handleSubmit: handlePartSubmit,
        reset: resetPart,
        formState: { errors: partErrors },
    } = useForm<PartFormValues>({ resolver: zodResolver(partSchema) });

    const addPartMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & PartFormValues) => addMaintenanceWorkOrderPart(id, payload),
        onSuccess: () => {
            invalidate();
            setPartsForId(null);
            resetPart();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not add part', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: completeControl,
        handleSubmit: handleCompleteSubmit,
        reset: resetComplete,
    } = useForm<CompleteFormValues>({ resolver: zodResolver(completeSchema) });

    const completeMutation = useMutation({
        mutationFn: ({ id, laborCost }: { id: number; laborCost?: number }) => completeMaintenanceWorkOrder(id, laborCost),
        onSuccess: () => {
            invalidate();
            setCompletingId(null);
            resetComplete();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not complete work order', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Maintenance Work Orders</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>Report Work Order</Button>
            </Space>

            <Table<MaintenanceWorkOrder>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Asset', render: (_, row) => `${row.asset.code} — ${row.asset.name}` },
                    { title: 'Type', dataIndex: 'type' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: MaintenanceWorkOrderStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Reported', dataIndex: 'reported_date' },
                    { title: 'Parts Cost', dataIndex: 'parts_cost' },
                    { title: 'Labor Cost', dataIndex: 'labor_cost' },
                    { title: 'Total Cost', dataIndex: 'total_cost' },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                {(row.status === 'open' || row.status === 'in_progress') && (
                                    <Button size="small" onClick={() => setPartsForId(row.id)}>Add Part</Button>
                                )}
                                {row.status === 'open' && (
                                    <Button size="small" onClick={() => startMutation.mutate(row.id)} loading={startMutation.isPending}>
                                        Start
                                    </Button>
                                )}
                                {row.status === 'in_progress' && (
                                    <Button size="small" onClick={() => setCompletingId(row.id)}>Complete</Button>
                                )}
                                {row.status === 'open' && (
                                    <Button
                                        size="small"
                                        danger
                                        onClick={() => cancelMutation.mutate(row.id)}
                                        loading={cancelMutation.isPending}
                                    >
                                        Cancel
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                title="Report Work Order"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Asset" validateStatus={errors.asset_id ? 'error' : ''} help={errors.asset_id?.message}>
                        <Controller
                            name="asset_id"
                            control={control}
                            render={({ field }) => <Select {...field} options={assetOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item label="Type" validateStatus={errors.type ? 'error' : ''} help={errors.type?.message}>
                        <Controller name="type" control={control} render={({ field }) => <Select {...field} options={typeOptions} />} />
                    </Form.Item>
                    <Form.Item label="Description">
                        <Controller name="description" control={control} render={({ field }) => <Input.TextArea {...field} rows={2} />} />
                    </Form.Item>
                    <Form.Item label="Assigned To">
                        <Controller
                            name="assigned_to"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={employeeOptions} showSearch optionFilterProp="label" allowClear />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title="Add Part"
                open={partsForId !== null}
                onCancel={() => setPartsForId(null)}
                onOk={handlePartSubmit((values) => {
                    if (partsForId !== null) {
                        addPartMutation.mutate({ id: partsForId, ...values });
                    }
                })}
                confirmLoading={addPartMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Item"
                        validateStatus={partErrors.item_id ? 'error' : ''}
                        help={partErrors.item_id?.message}
                    >
                        <Controller
                            name="item_id"
                            control={partControl}
                            render={({ field }) => <Select {...field} options={itemOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Warehouse"
                        validateStatus={partErrors.warehouse_id ? 'error' : ''}
                        help={partErrors.warehouse_id?.message}
                    >
                        <Controller
                            name="warehouse_id"
                            control={partControl}
                            render={({ field }) => (
                                <Select {...field} options={warehouseOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Quantity"
                        validateStatus={partErrors.quantity ? 'error' : ''}
                        help={partErrors.quantity?.message}
                    >
                        <Controller
                            name="quantity"
                            control={partControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title="Complete Work Order"
                open={completingId !== null}
                onCancel={() => setCompletingId(null)}
                onOk={handleCompleteSubmit((values) => {
                    if (completingId !== null) {
                        completeMutation.mutate({ id: completingId, laborCost: values.labor_cost });
                    }
                })}
                confirmLoading={completeMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Labor Cost (optional)">
                        <Controller
                            name="labor_cost"
                            control={completeControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
