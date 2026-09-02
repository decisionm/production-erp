import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Descriptions, Drawer, Input, InputNumber, Space, Table, Tag, Typography } from 'antd';
import { useEffect, useState } from 'react';
import { useAuthStore } from '@/features/auth/store';
import { hasManageAccess, hasModuleAccess } from '@/features/auth/permissions';
import {
    type BatchQualityQueueRow,
    createBatchQualityCheck,
    listBatchQualityQueue,
    returnBatchToProduction,
    RETURN_REASON_MIN_LENGTH,
} from '@/features/quality/api';
import { ListNoMatch } from '@/features/quality/components/ListNoMatch';
import { grossProducedPieces, readQuantity } from '@/features/production/types';
import {
    PRODUCTION_QC_DEFAULT_SORT,
    PRODUCTION_QC_LIST,
    PRODUCTION_QC_SORT_FIELDS,
    type SortedListParams,
} from '@/features/quality/qualityLists';
import { itemLabel } from '@/lib/itemLabel';
import { ListEmpty, ListReadAlert } from '@/lib/ListEmpty';
import { compactParams } from '@/lib/listParams';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

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
 * The queue's URL keys are q / page / per_page and `sort`: its MEMBERSHIP
 * is the server's contract, not something a query string may widen; its
 * ORDER is oldest first (id breaking the tie) unless a column header asks
 * the server for another (PRODUCTION_QC_LIST, qualityLists.ts). Module-
 * level: useListParams memoises on it.
 */
