import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, message, Modal, Select, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { hasManageAccess } from '@/features/auth/permissions';
import { showApiError } from '@/lib/showApiError';
import { useAuthStore } from '@/features/auth/store';
import { attachStandardItem, getConfigurationReview, setPackagingIdentity } from '@/features/production/api';
import {
    PACKING_MODE_LABEL,
    missingWords,
    packagingCountsSummary,
    tallyIdentityLabel,
} from '@/features/production/productStandardsConfig';
import type {
    ConfigurationReviewCandidate,
    ConfigurationReviewFixTarget,
    ConfigurationReviewKind,
    ConfigurationReviewRow,
} from '@/features/production/types';

/**
 * NEEDS REVIEW — everything in the product configuration that still waits
 * on a person (Phase 5, P5-03), fed by GET production/configuration/review.
 *
 * Three kinds of row arrive: a packing (or, when no packing inherits it, a
 * standard) whose resolved Tally identity is missing — null, GUID-less or a
 * local fixture (DEC-20260810-003 made each packing's identity its own
 * question); a packing whose identity's NAME is carried by more than one
 * items row (so the name cannot say which); and an item still on the SKU
 * the Tally pull seeded from its name.
 *
 * ## What this panel refuses to do
 *
 *  - **Pick.** The server offers candidates matched by exact / normalised
 *    name — Tally-pulled rows only, never a local fixture. They are a
 *    shortlist to read; the select starts empty even when there is exactly
 *    one, and nothing is written until a person chooses and presses Link.
 *    An ambiguous SKU→Tally mapping is the owner's to settle, not this
 *    screen's to guess.
 *  - **Create.** There is no "make a Tally item" here and never will be: the
 *    ERP links an EXISTING Tally item, through the endpoints that already
 *    exist — the packaging's identity-only PATCH (item_id and nothing
 *    else), or the standard's attach-item when the row is about the
 *    product's own identity (`fix_target`). A product Tally has never heard
 *    of is reported as such — the row stays until Tally carries it.
 *  - **Move a count.** Link sends `{ item_id }` alone. It used to send the
 *    row's mode and inner counts through the full PUT, and the server
 *    re-derived the box count from them — which quietly turned the sheet's
 *    520 into 525 on a row the importer had deliberately left inconsistent
 *    ("confirm which is correct"). Identity is independent of counts: a
 *    packing whose counts are still open links all the same, and its counts
 *    stay exactly as stored.
 *  - **Resolve a shared name.** A `packaging_ambiguous` row is ADVISORY
 *    (`fix_target: name_ambiguity`): more than one catalogue row carries
 *    the identity's NAME, and Tally matches a voucher line by name, so
 *    linking any of them clears nothing. The panel names the duplicate and
 *    offers no Link — the catalogue question is the owner's (Q43).
 *  - **Name a SKU.** A provisional-SKU row says the SKU is provisional and
 *    where it is set; what it should become is the SKU format programme's
 *    answer, which is held by the owner.
 *
 * On a backend that predates the endpoint (404) the panel renders nothing,
 * so the workspace it sits in is unchanged for it.
 */

const KIND_LABEL: Record<ConfigurationReviewKind, string> = {
    packaging_no_identity: 'Packing has no Tally identity',
    packaging_ambiguous: 'Tally item name is carried by more than one item',
    item_provisional_sku: 'SKU is provisional (seeded from the Tally name)',
};

const KIND_COLOUR: Record<ConfigurationReviewKind, string> = {
    packaging_no_identity: 'orange',
    packaging_ambiguous: 'gold',
    item_provisional_sku: 'purple',
};

/** A stable key for a row: the packaging, or the item, or the kind — the server sends no id of its own. */
const rowKey = (r: ConfigurationReviewRow): string =>
    `${r.kind}:${r.standard?.id ?? 's'}:${r.packaging?.id ?? 'p'}:${r.item?.id ?? 'i'}`;

const candidateLabel = (c: ConfigurationReviewCandidate): string =>
    `${tallyIdentityLabel(c)}${c.guid ? '' : ' (not in Tally)'}`;

