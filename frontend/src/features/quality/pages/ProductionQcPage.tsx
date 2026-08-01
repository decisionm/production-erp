import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Descriptions, Drawer, Input, InputNumber, Space, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { useAuthStore } from '@/features/auth/store';
import { hasManageAccess, hasModuleAccess } from '@/features/auth/permissions';
import {
    type BatchQualityQueueRow,
    createBatchQualityCheck,
    listBatchQualityQueue,
} from '@/features/quality/api';
import { grossProducedPieces, readQuantity } from '@/features/production/types';
import { itemLabel } from '@/lib/itemLabel';

/**
 * Production QC — the queue the owner asked for: "all the machines will go to
 * quality queue, and quality will do the check, add entry as how many
 * reviewed, how many okay and how many rejected."
 *
 * Every completed batch waits here before the Plant Manager can approve it —
 * the backend's pmApprove() refuses an unchecked batch outright, so this is a
 * real gate, not a display. What quality records reduces the production figure
 * that flows on to the PM, the accountant and Tally. Rejected bottles are
 * scrap and are never reworked (owner, asked directly: "no — go to the
 * rejected scrap only"), so nothing here offers a rework path.
 *
 * UNITS. Reviewed/OK/Rejected are whole PIECES (bottles) — the server
 * validates them as integers and refuses decimals, because a decimal here
 * means someone sent kilograms. The batch's production-side rejection shown
 * beside them IS kilograms. The two are never added or converted: the
 * bottles→kg conversion needs the run's frozen unit weight, and the server
 * does it. Every number on this screen is labelled with its unit.
 */

const fmtPcs = (raw: string | number | null | undefined): string => {
    const n = readQuantity(raw);
    return n === null ? '—' : n.toLocaleString('en-IN');
};

const fmtKg = (raw: string | null | undefined): string => {
    const n = readQuantity(raw);
    return n === null ? '—' : `${n.toLocaleString('en-IN')} kg`;
};

/**
 * Oldest first — the queue is worked front to back. The server orders newest
 * first (production_date desc, id desc) for the approval screens, so this is a
 * deliberate re-sort, not a coincidence of the payload.
 */
function oldestFirst(rows: BatchQualityQueueRow[]): BatchQualityQueueRow[] {
    const at = (row: BatchQualityQueueRow): number => {
        const byDate = row.production_date ? dayjs(row.production_date).valueOf() : NaN;
        return Number.isFinite(byDate) ? byDate : 0;
    };
    // Same production date is the common case (a day's batches), so the id
    // breaks the tie — it is monotonic in creation order.
    return [...rows].sort((a, b) => at(a) - at(b) || a.id - b.id);
}

