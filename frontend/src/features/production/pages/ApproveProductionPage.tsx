import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Descriptions, Drawer, Input, Modal, Segmented, Space, Steps, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { useAuthStore } from '@/features/auth/store';
import {
    accountantApproveShiftProductionEntry,
    listShiftProductionEntries,
    pmApproveShiftProductionEntry,
    rejectShiftProductionEntry,
} from '@/features/production/api';
import type { ConsumptionVariance, ShiftProductionEntry, ShiftProductionEntryStatus } from '@/features/production/types';

const statusColor: Record<ShiftProductionEntryStatus, string> = {
    pending: 'processing',
    pm_approved: 'cyan',
    accountant_approved: 'geekblue',
    approved: 'success',
    rejected: 'error',
    synced: 'success',
    failed: 'error',
};

const statusLabel: Record<ShiftProductionEntryStatus, string> = {
    pending: 'Awaiting Plant Manager',
    pm_approved: 'Awaiting Accountant',
    accountant_approved: 'Awaiting MD (reserved)',
    approved: 'Approved — syncing',
    rejected: 'Rejected',
    synced: 'Synced to Tally',
    failed: 'Sync failed',
};

/** "20.0000" → "20", "1.50" → "1.5", up to 2 decimals. "—" for null/unparseable. */
const fmtKg = (v: string | null | undefined): string => {
    if (v === null || v === undefined) return '—';
    const n = parseFloat(v);
    if (Number.isNaN(n)) return '—';
    return String(parseFloat(n.toFixed(2)));
};

/** Same as fmtKg but with an explicit "+" on positive values (for variances). */
const fmtSignedKg = (v: string | null | undefined): string => {
    const s = fmtKg(v);
    return s !== '—' && parseFloat(s) > 0 ? `+${s}` : s;
};

const varianceTag = (pct: number | null) => {
    if (pct === null) return null;
    const abs = Math.abs(pct);
    if (abs <= 2) return <Tag color="green">OK</Tag>;
    if (abs <= 5) return <Tag color="orange">Watch</Tag>;
    return <Tag color="red">Investigate</Tag>;
};

/**
 * Expected-vs-actual material use for a completed batch — the block the Plant
 * Manager and Accountant scan to decide whether consumption needs questioning
 * before it posts to Tally. `unaccounted_kg` is the number to investigate.
 */
function VarianceSection({ variance }: { variance: ConsumptionVariance }) {
    if (variance.norm_source === null) {
        return (
            <>
                <Typography.Title level={5} style={{ marginTop: 16 }}>Material Usage vs Norm</Typography.Title>
                <Descriptions column={1} size="small" bordered>
                    <Descriptions.Item label="Actual consumed">{fmtKg(variance.actual_kg)} Kg</Descriptions.Item>
                </Descriptions>
                <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8 }}>
                    No norm set for this product
                </Typography.Text>
            </>
        );
    }

    const unaccounted = variance.unaccounted_kg === null ? null : parseFloat(variance.unaccounted_kg);
    const unaccountedHot = unaccounted !== null && !Number.isNaN(unaccounted) && Math.abs(unaccounted) > 0.5;

    return (
        <>
            <Typography.Title level={5} style={{ marginTop: 16 }}>Material Usage vs Norm</Typography.Title>
            <Descriptions column={1} size="small" bordered>
                <Descriptions.Item label="Expected (per norm)">
                    {variance.expected_kg === null ? '—' : `${fmtKg(variance.expected_kg)} Kg`}
                </Descriptions.Item>
                <Descriptions.Item label="Actual consumed">{fmtKg(variance.actual_kg)} Kg</Descriptions.Item>
                <Descriptions.Item label="Variance">
                    {variance.variance_kg === null ? (
                        '—'
                    ) : (
                        <Space size={4} wrap>
                            <span>
                                {fmtSignedKg(variance.variance_kg)} Kg
                                {variance.variance_pct !== null
                                    ? ` (${variance.variance_pct > 0 ? '+' : ''}${variance.variance_pct}%)`
                                    : ''}
                            </span>
                            {varianceTag(variance.variance_pct)}
                        </Space>
                    )}
                </Descriptions.Item>
            </Descriptions>
            {variance.unaccounted_kg !== null && (
                <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8 }}>
                    of which rejection {fmtKg(variance.rejection_kg)} kg · scrap {fmtKg(variance.scrap_kg)} kg ·{' '}
                    <Typography.Text type={unaccountedHot ? 'danger' : 'secondary'} strong={unaccountedHot}>
                        unaccounted {fmtSignedKg(variance.unaccounted_kg)} kg
                    </Typography.Text>
                </Typography.Text>
            )}
            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 4, fontSize: 12 }}>
                norm: {variance.norm_source === 'bom' ? 'BOM' : 'product weight'}
            </Typography.Text>
        </>
    );
}

/**
 * The 4-stage chain (factory answer 9): Supervisor submits → Plant Manager
 * verifies → Accountant reconciles → MD final approval → Tally. Each row's
 * available action depends on its stage AND the viewer's role — the stage
 * config drives both the button and the visibility.
 */