const QUEUE_LIST_SPEC = PRODUCTION_QC_LIST.spec;

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
    // The desk's other answer: not "these are the numbers", but "I cannot
    // certify this — production has to look at it again".
    const [returningRow, setReturningRow] = useState<BatchQualityQueueRow | null>(null);

    // Both writes move a batch between two desks' screens, so both stale the
    // same two lists: this queue and the production/approval entry list.
    const refreshQueues = () => {
        queryClient.invalidateQueries({ queryKey: ['quality', 'batch-quality-queue'] });
        queryClient.invalidateQueries({ queryKey: ['production', 'shift-production-entries'] });
    };

    // THE URL IS THE QUEUE'S STATE (search, page, page size) and the SERVER
    // cuts the page: this screen used to walk every page of the production
    // list and filter and re-sort in the browser.
    const { params, setParams, setPage, reset } = useListParams<SortedListParams>(QUEUE_LIST_SPEC);
    const request = compactParams(params);

    const { data, isLoading, isPending, isError, error, refetch } = useQuery({
        queryKey: ['quality', 'batch-quality-queue', request],
        queryFn: () => listBatchQualityQueue(params),
        enabled: canView && canReadQueue,
        retry: false,
        placeholderData: (previous) => previous,
    });

    const status = (error as { response?: { status?: number } } | null)?.response?.status;
    const forbidden = status === 403;
    // The server's order IS the queue's order — oldest first, or the column
    // the URL's sort names — so a page is a slice of the queue, never a
    // re-sort of one.
    const rows = data?.data ?? [];
    // The factory has stood the quality stage down (PROD_QUALITY_STAGE_ENABLED
    // =false). The server says so in meta, because no row can; saying it is
    // the difference between "nothing to do" and "this screen is not in use".
    const stageStoodDown = data?.meta.stage_enabled === false;

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

            {!canReadQueue && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="You can record checks, but not list the queue"
                    description="The waiting-batches list is served by the production module, so reading it needs Production view permission on top of Quality. Ask an administrator to add it — until then this page cannot show you what is waiting."
                />
            )}

            {forbidden && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="Not allowed to read the batch list"
                    description="Reading the waiting batches needs Production view permission alongside Quality."
                />
            )}

            {stageStoodDown && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="The quality stage is switched off"
                    description={`Completed batches are going straight to the Plant Manager, as they did before this stage existed — ${data?.meta.pending_count ?? 0} are waiting for approval right now. Nothing needs checking here until the stage is switched back on.`}
                />
            )}

            {/* No table at all on a 403 — an empty grid under a banner saying
                the list may not be read would read as "nothing is waiting".
                Every OTHER read state is the table's own: a failed first
                read shows the failure and Try again where the rows would be
                (ListEmpty), and a failed REFETCH keeps the stale rows and
                says so above them (ListReadAlert) — "no batches waiting" is
                shown only when the server actually said so. */}
            {canReadQueue && !stageStoodDown && !forbidden && (
            <>
            <Space wrap style={{ marginBottom: 12 }}>
                {/* Keyed on the term so Clear (reset) empties the box; submits
                    on Enter, the button, or its own clear cross. */}
                <Input.Search
                    key={params.q ?? ''}
                    allowClear
                    defaultValue={params.q}
                    placeholder="Search batch, product, machine"
                    onSearch={(value) => setParams({ q: value.trim() || undefined })}
                    style={{ width: 280 }}
                />
                {params.q && data?.meta && (
                    <>
                        <Typography.Text type="secondary">{`${data.meta.total} match`}</Typography.Text>
                        <Button size="small" type="link" style={{ padding: 0 }} onClick={reset}>
                            Clear
                        </Button>
                    </>
                )}
            </Space>

            <ListReadAlert state={{ isPending, isError, error, refetch }} entity="the quality queue" />

            <Table<BatchQualityQueueRow>
                scroll={{ x: 'max-content' }}
                sticky={TABLE_STICKY}
                size="small"
                rowKey="id"
                loading={isLoading}
                // SORTED BY THE SERVER: every sorter is sortOrder-controlled
                // and re-queries the whole queue; sorting the loaded page
                // would misorder the rest of it.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, PRODUCTION_QC_SORT_FIELDS, PRODUCTION_QC_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'batches')}
                dataSource={rows}
                locale={{
                    emptyText: (
                        <ListEmpty
                            state={{ isPending, isError, error, refetch }}
                            entity="the quality queue"
                            empty={
                                params.q
                                    ? <ListNoMatch entity="batches" term={params.q} onClear={reset} />
                                    : 'No batches waiting for a quality check.'
                            }
                        />
                    ),
                }}
                columns={[
                    {
                        title: 'Batch #',
                        key: 'batch_number',
                        dataIndex: 'batch_number',
                        sorter: true,
                        sortOrder: columnSortOrder('batch_number', params.sort, PRODUCTION_QC_DEFAULT_SORT),
                        render: (v: string | null) => v ?? '—',
                    },
                    { title: 'Machine', render: (_, row) => row.work_center?.code ?? row.work_center?.name ?? '—' },
                    { title: 'Product', render: (_, row) => itemLabel(row.item) },
                    {
                        // An unchecked batch's quantity_produced IS its gross
                        // figure (no check has reduced it yet), so the server
                        // sorts on that column and the order matches the cell.
                        title: 'Produced (pcs)',
                        key: 'quantity_produced',
                        align: 'right',
                        sorter: true,
                        sortOrder: columnSortOrder('quantity_produced', params.sort, PRODUCTION_QC_DEFAULT_SORT),
                        render: (_, row) => fmtPcs(grossProducedPieces(row)),
                    },
                    {
                        // `shift_production_entries` has no completed_at column,
                        // and created_at is when START Batch opened the row — a
                        // different and misleading instant. So this is the
                        // batch's production date and shift, which are real.
                        title: 'Completed',
                        key: 'production_date',
                        sorter: true,
                        sortOrder: columnSortOrder('production_date', params.sort, PRODUCTION_QC_DEFAULT_SORT),
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
                            // Two doors out of this queue, side by side, because
                            // they are the two honest answers to the same batch:
                            // record the count, or send it back with the reason.
                            // Returning is not the primary one — most batches are
                            // checked, not returned — but it must never be hidden
                            // behind the check drawer, or a checker with a bad
                            // batch has only one button and it is the wrong one.
                            <Space size={8} wrap>
                                <Button size="small" type="primary" onClick={() => setOpenRow(row)}>
                                    Check
                                </Button>
                                <Button size="small" onClick={() => setReturningRow(row)}>
                                    Return to production
                                </Button>
                            </Space>
                        ),
                    },
                ]}
            />
            </>
            )}

            <QualityCheckDrawer
                row={openRow}
                canSubmit={canSubmit}
                onClose={() => setOpenRow(null)}
                onDone={() => {
                    setOpenRow(null);
                    // The approval queues carry the check and the net figure,
                    // so they are stale the moment one is recorded.
                    refreshQueues();
                }}
            />

            <ReturnToProductionDrawer
                row={returningRow}
                canSubmit={canSubmit}
                onClose={() => setReturningRow(null)}
                onDone={() => {
                    setReturningRow(null);
                    // The batch has to LEAVE this queue on the next read — it is
                    // production's now, and the server's queue predicate drops
                    // it the same way its `correction.awaiting_correction` flag
                    // would (whereAwaitingQualityCheck).
                    refreshQueues();
                }}
            />
        </>
    );
}

