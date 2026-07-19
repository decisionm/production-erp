import { useMutation, useQuery } from '@tanstack/react-query';
import { Button, Card, Col, Form, Modal, Row, Select, Statistic, Typography } from 'antd';
import { useState } from 'react';
import { getReliabilityReport, listAssets } from '@/features/maintenance/api';
import type { ReliabilityReport } from '@/features/maintenance/types';

export default function ReliabilityReportPage() {
    const [assetId, setAssetId] = useState<number | undefined>();
    const [report, setReport] = useState<ReliabilityReport | null>(null);

    const { data: assets } = useQuery({ queryKey: ['maintenance', 'assets'], queryFn: listAssets });
    const assetOptions = assets?.data.map((a) => ({ value: a.id, label: `${a.code} — ${a.name}` })) ?? [];

    const mutation = useMutation({
        mutationFn: getReliabilityReport,
        onSuccess: setReport,
        onError: (error: any) => {
            Modal.error({ title: 'Could not load report', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Typography.Title level={3}>Asset Reliability — MTBF / MTTR</Typography.Title>
            <Typography.Paragraph type="secondary">
                MTTR (Mean Time To Repair) is the average duration between a work order starting and completing.
                MTBF (Mean Time Between Failures) is the average gap between reported breakdowns (corrective work
                orders) for the asset. Both need enough history to compute — MTBF needs at least two breakdowns.
            </Typography.Paragraph>

            <Form layout="inline" style={{ marginBottom: 24 }}>
                <Form.Item label="Asset">
                    <Select
                        value={assetId}
                        onChange={setAssetId}
                        options={assetOptions}
                        showSearch
                        optionFilterProp="label"
                        style={{ width: 280 }}
                    />
                </Form.Item>
                <Form.Item>
                    <Button
                        type="primary"
                        disabled={!assetId}
                        loading={mutation.isPending}
                        onClick={() => assetId && mutation.mutate(assetId)}
                    >
                        Load Report
                    </Button>
                </Form.Item>
            </Form>

            {report && (
                <Row gutter={16}>
                    <Col span={6}>
                        <Card>
                            <Statistic title="Completed Work Orders" value={report.completed_work_orders} />
                        </Card>
                    </Col>
                    <Col span={6}>
                        <Card>
                            <Statistic title="Breakdowns" value={report.breakdown_count} />
                        </Card>
                    </Col>
                    <Col span={6}>
                        <Card>
                            <Statistic
                                title="MTTR (hours)"
                                value={report.mttr_hours ?? undefined}
                                precision={2}
                                valueStyle={report.mttr_hours === null ? { color: '#999' } : undefined}
                            />
                            {report.mttr_hours === null && <Typography.Text type="secondary">No data yet</Typography.Text>}
                        </Card>
                    </Col>
                    <Col span={6}>
                        <Card>
                            <Statistic
                                title="MTBF (hours)"
                                value={report.mtbf_hours ?? undefined}
                                precision={2}
                                valueStyle={report.mtbf_hours === null ? { color: '#999' } : undefined}
                            />
                            {report.mtbf_hours === null && <Typography.Text type="secondary">Needs 2+ breakdowns</Typography.Text>}
                        </Card>
                    </Col>
                </Row>
            )}
        </>
    );
}
