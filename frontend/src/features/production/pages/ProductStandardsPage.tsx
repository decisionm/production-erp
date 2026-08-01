import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Card,
    Col,
    Divider,
    Form,
    Input,
    InputNumber,
    Modal,
    Radio,
    Row,
    Segmented,
    Select,
    Space,
    Spin,
    Table,
    Tag,
    Tooltip,
    Typography,
} from 'antd';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { listAllItems } from '@/features/inventory/api';
import {
    buildStartBatchReturnUrl,
    hasStartBatchResume,
    parseStartBatchResume,
} from '@/features/production/startBatchResume';
import {
    attachStandardItem,
    createProductionStandard,
    listProductionConfigurations,
    listProductionStandards,
    listStandardItemCandidates,
} from '@/features/production/api';
import { useProductionSettings } from '@/features/production/packing';
import type {
    ProductionConfiguration,
    ProductionStandardRow,
    StandardSpecColumn,
    StandardSpecProvenance,
} from '@/features/production/types';
import { itemLabel } from '@/lib/itemLabel';

/**
 * The factory's imported product master, on screen — and the place it is
 * maintained from.
 *
 * This existed as an API endpoint and a database table with no page, which made
 * the configured products invisible: the only place their figures surfaced was
 * inside the Start Batch dialog, one product at a time, after picking a
 * machine. There was no way to answer "what is actually configured?".
 *
 * It was read-only for as long as the import command was the only writer. It no
 * longer is, and the three things that changed are all things the workbook
 * cannot do for itself:
 *
 *  - **Attaching a Tally item.** ~60 standards import under a product name that
 *    matches no item, so they land recorded-but-unattached. Only a person can
 *    say which item a workbook name means; the app can only shortlist. That
 *    shortlist is name similarity and nothing else — see the attach dialog,
 *    which says so where the decision is made rather than in a comment here.
 *  - **Adding a product by hand.** A product the workbook does not carry used
 *    to need a workbook edit and a re-import to exist at all.
 *  - **Reading a machine's exceptions.** The per-machine overrides used to live
 *    on a separate tab, which asked people to hold "standard" and
 *    "configuration" apart in their heads. They are now the expandable row of
 *    the standard they override, which is the only place the difference is
 *    self-explanatory.
 *
 * Editing an imported FIGURE is still not offered: the workbook is the
 * factory's own record, and a figure edited here would silently disagree with
 * it.
 *
 * ## The three tables this page is NOT
 *
 * Worth stating, because they are easy to confuse and each explains a different
 * notice on Start Batch:
 *
 *  - **Product standards** (this page) — what a product runs to WHEREVER it
 *    runs: cavities, weight, cycle time, packing. From the workbook.
 *  - **Production configuration** (/production/configuration) — a machine +
 *    product + mould approval, needed only for an exception. Now shown inline
 *    here, on the standard it is an exception to.
 *  - **Bills of Material** (/production/boms) — the consumption recipe: how much
 *    resin and masterbatch go into one bottle.
 */

/** One page holds the whole master (103 rows today), so the header freezing is what makes it readable. */
const PER_PAGE = 200;

/**
 * The app bar is `position: sticky; top: 0` at antd's default header height, so
 * the table header has to freeze below it rather than under it.
 */
const APP_HEADER_HEIGHT = 64;

/** The standard's packaging row for one mode, if the workbook gave one. */
const pkg = (r: ProductionStandardRow, mode: 'pouch' | 'tray' | 'direct_box') =>
    r.packagings.find((p) => p.mode === mode);

const fmt = (v: string | number | null | undefined, suffix = ''): string => {
    if (v === null || v === undefined || v === '') return '—';
    const n = typeof v === 'number' ? v : parseFloat(v);
    return Number.isNaN(n) ? '—' : `${parseFloat(n.toFixed(4))}${suffix}`;
};

const STATUS: Record<string, { colour: string; label: string; help: string }> = {
    approved: { colour: 'green', label: 'Approved', help: 'Signed off by a person.' },
    draft: {
        colour: 'blue',
        label: 'Ready',
        help: 'Imported cleanly and usable. Nothing here needs a decision — "draft" only means no one has formally signed it off, which the import deliberately never does on your behalf.',
    },
    unresolved: {
        colour: 'orange',
        label: 'Needs a factory answer',
        help: 'The workbook cell was ambiguous or blank. The batch can still run; the figure is just not one anybody has confirmed.',
    },
};

const CONFIG_STATUS_COLOUR: Record<string, string> = {
    approved: 'green',
    draft: 'blue',
    inactive: 'default',
};

// ---------------------------------------------------------------------------
// Packing-material specs, and whether the value in the cell was inferred
// ---------------------------------------------------------------------------

const SPEC_COLUMNS = ['carton_spec', 'tray_spec', 'pouch_spec'] as const;

/**
 * A packing-material spec, plus its provenance entry when one exists.
 *
 * The workbook leaves some of these blank and the factory can still answer
 * them from its own sheet — 375ML KIDNEY's carton is the 500ML KIDNEY's
 * carton, stated two rows above it. What such a fill must never do is *look*
 * like the factory stated it here, which is why the source travels beside the
 * value rather than inside it.
 *
 * A column with no entry in the map came from the workbook verbatim.
 */
function standardSpec(
    r: ProductionStandardRow,
    column: StandardSpecColumn,
): { value: string | null; inferred: StandardSpecProvenance | null } {
    const value =
        column === 'carton_spec' ? r.carton_spec : column === 'tray_spec' ? r.tray_spec : r.pouch_spec;

    // `inferred: false` would be a stated value whose origin happens to be
    // recorded — no marker, because it needs no caveat.
    const entry = r.spec_provenance?.[column] ?? null;

    return {
        value: value === null || value === undefined || value === '' ? null : value,
        inferred: entry && entry.inferred !== false ? entry : null,
    };
}

