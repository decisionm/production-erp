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
    SEPARATE_PRODUCT_REQUIRED_DETAIL,
    missingWords,
    packagingCountsSummary,
    tallyIdentityLabel,
    tallyIdentityLabelMarkingArchived,
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
 * Four kinds of row arrive: a packing whose own Tally identity names a
 * DIFFERENT item from the product it sits under, which under DEC-20260821-001
 * makes it a separate finished product; a packing (or, when no packing
 * inherits it, a standard) whose resolved Tally identity is missing — null,
 * GUID-less or a local fixture (DEC-20260810-003 made each packing's identity
 * its own question); a packing whose identity's NAME is carried by more than
 * one items row (so the name cannot say which); and an item still on the SKU
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
 *  - **Split a product, or un-split one.** A `packaging_separate_product`
 *    row (`fix_target: separate_product`, DEC-20260821-001) is the packing
 *    that posts as its OWN Tally stock item while sitting under another
 *    product. It offers no Link — the thing that closes it is a separate
 *    PRODUCT: the Tally stock item pulled into the catalogue (only the
 *    masters pull puts it there; an item made by hand carries no GUID and
 *    cannot post), a production standard created for or attached to it, and
 *    the packing configured under that product. It also never CLEARS the
 *    identity — not because that column is the only record of what already
 *    posted (it is not: a completed run froze its own identity on the entry,
 *    and the queued voucher's payload and event trail are built from that
 *    frozen column, never from this one) but because it is the CURRENT
 *    configuration's evidence, and this panel is advisory: it rewrites
 *    neither the configuration nor posted history. Evidence, not a refusal —
 *    the guard that stops NEW ones of these lives on the write endpoints and
 *    at Start Batch, and nothing already recorded is undone by this screen.
 *
 * An `item` (or `product_item`) on a separate-product row may name an
 * ARCHIVED catalogue row: the packing is active and its identity is set, and
 * only the item it points at has since been retired. The server resolves it
 * anyway, because the finding is about the stored column and "posts as no
 * Tally identity" over an identity that is plainly set would be false — and
 * it says the retirement out loud (`archived`), worn here as an "(archived)"
 * marker on the label. The marker is the row's own honesty, not a nicety:
 * the coexisting `packaging_no_identity` row also says the packing cannot
 * post today, but past ten rows the table paginates and that row can sit on
 * a page the reader never opens — "posts as sku · name" alone would then be
 * the panel's only claim, and a false one.
 *
 * On a backend that predates the endpoint (404) the panel renders nothing,
 * so the workspace it sits in is unchanged for it.
 */

const KIND_LABEL: Record<ConfigurationReviewKind, string> = {
    packaging_separate_product: 'Packing belongs under a separate product',
    packaging_no_identity: 'Packing has no Tally identity',
    packaging_ambiguous: 'Tally item name is carried by more than one item',
    item_provisional_sku: 'SKU is provisional (seeded from the Tally name)',
};