export default function ProductionQcPage() {
    const user = useAuthStore((s) => s.user);
    // The same gate the Quality nav group uses (AppLayout's `module: 'quality'`),
    // applied at the page too because App.tsx routes carry no permission check
    // and this URL is reachable by typing it.
    const canView = hasModuleAccess(user, 'quality');
    // Recording a check is a POST under module:quality ⇒ needs quality.manage.
    // A view-only user must not be shown a button that can only ever 403.
    const canSubmit = hasManageAccess(user, 'quality');
    // The queue is derived from the production entry list, which is
    // production-gated. Worth naming on screen, because "empty queue" and
    // "you cannot read the queue" must not look the same.
    const canReadQueue = hasModuleAccess(user, 'production');

    const queryClient = useQueryClient();
    const [openRow, setOpenRow] = useState<BatchQualityQueueRow | null>(null);

    const { data, isLoading, error } = useQuery({
        queryKey: ['quality', 'batch-quality-queue'],
        queryFn: listBatchQualityQueue,
        enabled: canView && canReadQueue,
        retry: false,
    });

    const status = (error as { response?: { status?: number } } | null)?.response?.status;
    const rows = oldestFirst(data?.rows ?? []);
    // Pending batches exist, but not one of them carries the gate: the factory
    // has stood the quality stage down (PROD_QUALITY_STAGE_ENABLED=false).
    // Saying so is the difference between "nothing to do" and "this screen is
    // not in use" — and the second is not something to leave anyone guessing at.
    const stageStoodDown = data?.stageEnabled === false;

    if (!canView) {
        return (
            <>
                <Typography.Title level={3} style={{ marginBottom: 4 }}>Production QC</Typography.Title>
                <Alert
                    type="warning"
                    showIcon
                    message="You do not have Quality access"
                    description="This queue is part of the Quality module. Ask an administrator for the Quality permission if you are meant to check batches."
                />
            </>
        );
    }

    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>Production QC</Typography.Title>
            <Typography.Paragraph type="secondary">
                Every completed batch waits here for its check before the Plant Manager can approve it. Record how many
                pieces were reviewed, how many were OK and how many were rejected — rejected pieces become scrap and
                reduce the production figure that goes on to Tally.
            </Typography.Paragraph>

            {!canReadQueue && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="You can record checks, but not list the queue"
                    description="The waiting-batches list is served by the production module, so reading it needs Production view permission on top of Quality. Ask an administrator to add it — until then this page cannot show you what is waiting."
                />
            )}

            {error && (
                <Alert
                    type={status === 403 ? 'warning' : 'error'}
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={status === 403 ? 'Not allowed to read the batch list' : 'Could not load the quality queue'}
                    description={
                        status === 403
                            ? 'Reading the waiting batches needs Production view permission alongside Quality.'
                            : 'Refresh the page. If it keeps failing the batches are still safe — they simply are not listed here.'
                    }
                />
            )}

            {stageStoodDown && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="The quality stage is switched off"
                    description={`Completed batches are going straight to the Plant Manager, as they did before this stage existed — ${data?.pendingCount ?? 0} are waiting for approval right now. Nothing needs checking here until the stage is switched back on.`}
                />
            )}

            {/* No table at all when the queue could not be read — whether that
                is a permission or a failed request. An empty grid saying "no
                batches waiting" underneath a banner explaining that the list
                could not be fetched reads as "nothing is waiting", which is
                the opposite of what is known. */}
            {canReadQueue && !stageStoodDown && !error && (
            <Table<BatchQualityQueueRow>
                scroll={{ x: 'max-content' }}
                size="small"
                rowKey="id"
                loading={isLoading}
                pagination={false}
                dataSource={rows}
                locale={{ emptyText: 'No batches waiting for a quality check.' }}
                columns={[
                    { title: 'Batch #', dataIndex: 'batch_number', render: (v: string | null) => v ?? '—' },
                    { title: 'Machine', render: (_, row) => row.work_center?.code ?? row.work_center?.name ?? '—' },
                    { title: 'Product', render: (_, row) => itemLabel(row.item) },
                    {
                        title: 'Produced (pcs)',
                        align: 'right',
                        render: (_, row) => fmtPcs(grossProducedPieces(row)),
                    },
                    {
                        // `shift_production_entries` has no completed_at column,
                        // and created_at is when START Batch opened the row — a
                        // different and misleading instant. So this is the
                        // batch's production date and shift, which are real.
                        title: 'Completed',
                        render: (_, row) => (
                            <Space direction="vertical" size={0}>
                                <span>{row.production_date ?? '—'}</span>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    {row.shift?.name ?? ''}
                                </Typography.Text>
                            </Space>
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button size="small" type="primary" onClick={() => setOpenRow(row)}>
                                Check
                            </Button>
                        ),
                    },
                ]}
            />
            )}

            <QualityCheckDrawer
                row={openRow}
                canSubmit={canSubmit}
                onClose={() => setOpenRow(null)}
                onDone={() => {
                    setOpenRow(null);
                    queryClient.invalidateQueries({ queryKey: ['quality', 'batch-quality-queue'] });
                    // The approval queues carry the check and the net figure,
                    // so they are stale the moment one is recorded.
                    queryClient.invalidateQueries({ queryKey: ['production', 'shift-production-entries'] });
                }}
            />
        </>
    );
}