const specCell = (r: ProductionStandardRow, column: StandardSpecColumn) => {
    const { value, inferred } = standardSpec(r, column);
    if (value === null) return '—';
    if (inferred === null) return value;

    const from = [
        inferred.from_source_reference ? `SL ${inferred.from_source_reference}` : null,
        inferred.from_product,
    ]
        .filter(Boolean)
        .join(' — ');

    return (
        <Tooltip
            title={
                <>
                    <div>
                        <b>Inferred{from ? ` from ${from}` : ''}</b> — the workbook left this blank for this product.
                    </div>
                    {inferred.reason && <div style={{ marginTop: 4 }}>{inferred.reason}</div>}
                    <div style={{ marginTop: 4, opacity: 0.75 }}>
                        {[inferred.inferred_by, inferred.inferred_on].filter(Boolean).join(' · ')}
                    </div>
                    <div style={{ marginTop: 4, opacity: 0.75 }}>
                        Not the factory's word. Correct it in the workbook if it is wrong.
                    </div>
                </>
            }
        >
            <span style={{ borderBottom: '1px dotted #ad6800', cursor: 'help' }}>{value}</span>
        </Tooltip>
    );
};

const hasInferredSpec = (r: ProductionStandardRow) =>
    SPEC_COLUMNS.some((c) => standardSpec(r, c).inferred !== null);

/**
 * How this standard came to point at its Tally item, in one line — or nothing,
 * which is itself the answer for the ~40 rows the importer matched by name.
 *
 * `item_attached_by` is a users foreign key, so an endpoint that has not
 * eager-loaded the relation sends a bare id. An id is not a name: "attached by
 * 7" tells nobody anything and reads as a bug. The DATE still says the thing
 * that matters most — that a PERSON decided this in the app, rather than the
 * importer matching two strings — so it is shown either way, named or not.
 */
const attachmentNote = (r: ProductionStandardRow): string | null => {
    const by = r.item_attached_by;
    const name = by !== null && by !== undefined && typeof by !== 'number' ? (by.name ?? '').trim() : '';
    const on = r.item_attached_at ? r.item_attached_at.slice(0, 10) : '';

    if (name === '' && on === '') return null;
    if (name === '') return `attached here · ${on}`;

    return on === '' ? `attached by ${name}` : `attached by ${name} · ${on}`;
};

// ---------------------------------------------------------------------------
// The expandable row: this product's per-machine exceptions
// ---------------------------------------------------------------------------

/**
 * The approved configurations for one product — the exceptions to the standard
 * the row above states.
 *
 * Mounted only once its row is expanded, which is why the query lives in here
 * rather than in the page: a hundred rows each firing a configurations request
 * on render would be a hundred requests to show nothing most of the time.
 *
 * Read-only on purpose. Editing an approval is an approval decision and stays
 * on Machine Setup, which has the approve/deactivate/copy actions and
 * the audit trail around them.
 */
