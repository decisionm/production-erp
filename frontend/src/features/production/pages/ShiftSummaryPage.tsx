import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Col, DatePicker, Form, Input, InputNumber, Modal, Radio, Row, Select, Space, Statistic, Typography } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listEmployees } from '@/features/hrms/api';
import { getShiftKpiReport, listShifts, saveShiftSummary } from '@/features/production/api';

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

export default function ShiftSummaryPage() {
    const [selectedShiftId, setSelectedShiftId] = useState<number | undefined>(undefined);
    const [productionDate, setProductionDate] = useState(dayjs().format('YYYY-MM-DD'));
    const queryClient = useQueryClient();

    const { data: shifts } = useQuery({ queryKey: ['production', 'shifts'], queryFn: listShifts });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees'], queryFn: listEmployees });
    const shiftOptions = shifts?.data.filter((s) => s.is_active).map((s) => ({ value: s.id, label: s.name })) ?? [];
    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];

    const effectiveShiftId = selectedShiftId ?? shiftOptions[0]?.value;

    const { control, handleSubmit, reset } = useForm<SummaryFormValues>({ resolver: zodResolver(summarySchema) });

    const { data: report, isLoading: reportLoading } = useQuery({
        queryKey: ['production', 'shift-kpi-report', effectiveShiftId, productionDate],
        queryFn: () => getShiftKpiReport(effectiveShiftId as number, productionDate),
        enabled: effectiveShiftId !== undefined,
    });

    // Prefill the editable fields (target/power/remarks/supervisor) from
    // whatever's already saved for this shift+date, without touching the
    // computed KPI fields — those are never form inputs, only display.
    useEffect(() => {
        if (report) {
            reset({
                supervisor_id: report.supervisor?.id,
                target_production_kg: report.target_production_kg ? Number(report.target_production_kg) : undefined,
                power_consumption_units: report.power_consumption_units ? Number(report.power_consumption_units) : undefined,
                remarks: report.remarks ?? undefined,
            });
        }
    }, [report, reset]);

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
            <Typography.Paragraph type="secondary">
                Target Production and Power Consumption are the only two numbers here that don&apos;t come from
                somewhere else — everything below is computed from the shift&apos;s logged batches.
            </Typography.Paragraph>

            <Space direction="vertical" size={16} style={{ width: '100%', marginBottom: 24 }}>
                <Form layout="inline">
                    <Form.Item label="Shift">
                        <Radio.Group
                            value={effectiveShiftId}
                            onChange={(e) => setSelectedShiftId(e.target.value)}
                            optionType="button"
                            buttonStyle="solid"
                            options={shiftOptions}
                        />
                    </Form.Item>
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

                <Col xs={24} lg={14}>
                    <Card title="Computed KPI Report" loading={reportLoading}>
                        <Row gutter={[16, 16]}>
                            <Col span={8}><Statistic title="Actual Production (Kg)" value={report?.actual_production_kg ?? '—'} /></Col>
                            <Col span={8}><Statistic title="Rejection (Kg)" value={report?.rejection_kg ?? '—'} /></Col>
                            <Col span={8}><Statistic title="Net Good Output (Kg)" value={report?.net_good_output_kg ?? '—'} /></Col>
                            <Col span={8}><Statistic title="Shift Efficiency" value={formatPercent(report?.efficiency_percent ?? null)} /></Col>
                            <Col span={8}><Statistic title="Rejection %" value={formatPercent(report?.rejection_percent ?? null)} /></Col>
                            <Col span={8}><Statistic title="Unit / Kg" value={report?.unit_per_kg?.toFixed(2) ?? '—'} /></Col>
                            <Col span={8}><Statistic title="Machines Running" value={report?.machines_running ?? '—'} /></Col>
                            <Col span={8}><Statistic title="Machines Down" value={report?.machines_down ?? '—'} /></Col>
                            <Col span={8}><Statistic title="Mold Changes" value={report?.no_of_mold_changes ?? '—'} /></Col>
                            <Col span={12}><Statistic title="Idle Time (Hrs)" value={report?.idle_time_hours ?? '—'} /></Col>
                        </Row>
                        <Typography.Paragraph type="secondary" style={{ marginTop: 16, marginBottom: 0 }}>
                            Machines Down is a live count of currently-open breakdowns; Idle Time only sums
                            breakdowns that have been closed — logged from the Shift Floor page.
                        </Typography.Paragraph>
                    </Card>
                </Col>
            </Row>
        </>
    );
}
