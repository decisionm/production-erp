import { Line } from '@ant-design/plots';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, Col, DatePicker, Form, Input, InputNumber, Modal, Row, Table, Tag, Tooltip, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useParams } from 'react-router-dom';
import { z } from 'zod';
import { CONFIGURATION_STATUS_WORDS } from '@/components/configuration/configurationWords';
import { getSpcChart, listSpcCharacteristics, recordSpcMeasurement } from '@/features/quality/api';
import type { SpcChartPoint } from '@/features/quality/types';

const measurementSchema = z.object({
    value: z.number({ error: 'Value is required' }),
    measured_at: z.string().optional(),
    notes: z.string().optional(),
});
type MeasurementFormValues = z.infer<typeof measurementSchema>;

interface ChartRow {
    x: number;
    y: number;
    series: string;
}

function buildSeriesRows(
    points: SpcChartPoint[],
    field: 'value' | 'moving_range',
    referenceLines: { label: string; value: number | null }[],
): ChartRow[] {
    const rows: ChartRow[] = [];
    const label = field === 'value' ? 'Value' : 'Moving Range';

    points.forEach((point, index) => {
        const y = field === 'value' ? point.value : point.moving_range;
        if (y !== null) {
            rows.push({ x: index + 1, y, series: label });
        }
    });

    const lastX = points.length;
    referenceLines.forEach(({ label: lineLabel, value }) => {
        if (value === null) return;
        rows.push({ x: 1, y: value, series: lineLabel });
        rows.push({ x: lastX, y: value, series: lineLabel });
    });

    return rows;
}

export default function SpcChartPage() {
    const { id } = useParams<{ id: string }>();
    const characteristicId = Number(id);
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data: characteristics } = useQuery({ queryKey: ['quality', 'spc-characteristics'], queryFn: () => listSpcCharacteristics() });
    const characteristic = characteristics?.data.find((c) => c.id === characteristicId);

    const { data: chart, isLoading } = useQuery({
        queryKey: ['quality', 'spc-chart', characteristicId],
        queryFn: () => getSpcChart(characteristicId),
    });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<MeasurementFormValues>({
        resolver: zodResolver(measurementSchema),
    });

    const mutation = useMutation({
        mutationFn: (values: MeasurementFormValues) => recordSpcMeasurement(characteristicId, values),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['quality', 'spc-chart', characteristicId] });
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not record measurement', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const iChartData = useMemo(() => {
        if (!chart || !chart.sufficient_data) return [];
        return buildSeriesRows(chart.points, 'value', [
            { label: 'CL', value: chart.center_line },
            { label: 'UCL', value: chart.ucl },
            { label: 'LCL', value: chart.lcl },
            { label: 'USL', value: characteristic?.upper_spec_limit ? Number(characteristic.upper_spec_limit) : null },
            { label: 'LSL', value: characteristic?.lower_spec_limit ? Number(characteristic.lower_spec_limit) : null },
        ]);
    }, [chart, characteristic]);

    const mrChartData = useMemo(() => {
        if (!chart || !chart.sufficient_data) return [];
        return buildSeriesRows(chart.points, 'moving_range', [
            { label: 'MR-CL', value: chart.mr_center_line },
            { label: 'MR-UCL', value: chart.mr_ucl },
        ]);
    }, [chart]);

    const violations = chart?.points.filter((p) => p.beyond_limits || p.run_violation) ?? [];

    return (
        <>
            <Typography.Title level={3}>
                SPC Chart {characteristic ? `— ${characteristic.item.sku}: ${characteristic.name}` : ''}
            </Typography.Title>
            {/* WS-B: `RecordSpcMeasurementRequest` refuses a WITHDRAWN
                characteristic — one the factory has stopped measuring — so the
                button that would ask stops asking. The chart itself keeps
                every measurement already recorded. */}
            {characteristic !== undefined && !characteristic.is_active ? (
                <Tooltip title={CONFIGURATION_STATUS_WORDS.retired.description}>
                    <span style={{ display: 'inline-block', marginBottom: 16 }}>
                        <Button type="primary" disabled>
                            Record Measurement
                        </Button>
                    </span>
                </Tooltip>
            ) : (
                <Button type="primary" style={{ marginBottom: 16 }} onClick={() => setModalOpen(true)}>
                    Record Measurement
                </Button>
            )}

            {!isLoading && chart && !chart.sufficient_data && (
                <Alert
                    type="info"
                    showIcon
                    message="Not enough data yet"
                    description="At least 2 measurements are needed to compute control limits."
                    style={{ marginBottom: 16 }}
                />
            )}

            {violations.length > 0 && (
                <Alert
                    type="warning"
                    showIcon
                    message={`${violations.length} point(s) out of statistical control`}
                    style={{ marginBottom: 16 }}
                />
            )}

            {chart && chart.sufficient_data && (
                <>
                    <Row gutter={16}>
                        <Col span={12}>
                            <Card title="Individuals (I) Chart">
                                <Line data={iChartData} xField="x" yField="y" seriesField="series" colorField="series" height={280} />
                            </Card>
                        </Col>
                        <Col span={12}>
                            <Card title="Moving Range (MR) Chart">
                                <Line data={mrChartData} xField="x" yField="y" seriesField="series" colorField="series" height={280} />
                            </Card>
                        </Col>
                    </Row>

                    <Card title="Points" style={{ marginTop: 16 }}>
                        <Table<SpcChartPoint>
                            scroll={{ x: 'max-content' }}
                            rowKey="id"
                            size="small"
                            dataSource={chart.points}
                            pagination={false}
                            columns={[
                                { title: 'Measured At', dataIndex: 'measured_at' },
                                { title: 'Value', dataIndex: 'value' },
                                { title: 'Moving Range', dataIndex: 'moving_range' },
                                {
                                    title: 'Status',
                                    render: (_, row) =>
                                        row.beyond_limits || row.run_violation ? (
                                            <Tag color="red">
                                                {row.beyond_limits ? 'Beyond limits' : ''}
                                                {row.beyond_limits && row.run_violation ? ' + ' : ''}
                                                {row.run_violation ? 'Run of 8' : ''}
                                            </Tag>
                                        ) : (
                                            <Tag color="green">In control</Tag>
                                        ),
                                },
                            ]}
                        />
                    </Card>
                </>
            )}

            <Modal
                maskClosable={false}
                title="Record Measurement"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Value" validateStatus={errors.value ? 'error' : ''} help={errors.value?.message}>
                        <Controller
                            name="value"
                            control={control}
                            render={({ field }) => <InputNumber {...field} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Measured At (optional, defaults to now)">
                        <Controller
                            name="measured_at"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    showTime
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange((dateString as string) || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Notes">
                        <Controller name="notes" control={control} render={({ field }) => <Input.TextArea {...field} rows={2} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