function MachineOverrides({ itemId, productName }: { itemId: number; productName: string }) {
    const { data, isLoading, isError } = useQuery({
        queryKey: ['production', 'configurations', 'for-item', itemId],
        queryFn: () => listProductionConfigurations({ item_id: itemId, per_page: 200 }),
    });

    if (isLoading) {
        return (
            <Space>
                <Spin size="small" />
                <Typography.Text type="secondary">Looking for machine overrides…</Typography.Text>
            </Space>
        );
    }

    if (isError) {
        return (
            <Typography.Text type="secondary">
                Could not read this product's machine overrides just now. Open{' '}
                <Link to="/production/configuration">Machine Setup</Link> to check them.
            </Typography.Text>
        );
    }

    const rows = data?.data ?? [];

    if (rows.length === 0) {
        // The common and correct case, said quietly. An empty table here would
        // read as "something is missing"; nothing is missing — a product with
        // no exception simply runs to its standard.
        return (
            <Space size={6} wrap>
                <Typography.Text type="secondary">
                    Runs to this standard on every machine it may run on.
                </Typography.Text>
                <Link to="/production/configuration">Add a machine exception</Link>
            </Space>
        );
    }

    return (
        <>
            <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>
                Machines that run <b>{productName}</b> to figures of their own. Everywhere else, the standard above
                applies.
            </Typography.Text>
            <Table<ProductionConfiguration>
                rowKey="id"
                size="small"
                pagination={false}
                dataSource={rows}
                columns={[
                    {
                        title: 'Machine',
                        render: (_, c) => c.work_center.name ?? `#${c.work_center.id}`,
                    },
                    {
                        title: 'Cycle time (s)',
                        align: 'right' as const,
                        render: (_, c) =>
                            c.cycle_time_min !== null && c.cycle_time_max !== null
                                ? `${fmt(c.default_cycle_time)} (${fmt(c.cycle_time_min)}–${fmt(c.cycle_time_max)})`
                                : fmt(c.default_cycle_time),
                    },
                    {
                        title: 'Cavities',
                        align: 'right' as const,
                        render: (_, c) => c.default_cavities ?? '—',
                    },
                    {
                        title: 'Weight (g)',
                        align: 'right' as const,
                        render: (_, c) => fmt(c.unit_weight_grams),
                    },
                    {
                        title: 'Mould / colour',
                        render: (_, c) => [c.mold?.name, c.colour].filter(Boolean).join(' · ') || '—',
                    },
                    {
                        title: 'Status',
                        render: (_, c) => (
                            <Space direction="vertical" size={2}>
                                <Tag color={CONFIG_STATUS_COLOUR[c.status] ?? 'default'}>{c.status}</Tag>
                                {c.confirmation_status && (
                                    <Typography.Text type="secondary" style={{ fontSize: 11 }}>
                                        {c.confirmation_status}
                                    </Typography.Text>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />
            <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block', marginTop: 6 }}>
                Only an <b>approved</b> override drives a batch. Edit or approve on{' '}
                <Link to="/production/configuration">Machine Setup → Machine Exceptions</Link>.
            </Typography.Text>
        </>
    );
}

// ---------------------------------------------------------------------------
// Attaching a Tally item to a standard that has none
// ---------------------------------------------------------------------------

const showSaveError = (error: any, title: string) => {
    const errors = error?.response?.data?.errors;
    Modal.error({
        title,
        // Field-level messages say exactly what the backend refused and why —
        // an attach that collides with an existing variant is a real answer,
        // not an "unexpected error".
        content: errors
            ? Object.values(errors).flat().join(' ')
            : (error?.response?.data?.message ?? 'Unexpected error.'),
    });
};

/**
 * Pick the Tally item an unattached standard means.
 *
 * The candidate list is name similarity — `similar_text` over normalised
 * names, plus whether the leading size token agrees. It knows nothing about
 * what the factory makes, so a 90% match can be the wrong bottle and a 40%
 * match can be the right one. The dialog says this where the choice happens;
 * a person confirms, and nothing is written until they do.
 */
function AttachItemModal({ standard, onClose }: { standard: ProductionStandardRow; onClose: () => void }) {
    const queryClient = useQueryClient();
    const [selected, setSelected] = useState<number | null>(null);

    const candidates = useQuery({
        queryKey: ['production', 'standards', 'item-candidates', standard.id],
        queryFn: () => listStandardItemCandidates(standard.id),
        // One shot: the backend re-reads every active item to answer this, and
        // a failure here must not stop the search-every-item fallback below.
        retry: false,
        staleTime: 5 * 60 * 1000,
    });

    const items = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems, staleTime: 5 * 60 * 1000 });

    const itemOptions = useMemo(
        () =>
            (items.data?.data ?? []).map((i) => ({
                value: i.id,
                label: itemLabel(i),
            })),
        [items.data],
    );

    const attach = useMutation({
        mutationFn: (itemId: number) => attachStandardItem(standard.id, itemId),
        onSuccess: () => {
            // The whole standards prefix, not this page+scope: the other scope's
            // cache and the counts on the stat cards are wrong the moment a row
            // gains its item.
            queryClient.invalidateQueries({ queryKey: ['production', 'standards'] });
            onClose();
        },
        onError: (error: any) => showSaveError(error, 'Could not attach this item'),
    });

    return (
        <Modal
            open
            title="Attach a Tally item"
            onCancel={onClose}
            okText="Attach this item"
            okButtonProps={{ disabled: selected === null }}
            confirmLoading={attach.isPending}
            onOk={() => selected !== null && attach.mutate(selected)}
            width={620}
        >
            <Typography.Paragraph style={{ marginBottom: 8 }}>
                The workbook calls this product <b>{standard.source_product_name}</b>
                {standard.source_reference ? ` (SL ${standard.source_reference})` : ''}. Which Tally item is that?
            </Typography.Paragraph>

            <Alert
                type="warning"
                showIcon
                style={{ marginBottom: 12 }}
                message="These are guesses at the name, not at the product"
                description="The list below is ranked by how closely the workbook's wording resembles a Tally item's name. The app does not know your bottles — a high score can still be the wrong product, and the right one may not be listed at all. Read them, and use the search below if none is right. Nothing is attached until you press Attach."
            />

            {candidates.isLoading && (
                <Space>
                    <Spin size="small" />
                    <Typography.Text type="secondary">Scoring item names…</Typography.Text>
                </Space>
            )}

            {candidates.isError && (
                <Typography.Text type="secondary">
                    No suggestions available — search the full item list below instead.
                </Typography.Text>
            )}

            {candidates.data && candidates.data.length > 0 && (
                <Radio.Group
                    value={selected}
                    onChange={(e) => setSelected(e.target.value)}
                    style={{ display: 'block', width: '100%' }}
                >
                    <Space direction="vertical" size={8} style={{ width: '100%' }}>
                        {candidates.data.map((c) => (
                            <Radio key={c.id} value={c.id}>
                                <Space size={6} wrap>
                                    <span>{itemLabel({ sku: c.sku, name: c.name })}</span>
                                    <Tag color={c.score >= 70 ? 'blue' : 'default'}>{Math.round(c.score)}% name match</Tag>
                                    {c.same_size && (
                                        <Tooltip title="The size in both names agrees, e.g. 500ML in each.">
                                            <Tag color="green">size agrees</Tag>
                                        </Tooltip>
                                    )}
                                    {/* The one thing a name score cannot know.
                                        Not a warning — a mould covers every
                                        colour of its bottle, so sibling
                                        variants sharing an item is normal. */}
                                    {c.attached_to_same_product && (
                                        <Tooltip title="Another variant of this same product name already points at this item. Usually right — one mould covers every colour of a bottle. Worth a second look if you expected this product to be its own item.">
                                            <Tag color="purple">already used by a sibling variant</Tag>
                                        </Tooltip>
                                    )}
                                </Space>
                            </Radio>
                        ))}
                    </Space>
                </Radio.Group>
            )}

            {candidates.data && candidates.data.length === 0 && (
                <Typography.Text type="secondary">
                    No item name resembles this one closely enough to suggest. Search the full list below.
                </Typography.Text>
            )}

            <Divider style={{ margin: '14px 0 10px' }} plain>
                or pick any item
            </Divider>

            <Select
                showSearch
                allowClear
                optionFilterProp="label"
                placeholder="Search every Tally item…"
                style={{ width: '100%' }}
                loading={items.isLoading}
                value={selected ?? undefined}
                onChange={(v) => setSelected(v ?? null)}
                options={itemOptions}
            />

            <Typography.Paragraph type="secondary" style={{ fontSize: 12, marginTop: 12, marginBottom: 0 }}>
                Attaching keeps this row as it is — the same cavities, weight and cycle time, now pointing at an item.
                It does not create a second copy of the product.
            </Typography.Paragraph>
        </Modal>
    );
}

// ---------------------------------------------------------------------------
// Adding a product the workbook does not carry
// ---------------------------------------------------------------------------

interface NewStandardForm {
    source_product_name: string;
    /** Required by the backend, but undefined until the person types it. */
    cavities?: number;
    unit_weight_grams?: number;
    cycle_time?: number;
    nos_per_pouch?: number;
    pouches_per_box?: number;
    nos_per_tray?: number;
    trays_per_box?: number;
    carton_spec?: string;
    tray_spec?: string;
    pouch_spec?: string;
    notes?: string;
}

/**
 * The workbook's own arithmetic, said back. The sheet carries all three figures
 * per packing mode and the third is always the product of the first two, so the
 * form asks for two and shows the third — that way the box count on screen can
 * never disagree with the two counts it came from.
 */
function DerivedPerBox({ per, count, unit }: { per?: number; count?: number; unit: 'pouch' | 'tray' }) {
    if (!per || !count) {
        // Says the rule rather than only the symptom: one figure on its own is
        // dropped, because half a packing mode would put a box count nobody
        // stated in front of the packing line.
        return (
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                Fill both, or neither — one on its own is not saved.
            </Typography.Text>
        );
    }
    return (
        <Typography.Text style={{ fontSize: 12 }}>
            {per}/{unit} × {count} {unit}s = <b>{per * count}</b>/box
        </Typography.Text>
    );
}

function NewStandardModal({ onClose, initialName }: { onClose: () => void; initialName?: string }) {
    const queryClient = useQueryClient();
    const [form] = Form.useForm<NewStandardForm>();

    const nosPerPouch = Form.useWatch('nos_per_pouch', form);
    const pouchesPerBox = Form.useWatch('pouches_per_box', form);
    const nosPerTray = Form.useWatch('nos_per_tray', form);
    const traysPerBox = Form.useWatch('trays_per_box', form);

    const create = useMutation({
        mutationFn: createProductionStandard,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'standards'] });
            onClose();
        },
        onError: (error: any) => showSaveError(error, 'Could not add this product standard'),
    });

    const submit = (v: NewStandardForm) => {
        // Cannot fire — the three are `required` on their Form.Items, so
        // onFinish only runs once they hold numbers. Present so the payload is
        // built from values TypeScript has actually seen narrowed, rather than
        // from three non-null assertions.
        if (v.cavities === undefined || v.unit_weight_grams === undefined || v.cycle_time === undefined) return;

        // A packing mode is sent only when BOTH of its counts are filled. A
        // half-filled mode is not a packing option with a number missing — it
        // is someone who has not decided yet, and recording it would put a
        // wrong box count in front of the packing line.
        const pouched = v.nos_per_pouch && v.pouches_per_box ? v.nos_per_pouch * v.pouches_per_box : null;
        const trayed = v.nos_per_tray && v.trays_per_box ? v.nos_per_tray * v.trays_per_box : null;

        create.mutate({
            source_product_name: v.source_product_name.trim(),
            cavities: v.cavities,
            unit_weight_grams: v.unit_weight_grams,
            cycle_time: v.cycle_time,
            carton_spec: v.carton_spec?.trim() || null,
            tray_spec: v.tray_spec?.trim() || null,
            pouch_spec: v.pouch_spec?.trim() || null,
            nos_per_pouch: pouched === null ? null : v.nos_per_pouch,
            pouches_per_box: pouched === null ? null : v.pouches_per_box,
            pouch_nos_per_box: pouched,
            nos_per_tray: trayed === null ? null : v.nos_per_tray,
            trays_per_box: trayed === null ? null : v.trays_per_box,
            tray_nos_per_box: trayed,
            notes: v.notes?.trim() || null,
        });
    };

    return (
        <Modal
            open
            title="New product standard"
            onCancel={onClose}
            okText="Add product"
            confirmLoading={create.isPending}
            onOk={() => form.submit()}
            width={640}
        >
            <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
                For a product the workbook does not carry. It is added unattached and marked the same way an import
                marks a row nobody has signed off — attach its Tally item from the table afterwards, the same way as
                any other row.
            </Typography.Paragraph>

            {/* Opened from a blocked Start Batch: the product is already known,
                so it arrives typed. Still editable — the workbook's wording and
                the item master's are not always the same, and only a person can
                say which one the factory uses. */}
            <Form<NewStandardForm>
                form={form}
                layout="vertical"
                onFinish={submit}
                requiredMark={false}
                initialValues={initialName ? { source_product_name: initialName } : undefined}
            >
                <Form.Item
                    name="source_product_name"
                    label="Product name"
                    rules={[{ required: true, message: 'Name it the way the factory says it, e.g. 500ML ROUND.' }]}
                    extra="Use the factory's own wording — this is the name the workbook would have carried."
                >
                    <Input placeholder="e.g. 500ML ROUND" />
                </Form.Item>

                {/* All three are required, unlike the importer, which has to
                    accept a blank cell rather than lose a product the factory
                    really runs. Every expected-output figure is derived from
                    them, so a form can simply ask. */}
                <Row gutter={12}>
                    <Col xs={24} sm={8}>
                        <Form.Item
                            name="cavities"
                            label="Cavities"
                            rules={[{ required: true, message: 'How many cavities the mould has.' }]}
                        >
                            <InputNumber min={1} precision={0} style={{ width: '100%' }} placeholder="e.g. 8" />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={8}>
                        <Form.Item
                            name="unit_weight_grams"
                            label="Weight (g)"
                            rules={[{ required: true, message: 'The weight of one bottle, in grams.' }]}
                        >
                            <InputNumber min={0} step={0.1} style={{ width: '100%' }} placeholder="e.g. 26" />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={8}>
                        <Form.Item
                            name="cycle_time"
                            label="Cycle time (s)"
                            rules={[{ required: true, message: 'Seconds per shot — expected output is derived from it.' }]}
                        >
                            <InputNumber min={0} step={0.1} style={{ width: '100%' }} placeholder="e.g. 16.5" />
                        </Form.Item>
                    </Col>
                </Row>

                <Divider titlePlacement="start" plain style={{ margin: '4px 0 12px' }}>
                    Packing — fill only the ways this product is actually packed
                </Divider>

                <Row gutter={12} align="middle">
                    <Col xs={12} sm={8}>
                        <Form.Item name="nos_per_pouch" label="Bottles per pouch">
                            <InputNumber min={1} precision={0} style={{ width: '100%' }} />
                        </Form.Item>
                    </Col>
                    <Col xs={12} sm={8}>
                        <Form.Item name="pouches_per_box" label="Pouches per box">
                            <InputNumber min={1} precision={0} style={{ width: '100%' }} />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={8}>
                        <Form.Item label=" " colon={false}>
                            <DerivedPerBox per={nosPerPouch} count={pouchesPerBox} unit="pouch" />
                        </Form.Item>
                    </Col>
                </Row>

                <Row gutter={12} align="middle">
                    <Col xs={12} sm={8}>
                        <Form.Item name="nos_per_tray" label="Bottles per tray">
                            <InputNumber min={1} precision={0} style={{ width: '100%' }} />
                        </Form.Item>
                    </Col>
                    <Col xs={12} sm={8}>
                        <Form.Item name="trays_per_box" label="Trays per box">
                            <InputNumber min={1} precision={0} style={{ width: '100%' }} />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={8}>
                        <Form.Item label=" " colon={false}>
                            <DerivedPerBox per={nosPerTray} count={traysPerBox} unit="tray" />
                        </Form.Item>
                    </Col>
                </Row>

                <Divider titlePlacement="start" plain style={{ margin: '4px 0 12px' }}>
                    Packaging materials — the sheet's own codes, if you know them
                </Divider>

                <Row gutter={12}>
                    <Col xs={24} sm={8}>
                        <Form.Item name="carton_spec" label="Carton">
                            <Input maxLength={64} placeholder="e.g. 500ML ROUND" />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={8}>
                        <Form.Item name="tray_spec" label="Tray">
                            <Input maxLength={64} placeholder="e.g. 835 X 610" />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={8}>
                        <Form.Item name="pouch_spec" label="Pouch film">
                            <Input maxLength={64} placeholder="e.g. HM 30.5*49" />
                        </Form.Item>
                    </Col>
                </Row>

                <Form.Item name="notes" label="Note (optional)">
                    <Input.TextArea rows={2} placeholder="Why this product is here and who asked for it." />
                </Form.Item>
            </Form>
        </Modal>
    );
}