const STAGES: {
    status: ShiftProductionEntryStatus;
    action: string;
    roles: string[];
    mutate: (id: number) => Promise<ShiftProductionEntry>;
}[] = [
    { status: 'pending', action: 'Approve (Plant Manager)', roles: ['Plant Manager', 'Administrator'], mutate: pmApproveShiftProductionEntry },
    // The accountant's approval posts to Tally (team decision 2026-07-26).
    // MD approval is reserved for a future "big approvals" flow.
    { status: 'pm_approved', action: 'Approve & Post (Accountant)', roles: ['Accounts', 'Administrator'], mutate: accountantApproveShiftProductionEntry },
];

export default function ApproveProductionPage() {
    const [status, setStatus] = useState<ShiftProductionEntryStatus>('pending');
    const [detailRow, setDetailRow] = useState<ShiftProductionEntry | null>(null);
    const [rejectingRow, setRejectingRow] = useState<ShiftProductionEntry | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const queryClient = useQueryClient();
    const user = useAuthStore((s) => s.user);
    const myRoles = user?.roles?.map((r) => r.name) ?? [];

    const stageFor = (s: ShiftProductionEntryStatus) => STAGES.find((st) => st.status === s);
    const canActOn = (row: ShiftProductionEntry) => {
        const stage = stageFor(row.status);
        return stage !== undefined && stage.roles.some((r) => myRoles.includes(r));
    };

    const { data, isLoading } = useQuery({
        queryKey: ['production', 'shift-production-entries', status],
        queryFn: () => listShiftProductionEntries(status),
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['production', 'shift-production-entries'] });

    const approveMutation = useMutation({
        mutationFn: (row: ShiftProductionEntry) => stageFor(row.status)!.mutate(row.id),
        onSuccess: () => {
            invalidate();
            setDetailRow(null);
        },
        onError: (error: any) => {
            Modal.error({
                title: 'Could not approve',
                content: error?.response?.data?.message ?? 'This entry may have already been decided — refresh and try again.',
            });
        },
    });

    const rejectMutation = useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) => rejectShiftProductionEntry(id, reason || undefined),
        onSuccess: () => {
            invalidate();
            setRejectingRow(null);
            setRejectReason('');
            setDetailRow(null);
        },
        onError: (error: any) => {
            Modal.error({
                title: 'Could not reject',
                content: error?.response?.data?.message ?? 'This entry may have already been decided — refresh and try again.',
            });
        },
    });

    const chainStep = (row: ShiftProductionEntry): number => {
        if (row.status === 'pending') return 1;
        if (row.status === 'pm_approved') return 2;
        return 3; // approved / synced / failed — accountant done, Tally next/done
    };

    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>Approve Production</Typography.Title>
            <Typography.Paragraph type="secondary">
                Every completed batch passes the full chain — Supervisor → Plant Manager → Accountant → MD —
                before it syncs to Tally. Rejection at any stage sends it back to the supervisor.
            </Typography.Paragraph>

            <Segmented
                value={status}
                onChange={(v) => setStatus(v as ShiftProductionEntryStatus)}
                options={[
                    { label: 'Plant Manager', value: 'pending' },
                    { label: 'Accountant', value: 'pm_approved' },
                    { label: 'Approved', value: 'approved' },
                    { label: 'Synced', value: 'synced' },
                    { label: 'Rejected', value: 'rejected' },
                ]}
                style={{ marginBottom: 16, maxWidth: '100%', overflowX: 'auto' }}
            />

            <Table<ShiftProductionEntry>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                locale={{ emptyText: `Nothing waiting here.` }}
                columns={[
                    { title: 'Date', dataIndex: 'production_date' },
                    { title: 'Shift', render: (_, row) => row.shift.name },
                    { title: 'Machine', render: (_, row) => row.work_center.name },
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Batch #', dataIndex: 'batch_number', render: (v: string | null) => v ?? '—' },
                    { title: 'Produced', dataIndex: 'quantity_produced' },
                    { title: 'Produced (Kg)', dataIndex: 'quantity_produced_kg', render: (v: string | null) => v ?? '—' },
                    { title: 'Rejected', dataIndex: 'quantity_scrap' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (s: ShiftProductionEntryStatus) => <Tag color={statusColor[s]}>{statusLabel[s]}</Tag>,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => setDetailRow(row)}>View</Button>
                                {canActOn(row) && (
                                    <>
                                        <Button
                                            size="small"
                                            type="primary"
                                            loading={approveMutation.isPending}
                                            onClick={() => approveMutation.mutate(row)}
                                        >
                                            {stageFor(row.status)!.action}
                                        </Button>
                                        <Button size="small" danger onClick={() => setRejectingRow(row)}>
                                            Reject
                                        </Button>
                                    </>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Drawer
                title={`Batch #${detailRow?.id} — ${detailRow?.work_center.name} · ${detailRow?.item.sku}`}
                open={detailRow !== null}
                onClose={() => setDetailRow(null)}
                width="min(100vw, 520px)"
                destroyOnHidden
            >
                {detailRow && (
                    <>
                        <Steps
                            size="small"
                            current={chainStep(detailRow)}
                            status={detailRow.status === 'rejected' ? 'error' : detailRow.status === 'failed' ? 'error' : 'process'}
                            style={{ marginBottom: 20 }}
                            items={[
                                { title: 'Supervisor' },
                                { title: 'Plant Mgr' },
                                { title: 'Accountant' },
                                { title: 'Tally' },
                            ]}
                        />
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Status">
                                <Tag color={statusColor[detailRow.status]}>{statusLabel[detailRow.status]}</Tag>
                            </Descriptions.Item>
                            <Descriptions.Item label="Date">{detailRow.production_date}</Descriptions.Item>
                            <Descriptions.Item label="Shift">{detailRow.shift.name}</Descriptions.Item>
                            <Descriptions.Item label="Batch Number">{detailRow.batch_number ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Produced">
                                {detailRow.quantity_produced} Nos{detailRow.quantity_produced_kg ? ` (${detailRow.quantity_produced_kg} Kg)` : ''}
                            </Descriptions.Item>
                            <Descriptions.Item label="Rejected">
                                {detailRow.quantity_scrap} Nos{detailRow.quantity_rejection_kg ? ` (${detailRow.quantity_rejection_kg} Kg)` : ''}
                            </Descriptions.Item>
                            <Descriptions.Item label="Rejection Reason">{detailRow.scrap_reason?.name ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Packing">
                                {detailRow.nos_per_tray ?? '—'}/tray × {detailRow.no_of_trays ?? '—'} trays,{' '}
                                {detailRow.nos_per_box ?? '—'}/box × {detailRow.no_of_box ?? '—'} boxes
                            </Descriptions.Item>
                            <Descriptions.Item label="Operator">{detailRow.operator?.name ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Notes">{detailRow.notes ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Plant Manager">
                                {detailRow.plant_manager_signed_by
                                    ? `${detailRow.plant_manager_signed_by.name} · ${detailRow.plant_manager_signed_at?.slice(0, 16).replace('T', ' ') ?? ''}`
                                    : '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Accountant">
                                {detailRow.accountant_signed_by
                                    ? `${detailRow.accountant_signed_by.name} · ${detailRow.accountant_signed_at?.slice(0, 16).replace('T', ' ') ?? ''}`
                                    : '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Final Approval">
                                {detailRow.approved_by ? detailRow.approved_by.name : '—'}
                            </Descriptions.Item>
                            {detailRow.rejection_reason && (
                                <Descriptions.Item label="Rejected Because">{detailRow.rejection_reason}</Descriptions.Item>
                            )}
                        </Descriptions>

                        {detailRow.variance && <VarianceSection variance={detailRow.variance} />}

                        {detailRow.material_consumptions.length > 0 && (
                            <>
                                <Typography.Title level={5} style={{ marginTop: 16 }}>Material Consumption</Typography.Title>
                                <Table
                                    size="small"
                                    rowKey="id"
                                    pagination={false}
                                    dataSource={detailRow.material_consumptions}
                                    columns={[
                                        { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                                        { title: 'From', render: (_, row) => row.warehouse.code },
                                        { title: 'Kg', dataIndex: 'quantity_issued_kg' },
                                    ]}
                                />
                            </>
                        )}

                        {detailRow.scraps.length > 0 && (
                            <>
                                <Typography.Title level={5} style={{ marginTop: 16 }}>Scrap Detail</Typography.Title>
                                <Table
                                    size="small"
                                    rowKey="id"
                                    pagination={false}
                                    dataSource={detailRow.scraps}
                                    columns={[
                                        { title: 'Type', dataIndex: 'type' },
                                        { title: 'Nos', dataIndex: 'quantity_nos', render: (v: string | null) => v ?? '—' },
                                        { title: 'Kg', dataIndex: 'quantity_kg', render: (v: string | null) => v ?? '—' },
                                        { title: 'Reason', render: (_, row) => row.scrap_reason?.name ?? '—' },
                                    ]}
                                />
                            </>
                        )}

                        {canActOn(detailRow) && (
                            <Space style={{ marginTop: 24 }}>
                                <Button type="primary" loading={approveMutation.isPending} onClick={() => approveMutation.mutate(detailRow)}>
                                    {stageFor(detailRow.status)!.action}
                                </Button>
                                <Button danger onClick={() => setRejectingRow(detailRow)}>Reject</Button>
                            </Space>
                        )}
                    </>
                )}
            </Drawer>

            <Modal
                maskClosable={false}
                title={`Reject Batch #${rejectingRow?.id}`}
                open={rejectingRow !== null}
                onCancel={() => {
                    setRejectingRow(null);
                    setRejectReason('');
                }}
                onOk={() => rejectingRow && rejectMutation.mutate({ id: rejectingRow.id, reason: rejectReason })}
                confirmLoading={rejectMutation.isPending}
                okText="Reject"
                okButtonProps={{ danger: true }}
                destroyOnHidden
            >
                <Input.TextArea
                    rows={3}
                    placeholder="Reason (optional) — helps the supervisor fix and resubmit"
                    value={rejectReason}
                    onChange={(e) => setRejectReason(e.target.value)}
                />
            </Modal>
        </>
    );
}
