import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Col, Form, Input, InputNumber, Modal, Radio, Row, Select, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listEmployees } from '@/features/hrms/api';
import { listItems, listWarehouses } from '@/features/inventory/api';
import {
    createShiftProductionEntry,
    listScrapReasons,
    listShiftProductionEntries,
    listShifts,
    listWorkCenters,
} from '@/features/production/api';
import type { ShiftProductionEntry } from '@/features/production/types';

const entrySchema = z.object({
    shift_id: z.number({ error: 'Pick a shift' }),
    work_center_id: z.number({ error: 'Pick a machine' }),
    item_id: z.number({ error: 'Item is required' }),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    quantity_produced: z.number().gt(0, 'Must be greater than 0'),
    quantity_scrap: z.number().min(0).optional(),
    scrap_reason_id: z.number().optional(),
    operator_id: z.number().optional(),
    notes: z.string().optional(),
});
type EntryFormValues = z.infer<typeof entrySchema>;

export default function ShiftProductionEntryPage() {
    const [justLoggedIds, setJustLoggedIds] = useState<number[]>([]);
    const queryClient = useQueryClient();

    const { data: shifts } = useQuery({ queryKey: ['production', 'shifts'], queryFn: listShifts });
    const { data: workCenters } = useQuery({ queryKey: ['production', 'work-centers'], queryFn: listWorkCenters });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses'], queryFn: listWarehouses });
    const { data: scrapReasons } = useQuery({ queryKey: ['production', 'scrap-reasons'], queryFn: listScrapReasons });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees'], queryFn: listEmployees });
    const { data: entries, isLoading: entriesLoading } = useQuery({
        queryKey: ['production', 'shift-production-entries'],
        queryFn: listShiftProductionEntries,
    });

    const shiftOptions = shifts?.data.filter((s) => s.is_active).map((s) => ({ value: s.id, label: s.name })) ?? [];
    const workCenterOptions =
        workCenters?.data.filter((w) => w.is_active).map((w) => ({ value: w.id, label: w.name })) ?? [];
    const itemOptions = items?.data.map((i) => ({ value: i.id, label: `${i.sku} — ${i.name}` })) ?? [];
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];
    const scrapReasonOptions = scrapReasons?.data.map((r) => ({ value: r.id, label: `${r.code} — ${r.name}` })) ?? [];
    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];

    const { control, handleSubmit, watch, reset, setValue, formState: { errors } } = useForm<EntryFormValues>({
        resolver: zodResolver(entrySchema),
    });
    const quantityScrap = watch('quantity_scrap');

    const mutation = useMutation({
        mutationFn: createShiftProductionEntry,
        onSuccess: (entry) => {
            queryClient.invalidateQueries({ queryKey: ['production', 'shift-production-entries'] });
            queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
            setJustLoggedIds((prev) => [entry.id, ...prev]);
            // Machine and shift stay put — the next entry at this station is
            // almost always the same station, same shift. Only the
            // per-batch fields reset, so logging the next item is one tap.
            setValue('item_id', undefined as unknown as number);
            setValue('quantity_produced', undefined as unknown as number);
            setValue('quantity_scrap', undefined);
            setValue('scrap_reason_id', undefined);
            setValue('notes', '');
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not log production', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>Log Production</Typography.Title>
            <Typography.Paragraph type="secondary">
                Fast shift-floor entry — pick your machine and shift once, then log each item as it comes off the
                line. Stock updates immediately.
            </Typography.Paragraph>

            <Card style={{ maxWidth: 720, marginBottom: 32 }}>
                <Form layout="vertical" onFinish={handleSubmit((values) => mutation.mutate(values))}>
                    <Form.Item label="Machine" validateStatus={errors.work_center_id ? 'error' : ''} help={errors.work_center_id?.message}>
                        <Controller
                            name="work_center_id"
                            control={control}
                            render={({ field }) => (
                                <Radio.Group
                                    {...field}
                                    optionType="button"
                                    buttonStyle="solid"
                                    options={workCenterOptions}
                                    style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}
                                />
                            )}
                        />
                    </Form.Item>

                    <Form.Item label="Shift" validateStatus={errors.shift_id ? 'error' : ''} help={errors.shift_id?.message}>
                        <Controller
                            name="shift_id"
                            control={control}
                            render={({ field }) => (
                                <Radio.Group
                                    {...field}
                                    optionType="button"
                                    buttonStyle="solid"
                                    size="large"
                                    options={shiftOptions}
                                />
                            )}
                        />
                    </Form.Item>

                    <Row gutter={16}>
                        <Col span={24}>
                            <Form.Item label="Item Produced" validateStatus={errors.item_id ? 'error' : ''} help={errors.item_id?.message}>
                                <Controller
                                    name="item_id"
                                    control={control}
                                    render={({ field }) => (
                                        <Select
                                            {...field}
                                            size="large"
                                            options={itemOptions}
                                            showSearch
                                            optionFilterProp="label"
                                            placeholder="Search item…"
                                        />
                                    )}
                                />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Row gutter={16}>
                        <Col span={24}>
                            <Form.Item
                                label="Finished Goods Warehouse"
                                validateStatus={errors.warehouse_id ? 'error' : ''}
                                help={errors.warehouse_id?.message}
                            >
                                <Controller
                                    name="warehouse_id"
                                    control={control}
                                    render={({ field }) => (
                                        <Select {...field} size="large" options={warehouseOptions} showSearch optionFilterProp="label" />
                                    )}
                                />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Row gutter={16}>
                        <Col span={12}>
                            <Form.Item
                                label="Quantity Produced (good)"
                                validateStatus={errors.quantity_produced ? 'error' : ''}
                                help={errors.quantity_produced?.message}
                            >
                                <Controller
                                    name="quantity_produced"
                                    control={control}
                                    render={({ field }) => <InputNumber {...field} size="large" min={0} style={{ width: '100%' }} />}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item label="Quantity Scrap">
                                <Controller
                                    name="quantity_scrap"
                                    control={control}
                                    render={({ field }) => <InputNumber {...field} size="large" min={0} style={{ width: '100%' }} />}
                                />
                            </Form.Item>
                        </Col>
                    </Row>

                    {!!quantityScrap && quantityScrap > 0 && (
                        <Form.Item label="Scrap Reason">
                            <Controller
                                name="scrap_reason_id"
                                control={control}
                                render={({ field }) => (
                                    <Select {...field} options={scrapReasonOptions} showSearch optionFilterProp="label" allowClear />
                                )}
                            />
                        </Form.Item>
                    )}

                    <Form.Item label="Operator (optional)">
                        <Controller
                            name="operator_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={employeeOptions} showSearch optionFilterProp="label" allowClear />
                            )}
                        />
                    </Form.Item>

                    <Form.Item label="Notes (optional)">
                        <Controller name="notes" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>

                    <Button type="primary" size="large" htmlType="submit" loading={mutation.isPending} block>
                        Log Production
                    </Button>
                </Form>
            </Card>

            <Typography.Title level={5}>Logged This Session</Typography.Title>
            <Table<ShiftProductionEntry>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                size="small"
                loading={entriesLoading}
                pagination={false}
                dataSource={(entries?.data ?? []).filter((e) => justLoggedIds.includes(e.id))}
                locale={{ emptyText: 'Nothing logged yet this session.' }}
                columns={[
                    { title: 'Machine', render: (_, row) => row.work_center.name },
                    { title: 'Shift', render: (_, row) => row.shift.name },
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Produced', dataIndex: 'quantity_produced' },
                    { title: 'Scrap', dataIndex: 'quantity_scrap' },
                    { title: 'Warehouse', render: (_, row) => row.warehouse.code },
                ]}
            />
        </>
    );
}