/**
 * Which existing endpoint closes a row. The server names it (`fix_target`);
 * an older payload without the key is read from the row's own shape — a
 * shared-name row is advisory, a packaging present means the identity
 * PATCH, a standard alone means attach-item, an item alone means the
 * item's SKU. An older server that still says `packaging_item` on an
 * ambiguous row is read as advisory too: linking never clears a shared name.
 */
const fixTargetOf = (r: ConfigurationReviewRow): ConfigurationReviewFixTarget => {
    if (r.kind === 'packaging_ambiguous') return 'name_ambiguity';
    if (r.fix_target) return r.fix_target;
    if (r.kind === 'item_provisional_sku') return 'item_sku';
    if (r.packaging) return 'packaging_item';
    return 'attach_item';
};

export default function ConfigurationReviewPanel({
    onFindInTable,
}: {
    /** Show the product this row is about in the table below — the drawer's full editor is one click from there. */
    onFindInTable?: (product: string) => void;
}) {
    const queryClient = useQueryClient();
    const user = useAuthStore((s) => s.user);
    const canManage = hasManageAccess(user, 'production');

    const [open, setOpen] = useState(true);
    // Chosen candidate per row, keyed by rowKey — never pre-filled.
    const [chosen, setChosen] = useState<Record<string, number | undefined>>({});

    const review = useQuery({
        queryKey: ['production', 'configuration', 'review'],
        queryFn: getConfigurationReview,
        // A 404 is "older backend", not a transient — retrying it would only
        // delay the quiet answer (render nothing).
        retry: (count, error: any) => error?.response?.status !== 404 && count < 2,
    });

    const invalidate = () => {
        // The workspace rows, this list, and the Start Batch preview all
        // read the identities — every cache is wrong the moment one changes.
        queryClient.invalidateQueries({ queryKey: ['production', 'standards'] });
        queryClient.invalidateQueries({ queryKey: ['production', 'configuration', 'review'] });
        queryClient.invalidateQueries({ queryKey: ['production', 'batch-preview'] });
    };

    // The backend's refusals name the reason (an inactive item, a local-only
    // fixture, a twin packing, a re-point without confirmation) — shown
    // verbatim, under the field key each one belongs to.
    const showRefusal = (error: unknown, title: string) => showApiError(error, title);

    const linkPackaging = useMutation({
        // Identity ONLY — `{ item_id }` to the identity route, never a mode
        // or a count. The row's stored counts (consistent or not) are left
        // exactly as the importer or a person wrote them.
        mutationFn: ({ row, itemId }: { row: ConfigurationReviewRow; itemId: number }) =>
            setPackagingIdentity(row.standard!.id, row.packaging!.id, itemId),
        onSuccess: () => {
            invalidate();
            message.success('Linked — every future batch packed this way posts under this Tally item.');
        },
        onError: (error: any) => showRefusal(error, 'Could not link this Tally item'),
    });

    const attachProduct = useMutation({
        // A standard that already points at an item (a fixture, or one Tally
        // has no GUID for) makes this a RE-POINT — a confirmed act
        // (DEC-20260810-003), and the backend refuses it without the flag.
        mutationFn: ({ row, itemId }: { row: ConfigurationReviewRow; itemId: number }) =>
            attachStandardItem(row.standard!.id, itemId, row.item !== null),
        onSuccess: () => {
            invalidate();
            message.success('Attached — every future run of this product posts under this Tally item.');
        },
        onError: (error: any) => showRefusal(error, 'Could not attach this Tally item'),
    });

    const link = (row: ConfigurationReviewRow, itemId: number) => {
        if (fixTargetOf(row) === 'attach_item') {
            if (row.item !== null) {
                Modal.confirm({
                    title: 'Change the Tally identity of this product?',
                    okText: 'Change the identity',
                    okButtonProps: { danger: true },
                    content: `Currently attached to "${row.item.name}". Changing it re-points every FUTURE run of this product at the new item. Completed batches and vouchers already posted keep the identity they recorded — history is never rewritten. The change is noted on the standard with your name.`,
                    // The refusal, if any, is already shown by onError; the
                    // confirm closes either way rather than hanging open.
                    onOk: () => attachProduct.mutateAsync({ row, itemId }).then(() => undefined, () => undefined),
                });
                return;
            }
            attachProduct.mutate({ row, itemId });
            return;
        }
        linkPackaging.mutate({ row, itemId });
    };

    if (review.isPending) return null;

    if (review.isError) {
        const status = (review.error as any)?.response?.status;
        // Older backend without the review endpoint: the workspace is
        // unchanged for it, and a warning about a feature it does not have
        // would only be noise.
        if (status === 404) return null;
        return (
            <Alert
                type="warning"
                showIcon
                style={{ marginBottom: 16 }}
                message="Could not load the configuration review"
                description="The list of packings and items still waiting on a person did not load. The table below is unaffected."
                action={
                    <Button size="small" onClick={() => review.refetch()}>
                        Retry
                    </Button>
                }
            />
        );
    }

    const rows = review.data?.rows ?? [];

    if (rows.length === 0) {
        return (
            <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 12, fontSize: 12 }}>
                Configuration review: nothing is waiting on a person — every packing resolves to a Tally item, no
                identity name is shared, and no item is on a provisional SKU.
            </Typography.Text>
        );
    }

    const configRows = rows.filter((r) => r.kind !== 'item_provisional_sku').length;
    const itemRows = rows.length - configRows;
    const headline = [
        configRows > 0 ? `${configRows} packing identit${configRows === 1 ? 'y' : 'ies'}` : null,
        itemRows > 0 ? `${itemRows} provisional SKU${itemRows === 1 ? '' : 's'}` : null,
    ]
        .filter(Boolean)
        .join(' and ');

    const busy = linkPackaging.isPending || attachProduct.isPending;
    const busyRow = linkPackaging.isPending ? linkPackaging.variables?.row : attachProduct.variables?.row;

    return (
        <Alert
            type="warning"
            showIcon
            style={{ marginBottom: 16 }}
            message={`Needs review — ${headline} still waiting on a person`}
            action={
                <Button size="small" onClick={() => setOpen((v) => !v)}>
                    {open ? 'Hide' : 'Show'}
                </Button>
            }
            description={
                open ? (
                    <div style={{ overflowX: 'auto', marginTop: 8 }}>
                        <Table<ConfigurationReviewRow>
                            rowKey={rowKey}
                            size="small"
                            pagination={rows.length > 10 ? { pageSize: 10, size: 'small' } : false}
                            dataSource={rows}
                            columns={[
                                {
                                    title: 'What',
                                    width: 300,
                                    render: (_, r) => (
                                        <Space size={4} wrap>
                                            <Tag color={KIND_COLOUR[r.kind]} style={{ marginInlineEnd: 0 }}>
                                                {r.kind === 'packaging_no_identity' && r.packaging === null
                                                    ? 'Product has no Tally identity'
                                                    : (KIND_LABEL[r.kind] ?? r.kind)}
                                            </Tag>
                                            {r.ambiguity && (
                                                <Tooltip title="Tally matches a voucher line by NAME, so which of these rows it would credit cannot be told from the name alone.">
                                                    <Tag style={{ marginInlineEnd: 0 }}>{r.ambiguity.shared_name_count} rows share the name</Tag>
                                                </Tooltip>
                                            )}
                                        </Space>
                                    ),
                                },
                                {
                                    title: 'Product / packing',
                                    width: 300,
                                    render: (_, r) => {
                                        const product = r.standard?.product ?? r.item?.name ?? '—';
                                        return (
                                            <Space direction="vertical" size={0}>
                                                <Space size={6}>
                                                    <Typography.Text strong>{product}</Typography.Text>
                                                    {onFindInTable && r.standard && (
                                                        <Button
                                                            type="link"
                                                            size="small"
                                                            style={{ padding: 0, height: 'auto', fontSize: 12 }}
                                                            onClick={() => onFindInTable(r.standard!.product)}
                                                        >
                                                            find in table
                                                        </Button>
                                                    )}
                                                </Space>
                                                {r.packaging ? (
                                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                        {PACKING_MODE_LABEL[r.packaging.mode] ?? r.packaging.mode} ·{' '}
                                                        {packagingCountsSummary(r.packaging.mode, r.packaging.counts)}
                                                    </Typography.Text>
                                                ) : r.standard ? (
                                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                        the product&rsquo;s own item — every packing of this standard posts as it
                                                    </Typography.Text>
                                                ) : null}
                                            </Space>
                                        );
                                    },
                                },
                                {
                                    title: 'Currently',
                                    width: 240,
                                    render: (_, r) =>
                                        r.kind === 'item_provisional_sku' ? (
                                            <Space size={6} wrap>
                                                <Typography.Text style={{ fontFamily: 'monospace' }}>{r.item?.sku ?? '—'}</Typography.Text>
                                                <Tag color="purple">provisional SKU</Tag>
                                            </Space>
                                        ) : (
                                            <Typography.Text type={r.item ? undefined : 'secondary'}>
                                                {tallyIdentityLabel(r.item)}
                                            </Typography.Text>
                                        ),
                                },
                                {
                                    title: 'Missing',
                                    width: 200,
                                    render: (_, r) => {
                                        const words = missingWords(r.missing ?? []);
                                        return words === '' ? (
                                            <Typography.Text type="secondary">—</Typography.Text>
                                        ) : (
                                            <Typography.Text>{words}</Typography.Text>
                                        );
                                    },
                                },
                                {
                                    title: 'Link an existing Tally item',
                                    render: (_, r) => {
                                        const target = fixTargetOf(r);

                                        if (target === 'item_sku') {
                                            return (
                                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                    Set the SKU on the item master
                                                    {r.item ? (
                                                        <>
                                                            {' '}
                                                            (<Link to={`/inventory/items/${r.item.id}`}>open item</Link>)
                                                        </>
                                                    ) : null}
                                                    ; the format is the owner&rsquo;s programme, so nothing here proposes
                                                    one.
                                                </Typography.Text>
                                            );
                                        }
                                        if (target === 'name_ambiguity') {
                                            const n = r.ambiguity?.shared_name_count;
                                            return (
                                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                    A catalogue duplicate — {n ? `${n} items` : 'more than one item'} carry this
                                                    name, and Tally matches a voucher line by name, so linking any one of them
                                                    does not clear it. The duplicate is settled in the catalogue (Q43 — whether
                                                    it blocks or warns is the owner&rsquo;s call); nothing here picks a row.
                                                </Typography.Text>
                                            );
                                        }
                                        if (!r.standard || (target === 'packaging_item' && !r.packaging)) {
                                            return <Typography.Text type="secondary">—</Typography.Text>;
                                        }
                                        const key = rowKey(r);
                                        const candidates = r.candidates ?? [];
                                        if (candidates.length === 0) {
                                            return (
                                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                    No Tally item matches this name. Tally has to carry the product
                                                    first — the ERP never creates one. Open the product to search the
                                                    whole catalogue.
                                                </Typography.Text>
                                            );
                                        }
                                        const picked = chosen[key];
                                        const verb = target === 'attach_item' ? 'Attach' : 'Link';
                                        return (
                                            <Space size={6} wrap>
                                                <Select
                                                    size="small"
                                                    style={{ minWidth: 280 }}
                                                    placeholder={`Choose one of ${candidates.length} name match${candidates.length === 1 ? '' : 'es'}…`}
                                                    value={picked}
                                                    allowClear
                                                    showSearch
                                                    optionFilterProp="label"
                                                    disabled={!canManage}
                                                    onChange={(v) => setChosen((c) => ({ ...c, [key]: v }))}
                                                    options={candidates.map((c) => ({ value: c.id, label: candidateLabel(c) }))}
                                                />
                                                <Tooltip
                                                    title={
                                                        !canManage
                                                            ? 'Needs the production.manage permission.'
                                                            : picked === undefined
                                                              ? 'Choose the Tally item first — nothing is picked for you.'
                                                              : undefined
                                                    }
                                                >
                                                    <Button
                                                        size="small"
                                                        type="primary"
                                                        disabled={!canManage || picked === undefined || busy}
                                                        loading={busy && busyRow === r}
                                                        onClick={() => picked !== undefined && link(r, picked)}
                                                    >
                                                        {verb}
                                                    </Button>
                                                </Tooltip>
                                            </Space>
                                        );
                                    },
                                },
                            ]}
                        />
                        <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block', marginTop: 6 }}>
                            Candidates are matched by name from the active Tally catalogue only. Linking sets the identity
                            and nothing else — a packing&rsquo;s counts stay exactly as stored — and changes future batches;
                            posted vouchers keep the identity they were posted under.
                        </Typography.Text>
                    </div>
                ) : undefined
            }
        />
    );
}