// ---------------------------------------------------------------------------

export default function ProductStandardsPage() {
    const [page, setPage] = useState(1);
    const [scope, setScope] = useState<'mapped' | 'all'>('all');
    const [search, setSearch] = useState('');
    const [cavityBand, setCavityBand] = useState<'any' | 'below' | 'atOrAbove'>('any');
    const [attaching, setAttaching] = useState<ProductionStandardRow | null>(null);
    const [adding, setAdding] = useState(false);

    /**
     * Arrived here from a blocked Start Batch.
     *
     * THE CONTEXT LIVES IN THE URL, not in memory, so a refresh in the middle
     * of configuring does not strand the supervisor on a page with no way
     * back. It is read through the shared allowlisted parser — every id is a
     * scalar this app put there and nothing else is accepted; an arbitrary
     * return URL is never honoured.
     */
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const resumeQuery = searchParams.toString();
    const resumeDraft = useMemo(() => {
        const params = new URLSearchParams(resumeQuery);
        if (!hasStartBatchResume(params, 'configure')) return null;
        return parseStartBatchResume(params)?.draft ?? null;
    }, [resumeQuery]);

    // Only fetched when somebody actually came from Start Batch. The key is
    // the app-wide "every item" one, so on a warm cache this costs nothing.
    const { data: allItems } = useQuery({
        queryKey: ['inventory', 'items', 'all'],
        queryFn: listAllItems,
        enabled: resumeDraft !== null,
    });
    const resumeItem = resumeDraft
        ? (allItems?.data.find((item) => item.id === resumeDraft.item_id) ?? null)
        : null;

    // Land ON the product, not on 86 rows of other people's products. Once
    // per arrival: after that the search box is the supervisor's.
    const prefilledSearchForRef = useRef<string | null>(null);
    useEffect(() => {
        if (!resumeDraft || !resumeItem) return;
        if (prefilledSearchForRef.current === resumeQuery) return;
        prefilledSearchForRef.current = resumeQuery;
        setSearch(resumeItem.name);
        setPage(1);
    }, [resumeDraft, resumeItem, resumeQuery]);

    const { data, isLoading } = useQuery({
        queryKey: ['production', 'standards', page, scope],
        queryFn: () => listProductionStandards({ page, per_page: PER_PAGE, matched_only: scope === 'mapped' }),
    });

    // The factory's machine rule, from the backend that also enforces it — so
    // the machines this page names cannot disagree with the machines Start
    // Batch allows. Absent on an older backend, in which case the column stays
    // silent rather than guessing.
    const settings = useProductionSettings();
    const rule = settings?.machine_capability ?? null;
    const threshold = rule?.cavity_threshold ?? null;
    const restrictedNames = (rule?.restricted_machines ?? []).map((m) => m.name);

    const rows = data?.data ?? [];

    // Filtered in the browser rather than by the API: the whole list is one
    // page, and typing is instant this way.
    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return rows.filter((r) => {
            if (q !== '') {
                const hit =
                    r.source_product_name.toLowerCase().includes(q) ||
                    (r.item?.name ?? '').toLowerCase().includes(q) ||
                    (r.item?.sku ?? '').toLowerCase().includes(q);
                if (!hit) return false;
            }
            if (cavityBand === 'any' || threshold === null) return true;
            // A blank cavity count belongs to neither band. It is the figure
            // the rule is decided on, so a row without one cannot be claimed
            // for either side.
            if (r.cavities === null || r.cavities === undefined) return false;
            return cavityBand === 'below' ? r.cavities < threshold : r.cavities >= threshold;
        });
    }, [rows, search, cavityBand, threshold]);

    const mappedCount = rows.filter((r) => r.item !== null).length;
    const needsAnswer = rows.filter((r) => r.status === 'unresolved').length;
    const withPouch = rows.filter((r) => r.packagings.some((p) => p.mode === 'pouch')).length;
    const inferredCount = rows.filter(hasInferredSpec).length;

    return (
        <>
            <Row justify="space-between" align="middle" gutter={[8, 8]} style={{ marginBottom: 4 }}>
                <Col>
                    <Typography.Title level={3} style={{ marginBottom: 0 }}>
                        Product Standards
                    </Typography.Title>
                </Col>
                <Col>
                    <Button type="primary" onClick={() => setAdding(true)}>
                        New product standard
                    </Button>
                </Col>
            </Row>
            <Typography.Paragraph type="secondary">
                The factory's product master — cavities, weight, cycle time and packing for each product, attached to
                the Tally item it applies to. Expand a row to see the machines that run it differently. The figures come
                from the workbook and are corrected there; what is maintained here is which item a product is, and
                products the workbook does not carry.
            </Typography.Paragraph>

            {/* THE WAY BACK. A supervisor sent here by a blocked Start Batch has
                a machine standing idle, and the worst outcome of this side trip
                is that they fix the product and then have to rebuild the setup
                from memory — machine, shift, date, store, cavities, colour. The
                button carries all of it back and Start Batch reopens exactly as
                it was left, having re-read this product's readiness. It sits at
                the top because it is the only thing on this page that is about
                the batch they are trying to start. */}
            {resumeDraft && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={
                        resumeItem
                            ? `Configuring ${itemLabel(resumeItem)} for a batch you were starting`
                            : 'Configuring a product for a batch you were starting'
                    }
                    description={
                        <>
                            Add or attach this product&rsquo;s standard below — the cavities, weight, cycle time and
                            pieces per carton are what Start Batch is waiting for. Then come back: your machine, shift,
                            date and store are all still held.
                            {/* THE COMMON CASE, AND THE ONE THAT LOOKS LIKE A
                                BROKEN PAGE. The findings that block a start —
                                weight, cycle time, cavities — fire precisely
                                when the product has no standard at all, so the
                                filtered table below is empty. An empty grid
                                reads as "nothing to do here"; it is in fact the
                                whole reason they were sent. */}
                            {resumeItem && visible.length === 0 && !isLoading ? (
                                <div style={{ marginTop: 8 }}>
                                    <Typography.Text strong>
                                        There is no standard for this product yet — that is what Start Batch is
                                        missing.
                                    </Typography.Text>{' '}
                                    Use <b>New product standard</b> above; it opens with the product name already
                                    filled in.
                                </div>
                            ) : null}
                        </>
                    }
                    action={
                        <Button
                            type="primary"
                            onClick={() => navigate(buildStartBatchReturnUrl(resumeDraft, 'created'), { replace: true })}
                        >
                            Back to Start Batch
                        </Button>
                    }
                />
            )}

            {/* The distinction that explains two Start Batch notices. Stated here
                because this is the page people arrive at looking for it. */}
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="Which machines a product runs on"
                description={
                    <>
                        {threshold !== null && restrictedNames.length > 0 ? (
                            <>
                                The <b>MACHINES</b> column is the factory's own rule, not a list anyone maintains: under{' '}
                                {threshold} cavities a mould runs on <b>any</b> machine, and at {threshold} or more it is
                                set up on <b>{restrictedNames.join(' or ')}</b>. Change the rule on{' '}
                                <Link to="/production/configuration">Machine Setup → Machines &amp; Capabilities</Link> and
                                this column follows.{' '}
                            </>
                        ) : null}
                        A <b>standard</b> (this page) is what a product runs to wherever it runs. A{' '}
                        <b>configuration</b> is only needed for an exception — a product that runs differently on one
                        machine than the workbook says. Those exceptions are on the <b>expanded row</b> of the product
                        they belong to; <Link to="/production/configuration">Machine Setup</Link> is where they are
                        edited and approved.
                    </>
                }
            />

            <Row gutter={[10, 10]} style={{ marginBottom: 16 }}>
                <Col xs={12} sm={6}>
                    <Card size="small" styles={{ body: { padding: '10px 14px' } }}>
                        <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                            Standards shown
                        </Typography.Text>
                        <Typography.Text strong style={{ fontSize: 24 }}>{rows.length}</Typography.Text>
                    </Card>
                </Col>
                <Col xs={12} sm={6}>
                    <Card size="small" styles={{ body: { padding: '10px 14px' } }}>
                        <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                            Attached to a Tally item
                        </Typography.Text>
                        <Typography.Text strong style={{ fontSize: 24, color: '#237804' }}>{mappedCount}</Typography.Text>
                    </Card>
                </Col>
                <Col xs={12} sm={6}>
                    <Card size="small" styles={{ body: { padding: '10px 14px' } }}>
                        <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                            Offer a pouch option
                        </Typography.Text>
                        <Typography.Text strong style={{ fontSize: 24 }}>{withPouch}</Typography.Text>
                    </Card>
                </Col>
                <Col xs={12} sm={6}>
                    <Card size="small" styles={{ body: { padding: '10px 14px' } }}>
                        <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                            Need a factory answer
                        </Typography.Text>
                        <Typography.Text strong style={{ fontSize: 24, color: needsAnswer > 0 ? '#ad6800' : undefined }}>
                            {needsAnswer}
                        </Typography.Text>
                    </Card>
                </Col>
            </Row>

            <Space style={{ marginBottom: 12 }} wrap>
                <Segmented
                    value={scope}
                    onChange={(v) => { setScope(v as 'mapped' | 'all'); setPage(1); }}
                    options={[
                        { value: 'mapped', label: 'Attached to an item' },
                        { value: 'all', label: 'Everything imported' },
                    ]}
                />
                <Input.Search
                    allowClear
                    placeholder="Search product or Tally item…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    style={{ width: 320 }}
                />
                {/* The cavity bands the machine rule actually splits on, so the
                    high-cavity group can be read as a group — that is the list
                    someone checks when they want to know what Machine 10 runs. */}
                {threshold !== null && restrictedNames.length > 0 && (
                    <Segmented
                        value={cavityBand}
                        onChange={(v) => setCavityBand(v as 'any' | 'below' | 'atOrAbove')}
                        options={[
                            { value: 'any', label: 'Any cavities' },
                            { value: 'below', label: `Under ${threshold} — all machines` },
                            { value: 'atOrAbove', label: `${threshold}+ — ${restrictedNames.join('/')} only` },
                        ]}
                    />
                )}
            </Space>

            <Table<ProductionStandardRow>
                rowKey="id"
                size="small"
                loading={isLoading}
                dataSource={visible}
                scroll={{ x: 'max-content' }}
                // Frozen below the app bar, not under it. The alternative — a
                // fixed body height via scroll.y — misaligns against the grouped
                // POUCH/TRAY/Packaging headers this table has.
                sticky={{ offsetHeader: APP_HEADER_HEIGHT }}
                expandable={{
                    // An unattached standard has no item to look configurations
                    // up by, so there is nothing behind its expander.
                    rowExpandable: (r) => r.item !== null,
                    expandedRowRender: (r) =>
                        r.item ? <MachineOverrides itemId={r.item.id} productName={r.source_product_name} /> : null,
                    // Pinned rather than measured: with `x: max-content` and
                    // three grouped header blocks, an auto-width expand column
                    // is where header and body drift apart.
                    columnWidth: 44,
                }}
                pagination={{
                    current: page,
                    pageSize: PER_PAGE,
                    total: data?.meta?.total ?? rows.length,
                    onChange: setPage,
                    showSizeChanger: false,
                    // Search and the cavity band filter in the browser, so the
                    // server's total is not what is on screen. Saying "103
                    // standards" above six KIDNEY rows would be the page
                    // contradicting itself.
                    showTotal: (total) =>
                        visible.length === rows.length
                            ? `${total} standards`
                            : `${visible.length} of ${total} standards match`,
                }}
                columns={[
                    {
                        title: 'SL.NO.',
                        align: 'right' as const,
                        render: (_, r) => (
                            <Tooltip title={r.source ? `From ${r.source}` : undefined}>
                                <Typography.Text type="secondary">{r.source_reference ?? '—'}</Typography.Text>
                            </Tooltip>
                        ),
                    },
                    {
                        title: 'PRODUCT',
                        sorter: (a, b) => a.source_product_name.localeCompare(b.source_product_name),
                        render: (_, r) => <Typography.Text strong>{r.source_product_name}</Typography.Text>,
                    },
                    {
                        title: 'Tally item it applies to',
                        sorter: (a, b) => (a.item?.name ?? '').localeCompare(b.item?.name ?? ''),
                        render: (_, r) =>
                            r.item ? (
                                <Space direction="vertical" size={0}>
                                    <span>{itemLabel(r.item)}</span>
                                    {/* Silent for a row the IMPORTER matched by
                                        name — which is the truth about it, and
                                        the distinction someone checking this
                                        column is looking for. */}
                                    {attachmentNote(r) !== null && (
                                        <Tooltip title="A person chose this item on this page. Rows without this line were matched by the importer from the workbook's product name.">
                                            <Typography.Text type="secondary" style={{ fontSize: 11 }}>
                                                {attachmentNote(r)}
                                            </Typography.Text>
                                        </Tooltip>
                                    )}
                                </Space>
                            ) : (
                                <Space size={6}>
                                    <Tooltip title="Imported, but the workbook name matches no Tally item — the standard is recorded as work to do rather than dropped.">
                                        <Tag>not attached</Tag>
                                    </Tooltip>
                                    <Button size="small" type="link" style={{ padding: 0 }} onClick={() => setAttaching(r)}>
                                        Attach
                                    </Button>
                                </Space>
                            ),
                    },
                    {
                        title: 'NO. OF CAVITY',
                        align: 'right',
                        sorter: (a, b) => (a.cavities ?? -1) - (b.cavities ?? -1),
                        render: (_, r) => r.cavities ?? '—',
                    },
                    {
                        // Which machines this product may run on — the factory's
                        // own rule (below the threshold, anywhere; at or above
                        // it, the named machines), applied to this row's cavity
                        // count. Computed, never stored: the rule is one
                        // sentence, and storing it per product-machine pair
                        // would mean ~790 rows to approve that then outrank the
                        // workbook the moment a figure is corrected there.
                        title: 'MACHINES',
                        sorter: (a, b) => (a.cavities ?? -1) - (b.cavities ?? -1),
                        render: (_, r) => {
                            if (threshold === null || restrictedNames.length === 0) return '—';
                            if (r.cavities === null || r.cavities === undefined) {
                                return (
                                    <Tooltip title="No cavity count on this standard, and the rule is decided on cavities — so which machines this runs on is not something the app can answer yet.">
                                        <Typography.Text type="secondary">needs a cavity count</Typography.Text>
                                    </Tooltip>
                                );
                            }
                            return r.cavities >= threshold ? (
                                <Tooltip title={`${r.cavities} cavities — at or above ${threshold}, so this mould is set up on ${restrictedNames.join(' or ')}.`}>
                                    <Tag color="gold">{restrictedNames.join(' or ')} only</Tag>
                                </Tooltip>
                            ) : (
                                <Tooltip title={`${r.cavities} cavities — below ${threshold}, so any machine can run it.`}>
                                    <Tag color="green">All machines</Tag>
                                </Tooltip>
                            );
                        },
                    },
                    {
                        title: 'WT. (g)',
                        align: 'right',
                        sorter: (a, b) => parseFloat(a.unit_weight_grams ?? '-1') - parseFloat(b.unit_weight_grams ?? '-1'),
                        render: (_, r) => fmt(r.unit_weight_grams),
                    },
                    {
                        title: 'CYCLE TIME (s)',
                        align: 'right',
                        sorter: (a, b) => parseFloat(a.cycle_time ?? '-1') - parseFloat(b.cycle_time ?? '-1'),
                        render: (_, r) =>
                            r.cycle_time_raw && r.cycle_time_raw !== r.cycle_time ? (
                                <Tooltip title={`The workbook cell held "${r.cycle_time_raw}" — split into separate variants rather than averaged.`}>
                                    <span>{fmt(r.cycle_time)} *</span>
                                </Tooltip>
                            ) : (
                                fmt(r.cycle_time)
                            ),
                    },
                    {
                        // The workbook's own three pouch columns, as columns —
                        // not folded into a tooltip. This page is read against
                        // the printed sheet, so it must line up with it.
                        title: 'POUCH',
                        children: [
                            { title: 'BOTL/POUCH', align: 'right' as const, render: (_: unknown, r: ProductionStandardRow) => pkg(r, 'pouch')?.nos_per_pouch ?? '—' },
                            { title: 'BOT/BOX', align: 'right' as const, render: (_: unknown, r: ProductionStandardRow) => pkg(r, 'pouch')?.nos_per_box ?? '—' },
                            { title: 'POUCH/BOX', align: 'right' as const, render: (_: unknown, r: ProductionStandardRow) => pkg(r, 'pouch')?.pouches_per_box ?? '—' },
                        ],
                    },
                    {
                        title: 'TRAY',
                        children: [
                            { title: 'BOTL/TRAY', align: 'right' as const, render: (_: unknown, r: ProductionStandardRow) => pkg(r, 'tray')?.nos_per_tray ?? '—' },
                            { title: 'BOT/BOX', align: 'right' as const, render: (_: unknown, r: ProductionStandardRow) => pkg(r, 'tray')?.nos_per_box ?? '—' },
                            { title: 'TRAY/BOX', align: 'right' as const, render: (_: unknown, r: ProductionStandardRow) => pkg(r, 'tray')?.trays_per_box ?? '—' },
                        ],
                    },
                    {
                        title: 'Box only',
                        align: 'right' as const,
                        render: (_, r) => pkg(r, 'direct_box')?.nos_per_box ?? '—',
                    },
                    {
                        // The three right-hand spec columns of the sheet:
                        // which carton, which tray, which pouch film.
                        title: 'Packaging materials',
                        children: [
                            { title: 'CARTON', render: (_: unknown, r: ProductionStandardRow) => specCell(r, 'carton_spec') },
                            { title: 'TRAY', render: (_: unknown, r: ProductionStandardRow) => specCell(r, 'tray_spec') },
                            { title: 'POUCH', render: (_: unknown, r: ProductionStandardRow) => specCell(r, 'pouch_spec') },
                        ],
                    },
                    {
                        title: 'Status',
                        render: (_, r) => {
                            const s = STATUS[r.status] ?? { colour: 'default', label: r.status, help: '' };
                            return (
                                <Tooltip title={r.status === 'unresolved' ? (r.unresolved_reason ?? s.help) : s.help}>
                                    <Tag color={s.colour}>{s.label}</Tag>
                                </Tooltip>
                            );
                        },
                    },
                ]}
            />

            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 12, fontSize: 12 }}>
                A cycle time marked <b>*</b> came from a workbook cell holding more than one value; each became its own
                variant rather than being averaged, because the mean of two real cycle times is a rate no machine runs
                at. Hover any tag for the figures behind it.
                {inferredCount > 0 && (
                    <>
                        {' '}A packing spec shown with a{' '}
                        <span style={{ borderBottom: '1px dotted #ad6800' }}>dotted underline</span> was blank in the
                        workbook and filled from a same-family row — {inferredCount}{' '}
                        {inferredCount === 1 ? 'product has' : 'products have'} at least one. Hover it to see which row
                        it came from; it is a reasonable guess, not the factory's word.
                    </>
                )}
            </Typography.Text>

            {/* Mounted only while open, and keyed by row, so each dialog starts
                with nothing selected rather than inheriting the last row's pick. */}
            {attaching !== null && (
                <AttachItemModal key={attaching.id} standard={attaching} onClose={() => setAttaching(null)} />
            )}
            {adding && (
                <NewStandardModal
                    onClose={() => setAdding(false)}
                    initialName={resumeItem?.name ?? undefined}
                />
            )}
        </>
    );
}