const KIND_COLOUR: Record<ConfigurationReviewKind, string> = {
    // Red, alone among the four: the others are gaps still to be filled in,
    // this one says the row is configured against the wrong product.
    packaging_separate_product: 'red',
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
 *
 * THE TWO ADVISORY KINDS ARE DECIDED BY `kind`, BEFORE `fix_target` IS READ,
 * and that ordering is the guarantee rather than a tidiness. A row whose
 * payload said `fix_target: 'packaging_item'` — an older server, a proxy, a
 * hand-rolled response — would otherwise render a Select and a Link button
 * on a row where every link this screen can make is the write
 * DEC-20260821-001 withdrew the authority for. Reading the kind first makes
 * "no Link on a separate-product row" a property of the code, not of the
 * data it is handed.
 */
export const fixTargetOf = (r: ConfigurationReviewRow): ConfigurationReviewFixTarget => {
    if (r.kind === 'packaging_ambiguous') return 'name_ambiguity';
    if (r.kind === 'packaging_separate_product') return 'separate_product';
    if (r.fix_target) return r.fix_target;
    if (r.kind === 'item_provisional_sku') return 'item_sku';
    if (r.packaging) return 'packaging_item';
    return 'attach_item';
};

/**
 * WHICH WRITE A LINK ON THIS ROW ACTUALLY PERFORMS — the whole save decision,
 * as data, before anything is sent.
 *
 * Extracted 24-Aug-2026 because the owner asked the right question: "we need
 * to check if we change those are getting saved". The backend PATCH was
 * covered twice over (PackagingIdentityOnlyTest, PackagingTallyIdentityTest
 * assert the row persists and no count moves), and the CELL's rendering was
 * covered — but the step between them, choosing WHICH endpoint gets WHICH
 * ids, was asserted nowhere. That is the step where a save silently goes to
 * the wrong place, and it cannot be tested through a click: this repo's
 * vitest runs in node with no DOM.
 *
 * So the decision is a pure function and the component executes what it
 * returns. One definition, provable per row shape.
 *
 * `none` is not a failure — it is the honest answer for a row that offers no
 * link at all (a separate-product row, an ambiguous name, a provisional SKU).
 * Those are handled before any control renders; returning a plan for them
 * would invent a write nobody offered.
 */
export type LinkPlan =
    | { endpoint: 'packaging_identity'; standardId: number; packagingId: number; itemId: number }
    | { endpoint: 'attach_item'; standardId: number; itemId: number; confirmRepoint: boolean }
    | { endpoint: 'none' };

export function linkPlanFor(row: ConfigurationReviewRow, itemId: number): LinkPlan {
    const target = fixTargetOf(row);

    if (target === 'attach_item') {
        if (row.standard === null) return { endpoint: 'none' };

        // A standard already pointing at an item makes this a RE-POINT, which
        // the backend refuses without the flag (DEC-20260810-003) — and which
        // the UI must confirm first. One condition drives both.
        return {
            endpoint: 'attach_item',
            standardId: row.standard.id,
            itemId,
            confirmRepoint: row.item !== null,
        };
    }

    if (target === 'packaging_item') {
        if (row.standard === null || row.packaging === null) return { endpoint: 'none' };

        return {
            endpoint: 'packaging_identity',
            standardId: row.standard.id,
            packagingId: row.packaging.id,
            itemId,
        };
    }

    return { endpoint: 'none' };
}

/**
 * THE ALERT'S ONE-LINE COUNT — pure for the same reason linkPlanFor() is.
 *
 * Separate-product rows used to be folded into "N packing identities still
 * waiting on a person", which was wrong twice over: their identity is not
 * missing (the identity IS the finding), and one packing can raise a
 * separate-product row AND a no-identity row AND an ambiguity row at once —
 * the kinds deliberately coexist — so a single packing could read as three
 * waiting identities on the panel's one always-visible line. They are their
 * own segment now, named for what the kind says (the packing is configured
 * under the wrong product). And the identity count counts IDENTITIES, not
 * rows: a no-identity row and a shared-name row about the same packing are
 * two questions about one unsettled identity, deduped by the identity they
 * ask about.
 */
export function reviewHeadline(rows: readonly ConfigurationReviewRow[]): string {
    const separate = rows.filter((r) => r.kind === 'packaging_separate_product').length;
    const skus = rows.filter((r) => r.kind === 'item_provisional_sku').length;

    // IDENTITIES, NOT ROWS. A no-identity row and a shared-name row about
    // the SAME packing are two questions about ONE unsettled identity — the
    // kinds deliberately coexist — and counting rows announced "2 packing
    // identities" over a single packing. Deduped by the identity the row
    // asks about: the packing's own, or the standard's when no packaging
    // carries the question.
    const identities = new Set(
        rows
            .filter((r) => r.kind === 'packaging_no_identity' || r.kind === 'packaging_ambiguous')
            .map((r) => `${r.standard?.id ?? 's'}:${r.packaging?.id ?? 'p'}`),
    ).size;

    const parts = [
        separate > 0 ? `${separate} packing${separate === 1 ? '' : 's'} under the wrong product` : null,
        identities > 0 ? `${identities} packing identit${identities === 1 ? 'y' : 'ies'}` : null,
        skus > 0 ? `${skus} provisional SKU${skus === 1 ? '' : 's'}` : null,
    ].filter((part): part is string => part !== null);

    if (parts.length <= 1) return parts[0] ?? '';

    return `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`;
}

/**
 * THE ACTION CELL — what, if anything, a person may do about one row.
 *
 * Lifted out of the table column unchanged so it can be called as a plain
 * function and its returned element tree walked (no DOM in this repo's test
 * setup; `App.routes.test.tsx` is the precedent). Hook-free and entirely
 * prop-driven for that reason: everything stateful — the chosen candidate,
 * the pending mutation, the permission — is passed in.
 *
 * That testability is the point. "A separate-product row never renders a
 * Link or Attach control" is a claim about rendering, so it is pinned by
 * reading the rendering, not by trusting the branch above it.
 */
export function ConfigurationReviewFixCell({
    row: r,
    canManage,
    picked,
    onPick,
    busy,
    isBusyRow,
    onLink,
}: {
    row: ConfigurationReviewRow;
    canManage: boolean;
    /** The candidate a person has chosen on THIS row, if any — never pre-filled. */
    picked: number | undefined;
    onPick: (itemId: number | undefined) => void;
    /** Any link/attach in flight (every row's control is disabled while one is). */
    busy: boolean;
    /** ...and whether it is this row's, which is the one that shows the spinner. */
    isBusyRow: boolean;
    onLink: (itemId: number) => void;
}) {
    const target = fixTargetOf(r);

    // DEC-20260821-001. FIRST, and with no path from here to a control: the
    // packing posts as its own Tally stock item, so it is a separate finished
    // product, and there is nothing on this screen to link it to. Not a
    // refusal either — the row already exists, and any voucher it has already
    // posted keeps the identity it was posted under. `candidates` is empty
    // from the server, and this branch returns before any candidate could be
    // read, so a payload that carried some anyway still offers none.
    if (target === 'separate_product') {
        // ONE WORDING, TRUE IN EVERY STATE — deliberately not conditional.
        // The old sentence ("nothing here links it: the item that closes
        // this is a separate PRODUCT") overclaimed in the migration-window
        // state, where attaching the product's real item can make the two
        // ends equal and close the row without any split. A first fix keyed
        // a softer sentence on "an attach row for this standard sits in this
        // same list", and an adversarial pass broke that in both directions:
        // the attach row's existence neither implies an attach could close
        // this row (a sibling packing with its own different identity —
        // trashed ones included — makes the backend refuse every attach; an
        // archived identity cannot be attached at all) nor is it implied by
        // it (a sibling that INHERITS carries the product's gap on its own
        // row, so the attach row vanishes while the drawer's attach still
        // closes this one). No signal this panel holds tracks the truth, so
        // the cell states only what is true everywhere: nothing links from
        // THIS row; a genuine two-item split is closed by a separate
        // product; and the verdict is re-judged whenever the product end
        // changes — the arithmetic, never a promise that any particular
        // attach will be accepted (the writers guard that, and their
        // refusals name the reason).
        return (
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                {SEPARATE_PRODUCT_REQUIRED_DETAIL} Nothing on this row links it: if Tally truly carries two stock
                items, the one that closes this is a separate PRODUCT, which this screen does not create. The
                verdict is re-judged from the stored columns on every read — while the product this packing sits
                under still posts as a placeholder, attaching that product&rsquo;s real Tally item re-asks the
                question, and the same item at both ends is one product, not two. This review is advisory — it
                changes neither the configuration you see nor anything already posted. The identity is left
                exactly as it is; completed runs keep the identity they froze at completion, queued and posted
                vouchers are unaffected, and history is never rewritten.
            </Typography.Text>
        );
    }
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
                ; the format is the owner&rsquo;s programme, so nothing here proposes one.
            </Typography.Text>
        );
    }
    if (target === 'name_ambiguity') {
        const n = r.ambiguity?.shared_name_count;
        return (
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                A catalogue duplicate — {n ? `${n} items` : 'more than one item'} carry this name, and Tally matches a
                voucher line by name, so linking any one of them does not clear it. The duplicate is settled in the
                catalogue (Q43 — whether it blocks or warns is the owner&rsquo;s call); nothing here picks a row.
            </Typography.Text>
        );
    }
    if (!r.standard || (target === 'packaging_item' && !r.packaging)) {
        return <Typography.Text type="secondary">—</Typography.Text>;
    }
    const candidates = r.candidates ?? [];
    if (candidates.length === 0) {
        return (
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                No Tally item matches this name. Tally has to carry the product first — the ERP never creates one. Open
                the product to search the whole catalogue.
            </Typography.Text>
        );
    }
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
                onChange={onPick}
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
                    loading={isBusyRow}
                    onClick={() => picked !== undefined && onLink(picked)}
                >
                    {verb}
                </Button>
            </Tooltip>
        </Space>
    );
}