/**
 * Sending a batch back to the floor. One required field, because there is only
 * one thing the floor needs from this desk: what to correct.
 *
 * Deliberately a separate drawer from the check rather than a tab inside it.
 * The two actions have opposite consequences and share no input, and a return
 * reached through a half-filled check form is a return typed while looking at
 * counts that are about to be thrown away.
 */
function ReturnToProductionDrawer({
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
    const [reason, setReason] = useState('');
    const [submitError, setSubmitError] = useState<string | null>(null);

    // Fresh batch, fresh reason — the previous batch's complaint must never
    // travel to this one's supervisor.
    useEffect(() => {
        setReason('');
        setSubmitError(null);
    }, [row?.id]);

    const trimmed = reason.trim();
    const reasonOk = trimmed.length >= RETURN_REASON_MIN_LENGTH;

    const mutation = useMutation({
        mutationFn: () => returnBatchToProduction(row!.id, trimmed),
        onSuccess: () => onDone(),
        onError: (err: unknown) => {
            const response = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
                ?.response?.data;
            const firstFieldError = response?.errors ? Object.values(response.errors)[0]?.[0] : undefined;
            setSubmitError(
                firstFieldError
                    ?? response?.message
                    ?? 'Could not send the batch back. Nothing was changed — try again.',
            );
        },
    });

    return (
        <Drawer
            open={row !== null}
            onClose={onClose}
            width="min(100vw, 520px)"
            destroyOnHidden
            title={row ? `Return to production — ${row.batch_number ?? `batch #${row.id}`}` : 'Return to production'}
            footer={
                <Space style={{ float: 'right' }}>
                    <Button onClick={onClose}>Cancel</Button>
                    <Button
                        danger
                        type="primary"
                        disabled={!reasonOk || !canSubmit}
                        loading={mutation.isPending}
                        onClick={() => {
                            setSubmitError(null);
                            mutation.mutate();
                        }}
                    >
                        Send back to production
                    </Button>
                </Space>
            }
        >
            {row && (
                <Space direction="vertical" size={16} style={{ width: '100%' }}>
                    <Descriptions size="small" column={1} bordered>
                        <Descriptions.Item label="Machine">
                            {row.work_center?.code ?? row.work_center?.name ?? '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="Product">{itemLabel(row.item)}</Descriptions.Item>
                        <Descriptions.Item label="Produced">
                            <strong>{fmtPcs(grossProducedPieces(row))}</strong> pcs
                        </Descriptions.Item>
                    </Descriptions>

                    <div>
                        <Typography.Text strong>
                            What does production have to correct? <span style={{ color: '#cf1322' }}>*</span>
                        </Typography.Text>
                        <Input.TextArea
                            style={{ marginTop: 4 }}
                            rows={4}
                            maxLength={1000}
                            autoFocus
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="e.g. Carton count is 40 but only 38 cartons are on the pallet — recount and re-enter."
                        />
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            The supervisor sees this sentence and nothing else, so name the figure that is wrong.
                            {!reasonOk && trimmed.length > 0
                                ? ` At least ${RETURN_REASON_MIN_LENGTH} characters.`
                                : ''}
                        </Typography.Text>
                    </div>

                    <Alert
                        type="warning"
                        showIcon
                        message="This batch leaves the quality queue"
                        description="It goes back to the floor for correction and comes back here for a fresh check once production has re-entered its figures. The Plant Manager cannot approve it in the meantime."
                    />

                    {!canSubmit && (
                        <Alert
                            type="warning"
                            showIcon
                            message="You can view this queue but not return batches"
                            description="Sending a batch back needs the Quality manage permission."
                        />
                    )}

                    {submitError && (
                        <Alert type="error" showIcon message="Could not send it back" description={submitError} />
                    )}
                </Space>
            )}
        </Drawer>
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
            width="min(100vw, 520px)"
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
