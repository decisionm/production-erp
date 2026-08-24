import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Col, DatePicker, Form, Input, InputNumber, Modal, Radio, Row, Segmented, Select, Space, Statistic, Table, Typography } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useRef, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listAllEmployees } from '@/features/hrms/api';
import { getShiftKpiReport, listCompletedEntriesFor, listShifts, saveShiftSummary } from '@/features/production/api';
import CecPreviewPanel from '@/features/production/components/CecPreviewPanel';
import { currentShift, productionDateFor } from '@/features/production/shiftClock';
import { reconcileShiftSummary } from '@/features/production/shiftSummaryReconcile';
import { itemLabel } from '@/lib/itemLabel';
import type {
    ShiftKpiDowntimeLog,
    ShiftKpiItemBreakdown,
    ShiftKpiMoldChangeLog,
    ShiftKpiPowerInterruptionLog,
    ShiftKpiStockCount,
} from '@/features/production/types';

const summarySchema = z.object({
    supervisor_id: z.number().optional(),
    target_production_kg: z.number().min(0).optional(),
    power_consumption_units: z.number().min(0).optional(),
    remarks: z.string().optional(),
});
type SummaryFormValues = z.infer<typeof summarySchema>;

function formatPercent(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(1)}%`;
}

function formatTime(iso: string | null): string {
    return iso ? dayjs(iso).format('HH:mm') : '—';
}

export default function ShiftSummaryPage() {
    const [scope, setScope] = useState<'shift' | 'day'>('shift');
    const [selectedShiftId, setSelectedShiftId] = useState<number | undefined>(undefined);
    const [productionDate, setProductionDate] = useState(dayjs().format('YYYY-MM-DD'));
    const queryClient = useQueryClient();

    const { data: shifts } = useQuery({ queryKey: ['production', 'shifts'], queryFn: () => listShifts() });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees', 'all'], queryFn: listAllEmployees });
    const activeShifts = shifts?.data.filter((s) => s.is_active) ?? [];
    const shiftOptions = activeShifts.map((s) => ({ value: s.id, label: s.name }));
    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];

    // Same clock rule as the Shift Floor: default to the shift running right
    // now, and to ITS production date — so a Night-shift supervisor opening
    // this at 02:00 lands on yesterday's night report, not an empty today.
    const detectedShift = currentShift(activeShifts);
    const effectiveShiftId = selectedShiftId ?? detectedShift?.id ?? shiftOptions[0]?.value;

    const dateInitialized = useRef(false);
    useEffect(() => {
        if (!dateInitialized.current && detectedShift) {
            dateInitialized.current = true;
            setProductionDate(productionDateFor(detectedShift));
        }
    }, [detectedShift]);
    // Day scope means "every shift that ran this date" — the API treats a
    // missing shift_id as that rollup, not an error.
    const queryShiftId = scope === 'shift' ? effectiveShiftId : undefined;

    const { control, handleSubmit, reset } = useForm<SummaryFormValues>({ resolver: zodResolver(summarySchema) });

    const { data: report, isLoading: reportLoading } = useQuery({
        queryKey: ['production', 'shift-kpi-report', queryShiftId ?? 'day', productionDate],
        queryFn: () => getShiftKpiReport(queryShiftId, productionDate),
        enabled: scope === 'day' || effectiveShiftId !== undefined,
    });

    // Completed batches ↔ summary (Phase 5.7): the same date/shift read the
    // way Completed Today reads it (entries index, batch_status = completed,
    // walked to the last page), summed at 4 dp and set beside the report's
    // actual_production_kg. Two server reads of the same entries — this line
    // says whether they agree, and names the difference when they do not.
    const { data: completedWalk, isLoading: completedLoading, error: completedError } = useQuery({
        queryKey: ['production', 'shift-summary-reconcile', queryShiftId ?? 'day', productionDate],
        queryFn: () => listCompletedEntriesFor(productionDate, queryShiftId),
        enabled: scope === 'day' || effectiveShiftId !== undefined,
    });
    // A walk the 25-page bound cut short is not a verdict — say so instead.
    const completedTruncated = completedWalk?.truncated === true;
    const reconcile =
        report && completedWalk && !completedTruncated ? reconcileShiftSummary(report.actual_production_kg, completedWalk.entries) : null;
    // Phase 5.7 names the two live counts for what they are; a backend that
    // predates the rename sends only the old keys, which mean the same thing.
    const machinesRunningNow = report?.machines_running_now ?? report?.machines_running;
    const machinesDownNow = report?.machines_down_now ?? report?.machines_down;
    // The server names what efficiency divides by (only ever the
    // supervisor-typed target) and whether a target is on record at all.
    const efficiencyTitle =
        report?.efficiency_basis === 'supervisor_target'
            ? 'Shift Efficiency (vs supervisor target)'
            : report?.kpi_inputs?.target_production_kg === null
              ? 'Shift Efficiency (no target typed)'
              : 'Shift Efficiency';

    // Prefill the editable fields (target/power/remarks/supervisor) from
    // whatever's already saved for this shift+date, without touching the
    // computed KPI fields — those are never form inputs, only display.
    useEffect(() => {
        if (report && scope === 'shift') {
            reset({
                supervisor_id: report.supervisor?.id,
                target_production_kg: report.target_production_kg ? Number(report.target_production_kg) : undefined,
                power_consumption_units: report.power_consumption_units ? Number(report.power_consumption_units) : undefined,
                remarks: report.remarks ?? undefined,
            });
        }
    }, [report, reset, scope]);

    const saveMutation = useMutation({
        mutationFn: (values: SummaryFormValues) => {
            if (!effectiveShiftId) throw new Error('Pick a shift');
            return saveShiftSummary({ ...values, shift_id: effectiveShiftId, production_date: productionDate });
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'shift-kpi-report'] });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not save shift summary', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>Shift Summary</Typography.Title>

            <Space direction="vertical" size={16} style={{ width: '100%', marginBottom: 24 }}>
                <Form layout="inline">
                    <Form.Item label="View">
                        <Segmented
                            value={scope}
                            onChange={(v) => setScope(v as 'shift' | 'day')}
                            options={[{ label: 'Shift', value: 'shift' }, { label: 'Whole Day', value: 'day' }]}
                        />
                    </Form.Item>
                    {scope === 'shift' && (
                        <Form.Item label="Shift">
                            <Radio.Group
                                value={effectiveShiftId}
                                onChange={(e) => setSelectedShiftId(e.target.value)}
                                optionType="button"
                                buttonStyle="solid"
                                options={shiftOptions}
                            />
                        </Form.Item>
                    )}
                    <Form.Item label="Date">
                        <DatePicker
                            value={dayjs(productionDate)}
                            onChange={(_, dateString) => setProductionDate((dateString as string) || dayjs().format('YYYY-MM-DD'))}
                            allowClear={false}
                        />
                    </Form.Item>
                </Form>
            </Space>

            <Row gutter={16}>
                {scope === 'shift' && (
                    <Col xs={24} lg={10}>
                        <Card title="Supervisor Inputs" loading={reportLoading}>
                            <Form layout="vertical">
                                <Form.Item label="Supervisor">
                                    <Controller
                                        name="supervisor_id"
                                        control={control}
                                        render={({ field }) => (
                                            <Select {...field} options={employeeOptions} showSearch optionFilterProp="label" allowClear />
                                        )}
                                    />
                                </Form.Item>
                                <Form.Item label="Target Production (Kg)">
                                    <Controller
                                        name="target_production_kg"
                                        control={control}
                                        render={({ field }) => <InputNumber {...field} size="large" min={0} style={{ width: '100%' }} />}
                                    />
                                </Form.Item>
                                <Form.Item label="Power Consumption (Units)">
                                    <Controller
                                        name="power_consumption_units"
                                        control={control}
                                        render={({ field }) => <InputNumber {...field} size="large" min={0} style={{ width: '100%' }} />}
                                    />
                                </Form.Item>
                                <Form.Item label="Remarks">
                                    <Controller name="remarks" control={control} render={({ field }) => <Input.TextArea {...field} rows={3} />} />
                                </Form.Item>
                                <Button
                                    type="primary"
                                    block
                                    loading={saveMutation.isPending}
                                    onClick={handleSubmit((values) => saveMutation.mutate(values))}
                                >
                                    Save
                                </Button>
                            </Form>
                        </Card>
                    </Col>
                )}

                <Col xs={24} lg={scope === 'shift' ? 14 : 24}>
                    <Card title="Computed KPI Report" loading={reportLoading}>
                        <Row gutter={[16, 16]}>
                            <Col xs={12} sm={8}><Statistic title="Actual Production (Kg)" value={report?.actual_production_kg ?? '—'} /></Col>
                            <Col xs={12} sm={8}><Statistic title="Rejection (Kg)" value={report?.rejection_kg ?? '—'} /></Col>
                            <Col xs={12} sm={8}><Statistic title="Net Good Output (Kg)" value={report?.net_good_output_kg ?? '—'} /></Col>
                            <Col xs={12} sm={8}>
                                <Statistic title={efficiencyTitle} value={formatPercent(report?.efficiency_percent ?? null)} />
                            </Col>
                            <Col xs={12} sm={8}><Statistic title="Rejection %" value={formatPercent(report?.rejection_percent ?? null)} /></Col>
                            <Col xs={12} sm={8}><Statistic title="Unit / Kg" value={report?.unit_per_kg?.toFixed(2) ?? '—'} /></Col>
                            <Col xs={12} sm={8}><Statistic title="Machines Running (now)" value={machinesRunningNow ?? '—'} /></Col>
                            <Col xs={12} sm={8}><Statistic title="Machines Down (now)" value={machinesDownNow ?? '—'} /></Col>
                            <Col xs={12} sm={8}><Statistic title="Mold Changes" value={report?.no_of_mold_changes ?? '—'} /></Col>
                            <Col xs={12} sm={8}><Statistic title="Idle Time — Machines (Hrs)" value={report?.idle_time_hours ?? '—'} /></Col>
                            <Col xs={12} sm={8}><Statistic title="Idle Time — Power Cuts (Hrs)" value={report?.power_interruption_hours ?? '—'} /></Col>
                        </Row>
                        <Typography.Paragraph type="secondary" style={{ marginTop: 16, marginBottom: 0 }}>
                            Machines Running and Machines Down are counted <strong>now</strong> — batches in progress and
                            breakdowns still open at this moment, filed under this date — not what the machines were doing
                            during the shift; on a past date they read 0 unless something is still open. Shift Efficiency
                            is measured against the supervisor-typed target and is blank until a non-zero target is typed. The two idle-time
                            figures are kept separate on purpose — one machine breaking down isn&apos;t the same as the whole
                            floor losing power at once.
                        </Typography.Paragraph>
                        <Typography.Paragraph style={{ marginTop: 12, marginBottom: 0 }}>
                            <Typography.Text strong>Completed batches vs summary: </Typography.Text>
                            {completedError ? (
                                <Typography.Text type="secondary">could not read the completed batches for this date.</Typography.Text>
                            ) : completedLoading || reportLoading ? (
                                <Typography.Text type="secondary">reading…</Typography.Text>
                            ) : completedTruncated ? (
                                <Typography.Text type="warning">
                                    not compared — the completed-batches read stopped at its 25-page bound (2,500 entries) before the
                                    last page; the summary&apos;s figure stands on its own
                                </Typography.Text>
                            ) : reconcile === null ? (
                                <Typography.Text type="secondary">—</Typography.Text>
                            ) : reconcile.equal ? (
                                <Typography.Text type="success">
                                    ✓ equal ({reconcile.batches} {reconcile.batches === 1 ? 'batch' : 'batches'}) — Σ completed batches{' '}
                                    {reconcile.sumKg} kg = Shift Summary actual {reconcile.summaryKg} kg
                                </Typography.Text>
                            ) : (
                                <Typography.Text type="danger">
                                    differs by {reconcile.difference} kg — Σ completed batches ({reconcile.batches}) {reconcile.sumKg} kg vs
                                    Shift Summary actual {reconcile.summaryKg} kg (
                                    {reconcile.direction === 'batches_over' ? 'batches exceed the summary' : 'summary exceeds the batches'})
                                </Typography.Text>
                            )}
                        </Typography.Paragraph>
                    </Card>
                </Col>
            </Row>

            <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
                <Col xs={24} lg={12}>
                    <Card title="Items Manufactured" size="small">
                        <Table<ShiftKpiItemBreakdown>
                            scroll={{ x: 'max-content' }}
                            size="small"
                            rowKey={(row) => row.item.id}
                            pagination={false}
                            loading={reportLoading}
                            dataSource={report?.items_manufactured ?? []}
                            locale={{ emptyText: 'Nothing completed yet.' }}
                            columns={[
                                { title: 'Item', render: (_, row) => itemLabel(row.item) },
                                { title: 'Batches', dataIndex: 'batches', width: 80 },
                                { title: 'Produced (Nos)', dataIndex: 'quantity_produced' },
                                { title: 'Produced (Kg)', dataIndex: 'quantity_produced_kg' },
                                { title: 'Rejected (Nos)', dataIndex: 'quantity_rejected' },
                                { title: 'Rejected (Kg)', dataIndex: 'quantity_rejection_kg' },
                            ]}
                        />
                    </Card>
                </Col>

                <Col xs={24} lg={12}>
                    <Card title="Machine Downtime" size="small">
                        <Table<ShiftKpiDowntimeLog>
                            scroll={{ x: 'max-content' }}
                            size="small"
                            rowKey="id"
                            pagination={false}
                            loading={reportLoading}
                            dataSource={report?.downtime_logs ?? []}
                            locale={{ emptyText: 'No breakdowns logged.' }}
                            columns={[
                                { title: 'Machine', dataIndex: 'work_center' },
                                { title: 'Problem', dataIndex: 'nature_of_problem' },
                                { title: 'From', render: (_, row) => formatTime(row.from_time) },
                                { title: 'To', render: (_, row) => formatTime(row.to_time) },
                                { title: 'Mins', dataIndex: 'total_minutes', render: (v: string | null) => v ?? '—' },
                                {
                                    title: 'Status',
                                    dataIndex: 'status',
                                    render: (s: string) => (s === 'open' ? <Typography.Text type="danger">Open</Typography.Text> : 'Closed'),
                                },
                            ]}
                        />
                    </Card>
                </Col>

                <Col xs={24} lg={12}>
                    <Card title="Power Interruptions" size="small">
                        <Table<ShiftKpiPowerInterruptionLog>
                            scroll={{ x: 'max-content' }}
                            size="small"
                            rowKey="id"
                            pagination={false}
                            loading={reportLoading}
                            dataSource={report?.power_interruption_logs ?? []}
                            locale={{ emptyText: 'None logged.' }}
                            columns={[
                                { title: 'From', render: (_, row) => formatTime(row.from_time) },
                                { title: 'To', render: (_, row) => formatTime(row.to_time) },
                                { title: 'Hours', dataIndex: 'idle_hours' },
                            ]}
                        />
                    </Card>
                </Col>

                <Col xs={24} lg={12}>
                    <Card title="Mold Changes" size="small">
                        <Table<ShiftKpiMoldChangeLog>
                            scroll={{ x: 'max-content' }}
                            size="small"
                            rowKey="id"
                            pagination={false}
                            loading={reportLoading}
                            dataSource={report?.mold_change_logs ?? []}
                            locale={{ emptyText: 'None logged.' }}
                            columns={[
                                { title: 'Machine', dataIndex: 'work_center' },
                                { title: 'Mold Out', dataIndex: 'changed_from_mold', render: (v: string | null) => v ?? '—' },
                                { title: 'Mold In', dataIndex: 'changed_to_mold', render: (v: string | null) => v ?? '—' },
                                { title: 'To Item', dataIndex: 'changed_to' },
                                { title: 'Mins', dataIndex: 'total_minutes', render: (v: string | null) => v ?? '—' },
                                {
                                    title: 'Status',
                                    dataIndex: 'status',
                                    render: (s: string) => (s === 'open' ? <Typography.Text type="warning">In Progress</Typography.Text> : 'Done'),
                                },
                            ]}
                        />
                    </Card>
                </Col>

                {(report?.stock_counts.length ?? 0) > 0 && (
                    <Col xs={24}>
                        <Card title="Stock Counts" size="small">
                            <Table<ShiftKpiStockCount>
                                scroll={{ x: 'max-content' }}
                                size="small"
                                rowKey="id"
                                pagination={false}
                                dataSource={report?.stock_counts ?? []}
                                columns={[
                                    { title: 'Location', dataIndex: 'location_label' },
                                    { title: 'Item', render: (_, row) => itemLabel(row.item) },
                                    { title: 'Kg', dataIndex: 'quantity_kg' },
                                ]}
                            />
                        </Card>
                    </Col>
                )}

                <Col xs={24}>
                    <CecPreviewPanel
                        productionDate={productionDate}
                        shiftId={queryShiftId}
                        enabled={scope === 'day' || effectiveShiftId !== undefined}
                    />
                </Col>
            </Row>
        </>
    );
}