function QualityCheckDrawer({
    row,
    canSubmit,
    onClose,
    onDone,
}: {
    row: BatchQualityQueueRow | null;
    canSubmit: boolean;
    onClose: () => void;
    onDone: () => void;
}) {
    const [reviewed, setReviewed] = useState<number | null>(null);
    const [rejected, setRejected] = useState<number | null>(null);
    const [ok, setOk] = useState<number | null>(null);
    // OK auto-derives from reviewed − rejected until someone types in it. Once
    // they do, their figure stands and the reconcile rule below is what tells
    // them the three no longer agree. Clearing the box resumes deriving.
    const [okTouched, setOkTouched] = useState(false);
    const [note, setNote] = useState('');
    const [submitError, setSubmitError] = useState<string | null>(null);

    // Fresh batch, fresh form — carrying a count over from the previous batch
    // is the one mistake this drawer must never allow.
    useEffect(() => {
        setReviewed(null);
        setRejected(null);
        setOk(null);
        setOkTouched(false);
        setNote('');
        setSubmitError(null);
    }, [row?.id]);

    const derivedOk = reviewed === null ? null : reviewed - (rejected ?? 0);
    const effectiveOk = okTouched ? ok : derivedOk;

    // The gross count: what the supervisor recorded. Until a check exists
    // `quantity_produced` IS the gross figure, which is the case here by
    // definition — an entry in this queue has not been checked.
    const produced = grossProducedPieces(row);

    // ---- reconcile rules, the same two the server enforces ---------------
    const problems: string[] = [];
    if (reviewed === null) problems.push('Enter how many pieces were reviewed.');
    if (rejected === null) problems.push('Enter how many pieces were rejected (0 if none).');
    if (reviewed !== null && !Number.isInteger(reviewed)) problems.push('Reviewed must be a whole number of bottles.');
    if (rejected !== null && !Number.isInteger(rejected)) problems.push('Rejected must be a whole number of bottles.');
    if (effectiveOk !== null && !Number.isInteger(effectiveOk)) problems.push('OK must be a whole number of bottles.');
    if (effectiveOk !== null && effectiveOk < 0) problems.push('OK cannot be negative — rejected is more than reviewed.');
    if (reviewed !== null && rejected !== null && effectiveOk !== null && reviewed !== effectiveOk + rejected) {
        problems.push(
            `The three must agree: OK (${effectiveOk}) + Rejected (${rejected}) must equal Reviewed (${reviewed}).`,
        );
    }
    // The one hard bound beyond the identity, and the server checks it too.
    // Production becomes gross − rejected, and that is what the PM approves and
    // what the voucher's produced line carries — rejecting more than was made
    // would post a negative. Reviewed is deliberately NOT bounded this way:
    // the owner's wording allows checking a sample rather than every piece.
    if (rejected !== null && produced !== null && rejected > produced) {
        problems.push(`Rejected (${rejected}) cannot exceed the ${produced.toLocaleString('en-IN')} pieces this batch produced.`);
    }

    const ready = problems.length === 0 && reviewed !== null && rejected !== null && effectiveOk !== null;
    const netProduction = produced !== null && rejected !== null ? produced - rejected : null;

    const mutation = useMutation({
        mutationFn: () =>
            createBatchQualityCheck(row!.id, {
                reviewed_nos: reviewed!,
                ok_nos: effectiveOk!,
                rejected_nos: rejected!,
                note: note.trim() || undefined,
            }),
        onSuccess: () => onDone(),
        onError: (err: unknown) => {
            const response = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
                ?.response?.data;
            // A 422 carries the field messages; show the first, which is the
            // one that actually names what is wrong.
            const firstFieldError = response?.errors ? Object.values(response.errors)[0]?.[0] : undefined;
            setSubmitError(
                firstFieldError ?? response?.message ?? 'Could not save the check. Nothing was recorded — try again.',
            );
        },
    });

    return (
        <Drawer
            open={row !== null}
            onClose={onClose}
            width={520}
            destroyOnHidden
            title={row ? `Quality check — ${row.batch_number ?? `batch #${row.id}`}` : 'Quality check'}
            footer={
                <Space style={{ float: 'right' }}>
                    <Button onClick={onClose}>Cancel</Button>
                    <Button
                        type="primary"
                        disabled={!ready || !canSubmit}
                        loading={mutation.isPending}
                        onClick={() => {
                            setSubmitError(null);
                            mutation.mutate();
                        }}
                    >
                        Submit check
                    </Button>
                </Space>
            }
        >
            {row && (
                <Space direction="vertical" size={16} style={{ width: '100%' }}>
                    {/* The batch's own figures, for sanity — read-only, beside
                        the inputs, so quality never counts blind. */}
                    <Descriptions size="small" column={1} bordered>
                        <Descriptions.Item label="Machine">
                            {row.work_center?.code ?? row.work_center?.name ?? '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="Product">{itemLabel(row.item)}</Descriptions.Item>
                        <Descriptions.Item label="Produced">
                            <strong>{fmtPcs(produced)}</strong> pcs
                        </Descriptions.Item>
                        <Descriptions.Item label="Rejection recorded by production">
                            {fmtKg(row.quantity_rejection_kg)}
                        </Descriptions.Item>
                    </Descriptions>

                    <div>
                        <Typography.Text strong>Reviewed (pcs)</Typography.Text>
                        <InputNumber
                            style={{ width: '100%', marginTop: 4 }}
                            min={0}
                            step={1}
                            precision={0}
                            value={reviewed}
                            onChange={(v) => setReviewed(v ?? null)}
                            placeholder="How many pieces were checked"
                        />
                    </div>

                    <div>
                        <Typography.Text strong>Rejected (pcs)</Typography.Text>
                        <InputNumber
                            style={{ width: '100%', marginTop: 4 }}
                            min={0}
                            step={1}
                            precision={0}
                            value={rejected}
                            onChange={(v) => setRejected(v ?? null)}
                            placeholder="How many were rejected"
                        />
                    </div>

                    <div>
                        <Typography.Text strong>OK (pcs)</Typography.Text>
                        <InputNumber
                            style={{ width: '100%', marginTop: 4 }}
                            min={0}
                            step={1}
                            precision={0}
                            value={effectiveOk}
                            onChange={(v) => {
                                if (v === null || v === undefined) {
                                    // Cleared — hand the box back to the
                                    // reviewed − rejected derivation.
                                    setOkTouched(false);
                                    setOk(null);
                                    return;
                                }
                                setOkTouched(true);
                                setOk(v);
                            }}
                            placeholder="Reviewed minus rejected"
                        />
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            {okTouched
                                ? 'You typed this figure. Clear the box to go back to reviewed minus rejected.'
                                : 'Filled in for you as reviewed minus rejected. You can type over it.'}
                        </Typography.Text>
                    </div>

                    <div>
                        <Typography.Text strong>Note</Typography.Text>
                        <Input.TextArea
                            style={{ marginTop: 4 }}
                            rows={2}
                            maxLength={1000}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder="What was wrong with the rejected pieces? (optional)"
                        />
                    </div>

                    {/* WHAT SUBMITTING WILL DO, in plain words, before anyone
                        commits to it. Shown only when something is actually
                        being rejected — a clean batch has no consequence to
                        warn about. */}
                    {ready && rejected !== null && rejected > 0 && (
                        <Alert
                            type="warning"
                            showIcon
                            message="This will reduce the batch"
                            description={
                                netProduction !== null ? (
                                    <>
                                        <strong>{rejected.toLocaleString('en-IN')}</strong> bottles move to rejected
                                        scrap; net production becomes{' '}
                                        <strong>{netProduction.toLocaleString('en-IN')}</strong> bottles (from{' '}
                                        {produced?.toLocaleString('en-IN')}). Rejected bottles are scrap — they are not
                                        reworked. This cannot be undone from here: to change the figures the batch has
                                        to go back to the floor.
                                    </>
                                ) : (
                                    <>
                                        <strong>{rejected.toLocaleString('en-IN')}</strong> bottles move to rejected
                                        scrap. This batch carries no produced count, so the server will work out the net
                                        figure.
                                    </>
                                )
                            }
                        />
                    )}
                    {ready && rejected === 0 && (
                        <Alert
                            type="success"
                            showIcon
                            message="Nothing rejected"
                            description="Production stays as recorded and the batch goes on to the Plant Manager unchanged."
                        />
                    )}

                    {problems.length > 0 && (
                        <Alert
                            type="info"
                            showIcon
                            message="Before you can submit"
                            description={
                                <ul style={{ margin: 0, paddingLeft: 18 }}>
                                    {problems.map((p) => (
                                        <li key={p}>{p}</li>
                                    ))}
                                </ul>
                            }
                        />
                    )}

                    {!canSubmit && (
                        <Alert
                            type="warning"
                            showIcon
                            message="You can view this queue but not record checks"
                            description="Recording a check needs the Quality manage permission."
                        />
                    )}

                    {submitError && <Alert type="error" showIcon message="Could not save" description={submitError} />}

                    <Tag color="default">Rejected pieces become scrap — never rework (factory rule).</Tag>
                </Space>
            )}
        </Drawer>
    );
}