/**
 * Where the collapsed/expanded choice is kept.
 *
 * `localStorage` and not a server preference: this is one person's view of
 * one panel on one browser, it must survive a navigation, and it is worth
 * exactly zero API surface. Every access is wrapped — a private window, or a
 * browser set to block site data, THROWS on access rather than returning
 * null, and a panel that crashes the Product Standards page to remember a
 * toggle is a far worse bug than a toggle that forgets.
 */
const OPEN_PREFERENCE_KEY = 'production.configurationReview.open';

export function readOpenPreference(): boolean {
    try {
        // Absent means never chosen, which is COLLAPSED — the default the
        // owner asked for. Only an explicit 'true' opens it.
        return window.localStorage.getItem(OPEN_PREFERENCE_KEY) === 'true';
    } catch {
        return false;
    }
}

function writeOpenPreference(open: boolean): void {
    try {
        window.localStorage.setItem(OPEN_PREFERENCE_KEY, open ? 'true' : 'false');
    } catch {
        // Nothing to do and nothing worth telling the user: the panel still
        // works this session, it just will not remember.
    }
}

export default function ConfigurationReviewPanel({
    onFindInTable,
}: {
    /** Show the product this row is about in the table below — the drawer's full editor is one click from there. */
    onFindInTable?: (product: string) => void;
}) {
    const queryClient = useQueryClient();
    const user = useAuthStore((s) => s.user);
    const canManage = hasManageAccess(user, 'production');

    // COLLAPSED ON ARRIVAL, and the choice is remembered (owner request,
    // 24-Aug-2026: "we have that option of choose the incomplete, complete
    // and all in the filter button, that is enough").
    //
    // The filter directly below this panel already carries the counts —
    // Production ready / Incomplete / All — so an expanded wall of yellow on
    // every page load was re-stating the chip beside it. What the filter
    // CANNOT do is fix a row: this panel is the only place all the waiting
    // identities are linkable from one screen instead of one drawer each.
    // So it collapses to a single line rather than being deleted; the
    // worklist stays one click away.
    const [open, setOpen] = useState(() => readOpenPreference());

    // Remember it. Hiding this on every visit is the complaint; a hide that
    // does not survive a navigation is the same complaint with extra steps.
    const toggleOpen = (next: boolean) => {
        setOpen(next);
        writeOpenPreference(next);
    };

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

    // Executes the plan linkPlanFor() returned; it decides nothing itself, so
    // the tested function and the shipped behaviour cannot drift apart.
    const link = (row: ConfigurationReviewRow, itemId: number) => {
        const plan = linkPlanFor(row, itemId);

        if (plan.endpoint === 'none') return;

        if (plan.endpoint === 'attach_item') {
            if (plan.confirmRepoint) {
                Modal.confirm({
                    title: 'Change the Tally identity of this product?',
                    okText: 'Change the identity',
                    okButtonProps: { danger: true },
                    content: `Currently attached to "${row.item?.name ?? ''}". Changing it re-points every FUTURE run of this product at the new item. Completed batches and vouchers already posted keep the identity they recorded — history is never rewritten. The change is noted on the standard with your name.`,
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

    const headline = reviewHeadline(rows);

    const busy = linkPackaging.isPending || attachProduct.isPending;
    const busyRow = linkPackaging.isPending ? linkPackaging.variables?.row : attachProduct.variables?.row;

    return (
        <Alert
            type="warning"
            showIcon
            style={{ marginBottom: 16 }}
            message={`Needs review — ${headline} still waiting on a person`}
            action={
                <Button size="small" onClick={() => toggleOpen(! open)}>
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
                                        ) : r.kind === 'packaging_separate_product' ? (
                                            // BOTH ENDS OF THE RELATION, because either alone
                                            // reads as ordinary: the item this packing posts
                                            // as, and the product it is filed under. Seeing
                                            // them side by side IS the finding. Either end may
                                            // name a retired row — the label wears "(archived)"
                                            // rather than passing it off as a live identity.
                                            <Space direction="vertical" size={0}>
                                                <Typography.Text>posts as {tallyIdentityLabelMarkingArchived(r.item)}</Typography.Text>
                                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                    under the product {tallyIdentityLabelMarkingArchived(r.product_item)}
                                                </Typography.Text>
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
                                    render: (_, r) => (
                                        <ConfigurationReviewFixCell
                                            row={r}
                                            canManage={canManage}
                                            picked={chosen[rowKey(r)]}
                                            onPick={(v) => setChosen((c) => ({ ...c, [rowKey(r)]: v }))}
                                            busy={busy}
                                            isBusyRow={busy && busyRow === r}
                                            onLink={(itemId) => link(r, itemId)}
                                        />
                                    ),
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
