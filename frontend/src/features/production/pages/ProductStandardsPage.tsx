import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Checkbox,
    Col,
    DatePicker,
    Divider,
    Drawer,
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
import type { FormInstance } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useRef, useState, type CSSProperties } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { activePickerOptions } from '@/components/configuration/pickerOptions';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
import { listAllItems } from '@/features/inventory/api';
import {
    buildStartBatchReturnUrl,
    hasStartBatchResume,
    parseStartBatchResume,
} from '@/features/production/startBatchResume';
import type { ProductConfigurationFiguresPayload } from '@/features/production/api';
import {
    addStandardPackaging,
    approveProductionConfiguration,
    attachStandardItem,
    copyProductionConfiguration,
    createProductionConfiguration,
    createProductionStandard,
    listAllMolds,
    listProductionStandards,
    listStandardItemCandidates,
    listStandardMachineExceptions,
    listWorkCenters,
    machineLabel,
    PRODUCT_STANDARDS_PAGE_SIZES,
    saveProductConfigurationFigures,
    type StandardPackagingPayload,
    updateProductionConfiguration,
    updateStandardPackaging,
} from '@/features/production/api';
import ConfigurationReviewPanel from '@/features/production/components/ConfigurationReviewPanel';
import { useProductionSettings } from '@/features/production/packing';
import {
    PACKING_MODE_LABEL,
    attachmentNote,
    fmt,
    missingWords,
    num,
    packagingState,
    packagingsOfMode,
    provisionalSkuTag,
    standardSpec,
    tallyIdentityLabel,
    type PackagingState,
} from '@/features/production/productStandardsConfig';
import type {
    ProductionConfiguration,
    ProductStandardGap,
    ProductStandardGapKey,
    ProductStandardsView,
    ProductStandardsWorkspaceRow,
    StandardPackaging,
    StandardPackagingMode,
    StandardSpecColumn,
} from '@/features/production/types';
import { itemLabel } from '@/lib/itemLabel';
import { showApiError } from '@/lib/showApiError';

/**
 * THE PRODUCT CONFIGURATION WORKSPACE — one screen that answers, for every
 * product the factory makes, "can this run, and if not, what exactly is
 * missing and where do I go?"
 *
 * It replaces three places that used to hold one product's configuration
 * between them: this page (the workbook's figures), Machine Setup → Machine
 * Exceptions (the per-machine approvals), and nowhere at all (the item
 * master's colour and packing, which had no editor). A supervisor no longer
 * has to know which of those a missing figure lives in.
 *
 * ## The three things this screen refuses to do
 *
 *  - **Paraphrase the gate.** Every gap sentence on this page is the string
 *    the Start Batch readiness gate itself speaks (ProductReadinessService's
 *    SENTENCES, which both now read). Rewording them here would mean a
 *    supervisor is told one thing while configuring and another when the
 *    batch is refused.
 *  - **Guess at a Tally identity.** The attach dialog ranks by NAME
 *    similarity and says so where the decision is made; nothing is written
 *    until a person picks. A product with no Tally item still RUNS — only its
 *    voucher waits, and the exact sentence saying so is the backend's.
 *  - **Edit the workbook's own figures.** The standard's cavities, weight and
 *    cycle time are the factory's record and are corrected there. What this
 *    page writes is the ITEM MASTER, which is the other half of the
 *    precedence the gate reads (`standard ?? item`) and the half the app
 *    owns — see ProductConfigurationFiguresModal.
 *
 * ## The three tables this page is NOT
 *
 *  - **Product standards** (this page) — what a product runs to WHEREVER it
 *    runs: cavities, weight, cycle time, packing. From the workbook.
 *  - **Production configuration** — a machine + product + mould approval,
 *    needed only for an exception. Now maintained HERE, in the drawer of the
 *    standard it is an exception to, rather than on a tab of its own.
 *  - **Bills of Material** (/production/boms) — the consumption recipe: how
 *    much resin and masterbatch go into one bottle. Item-level, shown here
 *    read-only, edited only there.
 */

/**
 * The app bar is `position: sticky; top: 0` and carries no height override, so
 * it stands at antd's default Layout.Header height. Browser-proven against the
 * running app rather than assumed: the header measures 64px and the table's
 * sticky header must freeze BELOW it, not under it.
 */
const APP_HEADER_HEIGHT = 64;

const DEFAULT_PAGE_SIZE = PRODUCT_STANDARDS_PAGE_SIZES[0];

/** Figures line up column-wise only if the digits are the same width. */
const numeric = { fontVariantNumeric: 'tabular-nums' } as const;

// `packagingsOfMode`, `fmt`, `num`, `attachmentNote`, `standardSpec` and
// PACKING_MODE_LABEL live in productStandardsConfig.ts (pure,
// vitest-covered) and are imported above; the page keeps only what renders.

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

// ---------------------------------------------------------------------------
// Packing-material specs, and whether the value in the cell was inferred
// ---------------------------------------------------------------------------

const specCell = (r: ProductStandardsWorkspaceRow, column: StandardSpecColumn) => {
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

/**
 * One workbook count column, ONE LINE PER PACKAGING OF THE MODE. Two
 * same-mode packings can coexist on one standard (Phase 5, D1 — a person's
 * 490/box tray beside the sheet's 520), and a column that showed only the
 * first row would hide the second; the lines are in the server's order, so
 * the three columns of a mode read across as rows. "—" for a count no row
 * states — never a filled-in figure.
 */
const packCountCell = (
    r: ProductStandardsWorkspaceRow,
    mode: StandardPackagingMode,
    field: 'nos_per_pouch' | 'pouches_per_box' | 'nos_per_tray' | 'trays_per_box' | 'nos_per_box',
) => {
    const rows = packagingsOfMode(r, mode);
    if (rows.length === 0) return '—';
    if (rows.length === 1) return rows[0][field] ?? '—';
    return (
        <>
            {rows.map((p, i) => (
                <div key={p.id ?? i}>{p[field] ?? '—'}</div>
            ))}
        </>
    );
};

/**
 * A packing that is not yet configured (Phase 5): its counts unstated, or no
 * real Tally item to post as — its own identity, else the product's
 * (DEC-20260810-003). The tag names the missing pieces in words, from the
 * server's keys; a bare "incomplete" would send someone hunting.
 */
function IncompleteTag({ state, style }: { state: PackagingState; style?: CSSProperties }) {
    const words = missingWords(state.missing);
    return (
        <Tooltip title={words === '' ? undefined : `${words} missing.`}>
            <Tag color="orange" style={{ fontWeight: 600, ...style }}>
                {words === '' ? 'INCOMPLETE' : `INCOMPLETE — ${words} missing`}
            </Tag>
        </Tooltip>
    );
}

/**
 * The item's SKU is still the one the Tally pull seeded from its name and
 * nobody has set it (P5-02 `sku_provisional`). Says that and no more: which
 * SKU it should carry is the SKU format programme's answer, the owner's.
 */
function ProvisionalSkuTag({ item }: { item: Parameters<typeof provisionalSkuTag>[0] }) {
    const text = provisionalSkuTag(item);
    if (text === null) return null;
    return (
        <Tooltip title="The SKU was seeded from the Tally item name when the item was pulled, and no person has set it yet. Setting the SKU on the item master clears this.">
            <Tag color="purple">{text}</Tag>
        </Tooltip>
    );
}

// Field-level messages say exactly what the backend refused and why — a
// cavity count outside the machine's capability, an overlapping approval, an
// attach that collides with an existing variant. All are real answers, not
// "unexpected error", and each now prints under the field key it belongs to.
const showSaveError = (error: unknown, title: string) => showApiError(error, title);

// ---------------------------------------------------------------------------
// The gaps, and where each one is closed
// ---------------------------------------------------------------------------

/** The item-master field each gap is closed by. `tally_item` is closed by attaching, not typing. */
type FigureField = keyof ProductConfigurationFiguresPayload;

const GAP_FIELD: Record<ProductStandardGapKey, FigureField | null> = {
    weight: 'nominal_weight_grams',
    cycle_time: 'standard_cycle_time',
    cavities: 'standard_cavities',
    packing: 'nos_per_box',
    colour: 'colour',
    tally_item: null,
};

/** What the button on a gap says. Never "fix this" — always the actual destination. */
const GAP_ACTION_LABEL: Record<ProductStandardGapKey, string> = {
    weight: 'Set the weight',
    cycle_time: 'Set the cycle time',
    cavities: 'Set the cavities',
    packing: 'Set pieces per box',
    colour: 'Set the colour',
    tally_item: 'Attach a Tally item',
};

/**
 * The server's numbered gap list, each line ending in a control that goes
 * somewhere real.
 *
 * The sentences are printed verbatim — they are the gate's own. The only
 * thing this component decides is which control closes each one, and when a
 * standard has no Tally item yet it says so instead of offering an editor
 * that would have nothing to write to: every figure this page writes lives on
 * the ITEM, so the item has to exist first.
 */
function GapList({
    gaps,
    hasItem,
    onFix,
    onAttach,
}: {
    gaps: ProductStandardGap[];
    hasItem: boolean;
    onFix: (field: FigureField) => void;
    onAttach: () => void;
}) {
    return (
        <Space direction="vertical" size={10} style={{ width: '100%' }}>
            {gaps.map((gap) => {
                const field = GAP_FIELD[gap.key];
                // Every editable figure is an item-master field, so without an
                // item there is exactly one honest next step for any of them.
                const attachFirst = field !== null && !hasItem;

                return (
                    <div key={gap.key} style={{ display: 'flex', gap: 10, alignItems: 'baseline' }}>
                        <Typography.Text strong style={{ ...numeric, minWidth: 18 }}>
                            {gap.number}.
                        </Typography.Text>
                        <div style={{ flex: 1 }}>
                            <Typography.Text>{gap.sentence}</Typography.Text>
                            <div style={{ marginTop: 2 }}>
                                <Button
                                    type="link"
                                    size="small"
                                    style={{ padding: 0, height: 'auto' }}
                                    onClick={() => (field === null || attachFirst ? onAttach() : onFix(field))}
                                >
                                    {attachFirst ? 'Attach a Tally item first' : GAP_ACTION_LABEL[gap.key]}
                                </Button>
                            </div>
                        </div>
                    </div>
                );
            })}
        </Space>
    );
}

// ---------------------------------------------------------------------------
// The item-master figures — the half of the product configuration the app owns
// ---------------------------------------------------------------------------

interface FiguresForm {
    nominal_weight_grams?: number;
    standard_cycle_time?: number;
    standard_cavities?: number;
    nos_per_box?: number;
    colour?: string;
}

/**
 * The five figures that close a gap, on the ITEM MASTER.
 *
 * WHY THE ITEM AND NOT THE STANDARD. Every gap the readiness gate reports is a
 * `standard ?? item` question: the workbook standard is consulted first and
 * the item master answers when it is blank. So a gap exists precisely when
 * BOTH are blank, and writing the item closes the gap the gate itself
 * re-evaluates — no second source of truth appears, because the precedence
 * that decides between them already existed and is unchanged. A standard's own
 * figures remain the workbook's record and are still not editable here.
 *
 * Each field therefore shows what the WORKBOOK says beside it. A supervisor
 * typing 26 into "weight" while the standard already states 24 must be able to
 * see that the run will use 24 — that is not a rejection, the item master is
 * used by other screens, but it would be a nasty surprise on the floor.
 */
function ProductConfigurationFiguresModal({
    row,
    focus,
    onClose,
}: {
    row: ProductStandardsWorkspaceRow;
    focus: FigureField;
    onClose: () => void;
}) {
    const queryClient = useQueryClient();
    const [form] = Form.useForm<FiguresForm>();
    const item = row.item;

    const save = useMutation({
        mutationFn: (values: FiguresForm) =>
            saveProductConfigurationFigures(item!.id, {
                // Sent whole, not as a diff: every field is `sometimes` on the
                // backend, an explicit null clears a figure, and a partial
                // save that silently kept an old value would be the harder
                // thing to explain on the floor.
                nominal_weight_grams: values.nominal_weight_grams ?? null,
                standard_cycle_time: values.standard_cycle_time ?? null,
                standard_cavities: values.standard_cavities ?? null,
                nos_per_box: values.nos_per_box ?? null,
                colour: (values.colour ?? '').trim() === '' ? null : values.colour!.trim(),
            }),
        onSuccess: () => {
            // Both masters: the workspace re-reads its gaps from the gate, and
            // every picker in the app holds this item in the all-items cache.
            queryClient.invalidateQueries({ queryKey: ['production', 'standards'] });
            queryClient.invalidateQueries({ queryKey: ['inventory', 'items'] });
            onClose();
        },
        onError: (error: any) => showSaveError(error, 'Could not save these figures'),
    });

    if (item === null) return null;

    const standardSays = (label: string, value: string | number | null | undefined) =>
        value === null || value === undefined || value === '' ? (
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                The workbook leaves {label} blank for this product.
            </Typography.Text>
        ) : (
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                The workbook standard says <b style={numeric}>{value}</b> — that is what a run uses.
            </Typography.Text>
        );

    return (
        <Modal
            open
            width={640}
            title={`Item master — ${itemLabel(item)}`}
            okText="Save figures"
            confirmLoading={save.isPending}
            onCancel={onClose}
            onOk={() => form.validateFields().then((values) => save.mutate(values))}
            destroyOnHidden
        >
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 14 }}
                message="These are the item master's figures, not the workbook's"
                description={
                    <>
                        A run reads the factory workbook standard first and falls back to these when it is blank —
                        which is why filling one in here closes the gap. The workbook's own figures are corrected in
                        the workbook, and this screen never overwrites them.
                    </>
                }
            />

            <Form<FiguresForm>
                form={form}
                layout="vertical"
                requiredMark={false}
                initialValues={{
                    nominal_weight_grams: num(item.nominal_weight_grams),
                    standard_cycle_time: num(item.standard_cycle_time),
                    standard_cavities: item.standard_cavities ?? undefined,
                    nos_per_box: item.nos_per_box ?? undefined,
                    colour: item.colour ?? undefined,
                }}
            >
                <Row gutter={12}>
                    <Col xs={24} sm={8}>
                        <Form.Item
                            name="nominal_weight_grams"
                            label="Weight of one piece (g)"
                            extra={standardSays('the weight', row.unit_weight_grams)}
                        >
                            <InputNumber
                                min={0.0001}
                                step={0.1}
                                style={{ width: '100%' }}
                                autoFocus={focus === 'nominal_weight_grams'}
                            />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={8}>
                        <Form.Item
                            name="standard_cycle_time"
                            label="Cycle time (s)"
                            extra={standardSays('the cycle time', row.cycle_time)}
                        >
                            <InputNumber
                                min={0.1}
                                step={0.1}
                                style={{ width: '100%' }}
                                autoFocus={focus === 'standard_cycle_time'}
                            />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={8}>
                        <Form.Item
                            name="standard_cavities"
                            label="Cavities"
                            extra={standardSays('the cavity count', row.cavities)}
                        >
                            <InputNumber
                                min={1}
                                precision={0}
                                style={{ width: '100%' }}
                                autoFocus={focus === 'standard_cavities'}
                            />
                        </Form.Item>
                    </Col>
                </Row>

                <Row gutter={12}>
                    <Col xs={24} sm={12}>
                        <Form.Item
                            name="nos_per_box"
                            label="Pieces per box"
                            // The one figure whose fix is worth spelling out:
                            // it closes the packing gap without the standard
                            // gaining a packing MODE, because a packaging row
                            // can only be written by the workbook import.
                            extra={
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    {row.packagings.length > 0
                                        ? `The workbook packs this ${row.packagings
                                              .map((p) => PACKING_MODE_LABEL[p.mode].toLowerCase())
                                              .join(' / ')}.`
                                        : 'The workbook records no packing mode for this product. Setting the count here lets boxes be converted to pieces; the packing MODE still comes from the workbook.'}
                                </Typography.Text>
                            }
                        >
                            <InputNumber
                                min={1}
                                precision={0}
                                style={{ width: '100%' }}
                                autoFocus={focus === 'nos_per_box'}
                            />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={12}>
                        <Form.Item
                            name="colour"
                            label="Colour"
                            extra={
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    Drives the masterbatch suggestion and the amber/clear scrap item. Write{' '}
                                    <b>Clear</b> for a bottle that takes no masterbatch — blank is "nobody has said",
                                    which is not the same thing.
                                </Typography.Text>
                            }
                        >
                            <Input maxLength={32} placeholder="Clear, Amber, Blue…" autoFocus={focus === 'colour'} />
                        </Form.Item>
                    </Col>
                </Row>
            </Form>
        </Modal>
    );
}

// ---------------------------------------------------------------------------
// Machine exceptions — the per-machine approvals, on the product they override
// ---------------------------------------------------------------------------

interface ExceptionForm {
    work_center_id?: number;
    mold_id?: number | null;
    colour?: string;
    unit_weight_grams?: number;
    default_cycle_time?: number;
    cycle_time_min?: number;
    cycle_time_max?: number;
    default_cavities?: number;
    effective_from?: dayjs.Dayjs;
    notes?: string;
}

/**
 * Create or edit ONE machine exception, against this product's item.
 *
 * An APPROVED configuration is never edited — the backend refuses it, because
 * an approval is a decision about a specific set of figures. The list offers
 * "Copy to draft" instead, which is the same refusal expressed as a next step.
 */
function MachineExceptionModal({
    itemId,
    productName,
    configuration,
    onClose,
}: {
    itemId: number;
    productName: string;
    configuration: ProductionConfiguration | null;
    onClose: () => void;
}) {
    const queryClient = useQueryClient();
    const [form] = Form.useForm<ExceptionForm>();

    // Active only: a retired machine must not be selectable for a new
    // exception, or the exception is unusable the moment it is approved.
    const machines = useQuery({
        queryKey: ['production', 'work-centers', 'active'],
        queryFn: () => listWorkCenters(true),
    });
    const molds = useQuery({ queryKey: ['production', 'molds', 'all'], queryFn: listAllMolds });

    const save = useMutation({
        mutationFn: (values: ExceptionForm) => {
            const payload = {
                work_center_id: values.work_center_id!,
                item_id: itemId,
                mold_id: values.mold_id ?? null,
                colour: (values.colour ?? '').trim() === '' ? null : values.colour!.trim(),
                unit_weight_grams: values.unit_weight_grams ?? null,
                default_cycle_time: values.default_cycle_time ?? null,
                cycle_time_min: values.cycle_time_min ?? null,
                cycle_time_max: values.cycle_time_max ?? null,
                default_cavities: values.default_cavities ?? null,
                effective_from: values.effective_from ? values.effective_from.format('YYYY-MM-DD') : null,
                notes: (values.notes ?? '').trim() === '' ? null : values.notes!.trim(),
            };

            return configuration === null
                ? createProductionConfiguration(payload)
                : updateProductionConfiguration(configuration.id, payload);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'standards'] });
            queryClient.invalidateQueries({ queryKey: ['production', 'configurations'] });
            onClose();
        },
        onError: (error: any) => showSaveError(error, 'Could not save this machine exception'),
    });

    return (
        <Modal
            open
            width={620}
            title={configuration === null ? `New machine exception — ${productName}` : `Edit draft — ${productName}`}
            okText={configuration === null ? 'Create draft' : 'Save draft'}
            confirmLoading={save.isPending}
            onCancel={onClose}
            onOk={() => form.validateFields().then((values) => save.mutate(values))}
            destroyOnHidden
        >
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 14 }}
                message="Saved as a draft. It reaches the shop floor only once approved."
                description="An exception is only needed when this product runs differently on one machine than the standard says. Everywhere else the standard applies on its own."
            />

            <Form<ExceptionForm>
                form={form}
                layout="vertical"
                initialValues={
                    configuration === null
                        ? undefined
                        : {
                              work_center_id: configuration.work_center.id,
                              mold_id: configuration.mold?.id ?? null,
                              colour: configuration.colour ?? undefined,
                              unit_weight_grams: num(configuration.unit_weight_grams),
                              default_cycle_time: num(configuration.default_cycle_time),
                              cycle_time_min: num(configuration.cycle_time_min),
                              cycle_time_max: num(configuration.cycle_time_max),
                              default_cavities: configuration.default_cavities ?? undefined,
                              effective_from: configuration.effective_from
                                  ? dayjs(configuration.effective_from)
                                  : undefined,
                              notes: configuration.notes ?? undefined,
                          }
                }
            >
                <Form.Item
                    name="work_center_id"
                    label="Machine"
                    rules={[{ required: true, message: 'An exception is always about one machine.' }]}
                >
                    <Select
                        showSearch
                        optionFilterProp="label"
                        loading={machines.isLoading}
                        options={(machines.data?.data ?? []).map((m) => ({ value: m.id, label: machineLabel(m) }))}
                    />
                </Form.Item>

                <Row gutter={12}>
                    <Col xs={24} sm={12}>
                        <Form.Item name="mold_id" label="Mould (optional)">
                            {/* WS-B: a RETIRED mould cannot govern a new
                                configuration, so it is not offered. An
                                existing draft that already names one keeps it
                                on screen, marked and unselectable — the server
                                lets an edit RE-POINT a retired mould but never
                                keep it, and this picker says the same thing
                                before the save is attempted. */}
                            <Select
                                allowClear
                                showSearch
                                optionFilterProp="label"
                                loading={molds.isLoading}
                                options={activePickerOptions(molds.data?.data, {
                                    isActive: (m) => m.status !== 'retired',
                                    option: (m) => ({ value: m.id, label: m.name }),
                                    keep: configuration?.mold?.id ?? null,
                                })}
                            />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={12}>
                        <Form.Item
                            name="colour"
                            label="Colour (optional)"
                            extra="Leave blank for an exception that applies to every colour of this product."
                        >
                            <Input maxLength={32} placeholder="Amber, Clear…" />
                        </Form.Item>
                    </Col>
                </Row>

                <Row gutter={12}>
                    <Col xs={24} sm={8}>
                        <Form.Item name="default_cycle_time" label="Cycle time (s)">
                            <InputNumber min={0.1} step={0.1} style={{ width: '100%' }} />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={8}>
                        <Form.Item name="default_cavities" label="Cavities">
                            <InputNumber min={1} precision={0} style={{ width: '100%' }} />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={8}>
                        <Form.Item name="unit_weight_grams" label="Weight (g)">
                            <InputNumber min={0.0001} step={0.1} style={{ width: '100%' }} />
                        </Form.Item>
                    </Col>
                </Row>

                <Row gutter={12}>
                    <Col xs={12} sm={8}>
                        <Form.Item name="cycle_time_min" label="Cycle min (s)">
                            <InputNumber min={0.1} step={0.1} style={{ width: '100%' }} />
                        </Form.Item>
                    </Col>
                    <Col xs={12} sm={8}>
                        <Form.Item name="cycle_time_max" label="Cycle max (s)">
                            <InputNumber min={0.1} step={0.1} style={{ width: '100%' }} />
                        </Form.Item>
                    </Col>
                    <Col xs={24} sm={8}>
                        <Form.Item name="effective_from" label="Effective from">
                            <DatePicker style={{ width: '100%' }} />
                        </Form.Item>
                    </Col>
                </Row>

                <Form.Item name="notes" label="Note (optional)">
                    <Input.TextArea rows={2} placeholder="Why this machine runs this product differently." />
                </Form.Item>

                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Cycle time, cavities and weight are all required before this can be approved, and the cavity count
                    must be one the machine is capable of — save what you know now and fill the rest in as the factory
                    confirms it.
                </Typography.Text>
            </Form>
        </Modal>
    );
}

/**
 * This product's machine exceptions, with their whole approval flow.
 *
 * The list comes from the per-standard endpoint, which is a second VIEW of the
 * configurations the exceptions page already served — same service, same
 * machine order — and every write still goes through the configuration
 * endpoints. Nothing about how an approval happens changed; only where it can
 * be reached from.
 */
function MachineExceptions({ standardId, itemId, productName }: { standardId: number; itemId: number | null; productName: string }) {
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState<ProductionConfiguration | null>(null);
    const [creating, setCreating] = useState(false);

    const { data, isLoading, isError } = useQuery({
        queryKey: ['production', 'standards', 'machine-exceptions', standardId],
        queryFn: () => listStandardMachineExceptions(standardId),
    });

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['production', 'standards'] });
        queryClient.invalidateQueries({ queryKey: ['production', 'configurations'] });
    };
    const onError = (error: any) => showSaveError(error, 'Could not save this machine exception');

    const approve = useMutation({ mutationFn: approveProductionConfiguration, onSuccess: invalidate, onError });
    const copy = useMutation({ mutationFn: copyProductionConfiguration, onSuccess: invalidate, onError });

    if (itemId === null) {
        // Configurations are keyed on the item, so there is nothing to list and
        // nothing that could be created. Said as the next step, not as an error.
        return (
            <Typography.Text type="secondary">
                A machine exception is recorded against the Tally item, so this product needs its item attached before
                one can be created.
            </Typography.Text>
        );
    }

    if (isLoading) {
        return (
            <Space>
                <Spin size="small" />
                <Typography.Text type="secondary">Looking for machine exceptions…</Typography.Text>
            </Space>
        );
    }

    if (isError) {
        return (
            <Typography.Text type="secondary">
                Could not read this product's machine exceptions just now. Reopen this drawer to try again.
            </Typography.Text>
        );
    }

    const rows = data ?? [];

    return (
        <>
            <Space style={{ marginBottom: 8 }} wrap>
                <Button size="small" type="primary" onClick={() => setCreating(true)}>
                    New machine exception
                </Button>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    {rows.length === 0
                        ? 'Runs to the standard above on every machine it may run on.'
                        : 'Only an approved exception, effective today, drives a batch.'}
                </Typography.Text>
            </Space>

            {rows.length > 0 && (
                <Table<ProductionConfiguration>
                    scroll={{ x: 'max-content' }}
                    rowKey="id"
                    size="small"
                    pagination={false}
                    dataSource={rows}
                    columns={[
                        {
                            title: 'Machine',
                            render: (_, c) =>
                                [c.work_center.code, c.work_center.name].filter(Boolean).join(' — ') ||
                                `#${c.work_center.id}`,
                        },
                        {
                            title: 'Cycle time (s)',
                            align: 'right' as const,
                            render: (_, c) => (
                                <span style={numeric}>
                                    {c.cycle_time_min !== null && c.cycle_time_max !== null
                                        ? `${fmt(c.default_cycle_time)} (${fmt(c.cycle_time_min)}–${fmt(c.cycle_time_max)})`
                                        : fmt(c.default_cycle_time)}
                                </span>
                            ),
                        },
                        {
                            title: 'Cavities',
                            align: 'right' as const,
                            render: (_, c) => <span style={numeric}>{c.default_cavities ?? '—'}</span>,
                        },
                        {
                            title: 'Weight (g)',
                            align: 'right' as const,
                            render: (_, c) => <span style={numeric}>{fmt(c.unit_weight_grams)}</span>,
                        },
                        {
                            title: 'Mould / colour',
                            render: (_, c) => [c.mold?.name, c.colour].filter(Boolean).join(' · ') || 'Any',
                        },
                        {
                            title: 'Status',
                            render: (_, c) => (
                                <Space direction="vertical" size={2}>
                                    {/* The one status vocabulary: Approved is
                                        this master's ACTIVE, inactive its
                                        RETIRED, and a draft is neither — which
                                        is exactly what ActiveFlag keeps apart
                                        on the server. */}
                                    <ConfigurationStatusTag entity="production-configuration" row={c} />
                                    {/* The factory's own wording, shown verbatim
                                        so nobody mistakes an unreviewed
                                        candidate for a decision. */}
                                    {c.confirmation_status && (
                                        <Typography.Text type="secondary" style={{ fontSize: 11 }}>
                                            {c.confirmation_status}
                                        </Typography.Text>
                                    )}
                                </Space>
                            ),
                        },
                        {
                            title: 'Actions',
                            render: (_, c) => {
                                {/* Approve and Copy-to-draft are this module's
                                    OWN acts, not lifecycle ones, and the
                                    approval window they govern is unchanged. */}
                                const ownActs = (
                                    <>
                                        {c.status === 'draft' && (
                                            <Button
                                                size="small"
                                                type="primary"
                                                loading={approve.isPending}
                                                onClick={() => approve.mutate(c.id)}
                                            >
                                                Approve
                                            </Button>
                                        )}
                                        {/* An approved row is never edited in
                                            place — copy-to-draft is the same
                                            refusal expressed as a next step. */}
                                        <Tooltip
                                            title={
                                                c.status === 'approved'
                                                    ? 'An approved exception cannot be edited. This makes an editable draft with the same figures.'
                                                    : 'Make another draft with these figures.'
                                            }
                                        >
                                            <Button size="small" loading={copy.isPending} onClick={() => copy.mutate(c.id)}>
                                                Copy to draft
                                            </Button>
                                        </Tooltip>
                                    </>
                                );

                                return (
                                    <ConfigurationActionsCell
                                        entity="production-configuration"
                                        id={c.id}
                                        can={c.can}
                                        recordName={
                                            [c.work_center.code, c.work_center.name].filter(Boolean).join(' — ') ||
                                            `#${c.work_center.id}`
                                        }
                                        // Only a draft is editable in place —
                                        // the module's own rule, expressed as
                                        // an act this SCREEN does not offer.
                                        // It never overrules `can`.
                                        onEdit={c.status === 'draft' ? () => setEditing(c) : undefined}
                                        extra={ownActs}
                                    />
                                );
                            },
                        },
                    ]}
                />
            )}

            {(creating || editing !== null) && (
                <MachineExceptionModal
                    key={editing?.id ?? 'new'}
                    itemId={itemId}
                    productName={productName}
                    configuration={editing}
                    onClose={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            )}
        </>
    );
}

// ---------------------------------------------------------------------------
// Attaching a Tally item to a standard that has none
// ---------------------------------------------------------------------------

/**
 * Pick the Tally item an unattached standard means.
 *
 * The candidate list is name similarity — `similar_text` over normalised
 * names, plus whether the leading size token agrees. It knows nothing about
 * what the factory makes, so a 90% match can be the wrong bottle and a 40%
 * match can be the right one. The dialog says this where the choice happens;
 * a person confirms, and nothing is written until they do.
 */
function AttachItemModal({ standard, onClose }: { standard: ProductStandardsWorkspaceRow; onClose: () => void }) {
    const queryClient = useQueryClient();
    const [selected, setSelected] = useState<number | null>(null);

    // An already-attached standard makes this a RE-POINT (DEC-20260810-003:
    // the identity is editable configuration) — a confirmed act with its own
    // wording, because it changes whose figures every FUTURE run uses.
    // History is safe either way: completed batches froze the identity they
    // posted under, and posted vouchers are never rewritten.
    const reattaching = standard.item != null;

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
        mutationFn: (itemId: number) => attachStandardItem(standard.id, itemId, reattaching),
        onSuccess: () => {
            // The whole standards prefix, not this page+view: the other views'
            // caches and the summary chips are wrong the moment a row gains
            // its item.
            queryClient.invalidateQueries({ queryKey: ['production', 'standards'] });
            onClose();
        },
        onError: (error: any) => showSaveError(error, 'Could not attach this item'),
    });

    return (
        <Modal
            open
            title={reattaching ? 'Change the Tally identity' : 'Attach a Tally item'}
            onCancel={onClose}
            okText={reattaching ? 'Change the identity' : 'Attach this item'}
            okButtonProps={{ disabled: selected === null, ...(reattaching ? { danger: true } : {}) }}
            confirmLoading={attach.isPending}
            onOk={() => selected !== null && attach.mutate(selected)}
            width={620}
        >
            <Typography.Paragraph style={{ marginBottom: 8 }}>
                The workbook calls this product <b>{standard.source_product_name}</b>
                {standard.source_reference ? ` (SL ${standard.source_reference})` : ''}. Which Tally item is that?
            </Typography.Paragraph>

            {reattaching && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message={`Currently attached to "${standard.item?.name ?? ''}"`}
                    description="Changing it re-points every FUTURE run of this product at the new item. Completed batches and vouchers already posted keep the identity they recorded — history is never rewritten. The change is noted on the standard with your name."
                />
            )}

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

/**
 * THE EXACT TALLY PRODUCT, PICKED BY A PERSON — never matched by the software.
 *
 * The list is the synced Tally catalogue itself, searched by name or SKU. There
 * is no ranking, no "closest match", and no create-if-missing: every option in
 * the list is a real Tally item that already exists, so choosing one cannot
 * mint a duplicate ERP product for something Tally already carries. That
 * duplication is the failure this replaces — a second item for a bottle Tally
 * already knows means two stock balances and a voucher naming the wrong one.
 *
 * Leaving it blank is a legitimate answer. A product with no Tally item still
 * runs; only the voucher waits, and the label says exactly that rather than
 * implying the floor is blocked.
 */
function TallyItemPicker({ form }: { form: FormInstance<NewStandardForm> }) {
    const { data: items, isFetching } = useQuery({
        queryKey: ['inventory', 'items', 'all'],
        queryFn: listAllItems,
    });

    const chosenId = Form.useWatch('item_id', form);
    const chosen = items?.data.find((i) => i.id === chosenId) ?? null;

    const options = (items?.data ?? [])
        .filter((i) => i.is_active)
        .map((i) => ({
            value: i.id,
            // Searched on, so it must contain both names the factory uses.
            label: `${i.name}${i.sku && i.sku !== i.name ? ` · ${i.sku}` : ''}`,
            hasGuid: Boolean(i.tally_stock_item_guid),
        }));

    return (
        <Form.Item
            name="item_id"
            label="Tally product"
            extra="Search the products already synced from Tally. Leave blank if this product is not in Tally yet."
        >
            <Select
                allowClear
                showSearch
                loading={isFetching}
                optionFilterProp="label"
                placeholder="Search by product name or code…"
                options={options}
                optionRender={(option) => (
                    <Space>
                        <span>{option.label}</span>
                        {(option.data as { hasGuid: boolean }).hasGuid ? (
                            <Tag color="green">In Tally</Tag>
                        ) : (
                            <Tag color="orange">Tally mapping pending</Tag>
                        )}
                    </Space>
                )}
                notFoundContent={
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        No Tally product matches. Leave this blank — production can still run, and the voucher waits
                        until the product exists in Tally.
                    </Typography.Text>
                }
            />
            {chosen && (
                <Typography.Paragraph style={{ fontSize: 12, marginTop: 6, marginBottom: 0 }}>
                    {chosen.tally_stock_item_guid ? (
                        <Typography.Text type="success">
                            Exact Tally product: <b>{chosen.name}</b> — vouchers will sync.
                        </Typography.Text>
                    ) : (
                        <Typography.Text type="warning">
                            <b>Tally mapping pending.</b> {chosen.name} is not in Tally yet, so production can run but
                            the voucher will not sync until it exists there.
                        </Typography.Text>
                    )}
                </Typography.Paragraph>
            )}
        </Form.Item>
    );
}

interface NewStandardForm {
    source_product_name: string;
    /**
     * The exact Tally product this standard is for, picked from the synced
     * catalogue. Optional: a product with no Tally item still RUNS, only its
     * voucher waits — so a standard may be prepared before the mapping exists.
     */
    item_id?: number;
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
        <Typography.Text style={{ fontSize: 12, ...numeric }}>
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
            item_id: v.item_id ?? null,
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
                For a product the workbook does not carry. Pick its exact Tally product below if it already exists —
                that is the whole point of the field: it reuses the item Tally already synced instead of creating a
                second one that then has to be reconciled.
            </Typography.Paragraph>

            <TallyItemPicker form={form} />

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
                        <Form.Item name="pouch_spec" label="Pouch">
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
// The drawer: one product's WHOLE configuration
// ---------------------------------------------------------------------------

/** A labelled block in the drawer. Quiet caption, then the answer. */
function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div style={{ marginBottom: 10 }}>
            <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                {label}
            </Typography.Text>
            <div>{children}</div>
        </div>
    );
}

function ProductConfigurationDrawer({
    row: opened,
    stillListed,
    onClose,
    onFix,
    onAttach,
}: {
    row: ProductStandardsWorkspaceRow;
    stillListed: boolean;
    onClose: () => void;
    onFix: (row: ProductStandardsWorkspaceRow, field: FigureField) => void;
    onAttach: (row: ProductStandardsWorkspaceRow) => void;
}) {
    // Packing-option editing (DEC-20260810-003): `'new'` = the add flow, a
    // packaging = correcting that one (counts and/or its Tally identity).
    const [editingPackaging, setEditingPackaging] = useState<StandardPackaging | 'new' | null>(null);

    /**
     * The drawer reads its OWN copy of the product, because a save can move
     * the row out of the view behind it — and a drawer that then went on
     * listing a gap the person had just closed would be the worst screen in
     * the app: it would teach them that fixing things does not work.
     *
     * Keyed under the standards prefix, so the same invalidation every save
     * already fires refreshes it. `search` is the product's own name, which
     * always matches itself; the id decides which row of that name it is.
     *
     * The page size is a BOUND, not a magic number: it has to exceed the
     * number of standards that can share one product name, and the whole
     * master is about a hundred rows. If this master ever grows past that,
     * the fallback below degrades to the row as it was opened — stale, but
     * never wrong about a different product.
     */
    const { data: fresh } = useQuery({
        queryKey: ['production', 'standards', 'one', opened.id],
        queryFn: () =>
            listProductionStandards({ view: 'all', search: opened.source_product_name, per_page: 100 }).then(
                (page) => page.data.find((r) => r.id === opened.id) ?? null,
            ),
    });

    const row = fresh ?? opened;
    const item = row.item;

    return (
        <Drawer
            open
            width="min(100vw, 760px)"
            onClose={onClose}
            title={row.source_product_name}
            extra={
                row.ready ? (
                    <Tag color="green">Production ready</Tag>
                ) : (
                    <Tag color="gold">{row.gaps.length} to fix</Tag>
                )
            }
        >
            {/* A save can move a product out of the view it was opened from.
                Saying so beats a drawer that silently describes a row the
                table behind it no longer shows. */}
            {!stillListed && (
                <Alert
                    type="success"
                    showIcon
                    style={{ marginBottom: 14 }}
                    message="Saved — this product no longer matches the view behind this drawer"
                    description="Switch to All (or Production ready) to find it again."
                />
            )}

            {row.gaps.length > 0 && (
                <>
                    <Typography.Title level={5} style={{ marginTop: 0 }}>
                        What is missing
                    </Typography.Title>
                    <GapList
                        gaps={row.gaps}
                        hasItem={item !== null}
                        onFix={(field) => onFix(row, field)}
                        onAttach={() => onAttach(row)}
                    />
                    <Divider style={{ margin: '16px 0' }} />
                </>
            )}

            <Typography.Title level={5} style={{ marginTop: 0 }}>
                Standard figures
            </Typography.Title>
            <Row gutter={12}>
                <Col xs={8}>
                    <Field label="Cavities">
                        <span style={numeric}>{row.cavities ?? '—'}</span>
                    </Field>
                </Col>
                <Col xs={8}>
                    <Field label="Weight (g)">
                        <span style={numeric}>{fmt(row.unit_weight_grams)}</span>
                    </Field>
                </Col>
                <Col xs={8}>
                    <Field label="Cycle time (s)">
                        <span style={numeric}>{fmt(row.cycle_time)}</span>
                    </Field>
                </Col>
            </Row>
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                From the factory workbook{row.source_reference ? ` (SL ${row.source_reference})` : ''} — corrected
                there, never here. A run reads these first and falls back to the item master when one is blank.
                {item !== null && (
                    <>
                        {' '}
                        <Button
                            type="link"
                            size="small"
                            style={{ padding: 0, height: 'auto' }}
                            onClick={() => onFix(row, 'nominal_weight_grams')}
                        >
                            Edit the item master's figures
                        </Button>
                    </>
                )}
            </Typography.Text>

            <Divider style={{ margin: '16px 0' }} />

            <Typography.Title level={5} style={{ marginTop: 0 }}>
                Packing
            </Typography.Title>
            {row.packagings.length === 0 ? (
                <Typography.Text type="secondary">
                    The workbook records no packing mode for this product.
                </Typography.Text>
            ) : (
                <Space direction="vertical" size={6} style={{ width: '100%' }}>
                    {row.packagings.map((p) => {
                        // The product's item is the fallback identity
                        // (DEC-20260810-003), so it is part of the verdict.
                        const state = packagingState(p, item);
                        return (
                            <div key={p.id}>
                                <Typography.Text style={numeric}>
                                    {PACKING_MODE_LABEL[p.mode]} — <b>{p.nos_per_box ?? '—'}</b> pieces per box
                                    {p.mode === 'pouch' && p.nos_per_pouch ? ` (${p.nos_per_pouch}/pouch × ${p.pouches_per_box ?? '—'})` : ''}
                                    {p.mode === 'tray' && p.nos_per_tray ? ` (${p.nos_per_tray}/tray × ${p.trays_per_box ?? '—'})` : ''}
                                    {p.id === row.resolved_packaging_id ? ' · used by a run' : ''}
                                </Typography.Text>
                                {/* Configured or not (Phase 5): counts stated AND
                                    a real Tally item to post as (its own, else
                                    the product's). The tag carries the missing
                                    pieces in words, so "incomplete" is never a
                                    bare verdict. */}
                                {!state.complete && <IncompleteTag state={state} style={{ marginLeft: 8 }} />}
                                {/* Which Tally item THIS packing posts as
                                    (DEC-20260810-003): "sku · name" when it has
                                    one of its own; otherwise the fallback is
                                    stated in so many words, with the edit right
                                    beside it — an unknown identity is a
                                    question on screen, never a guess. */}
                                <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                                    {p.tally_item ? (
                                        <>Tally identity: <b>{tallyIdentityLabel(p.tally_item)}</b></>
                                    ) : (
                                        <>
                                            {tallyIdentityLabel(null)} of its own — posts to Tally as the product's item
                                            {item ? <> (<b>{tallyIdentityLabel(item)}</b>)</> : ' (none attached yet)'}
                                        </>
                                    )}
                                    {' '}
                                    <Button
                                        type="link"
                                        size="small"
                                        style={{ padding: 0, height: 'auto' }}
                                        onClick={() => setEditingPackaging(p)}
                                    >
                                        {p.tally_item ? 'Change' : 'Set the Tally identity'}
                                    </Button>
                                </Typography.Text>
                                {/* A packing option is a configuration record
                                    of its own: it is nested under its standard,
                                    so the endpoint is built from the parent id
                                    rather than guessed. Editing it is the
                                    modal above; the lifecycle acts are the
                                    server's to allow. */}
                                <ConfigurationActionsCell
                                    entity="production-standard-packaging"
                                    id={p.id}
                                    parentId={row.id}
                                    can={p.can}
                                    recordName={p.label}
                                    onEdit={() => setEditingPackaging(p)}
                                />
                            </div>
                        );
                    })}
                </Space>
            )}
            <div style={{ marginTop: 8 }}>
                <Button size="small" onClick={() => setEditingPackaging('new')}>
                    Add a packing option
                </Button>
            </div>
            {editingPackaging !== null && (
                <PackagingEditModal
                    standardId={row.id}
                    productItemName={item?.name ?? null}
                    packaging={editingPackaging === 'new' ? null : editingPackaging}
                    onClose={() => setEditingPackaging(null)}
                />
            )}
            <div style={{ marginTop: 4 }}>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Item master pieces per box: <b style={numeric}>{item?.nos_per_box ?? 'not set'}</b> — used when the
                    workbook states no packing.
                    {item !== null && (
                        <>
                            {' '}
                            <Button
                                type="link"
                                size="small"
                                style={{ padding: 0, height: 'auto' }}
                                onClick={() => onFix(row, 'nos_per_box')}
                            >
                                Set pieces per box
                            </Button>
                        </>
                    )}
                </Typography.Text>
            </div>

            <Divider style={{ margin: '16px 0' }} />

            <Row gutter={16}>
                <Col xs={24} sm={12}>
                    <Typography.Title level={5} style={{ marginTop: 0 }}>
                        Required colour
                    </Typography.Title>
                    <Field label="Item master colour">
                        {item?.colour ? (
                            <Tag>{item.colour}</Tag>
                        ) : (
                            <Typography.Text type="secondary">Not set</Typography.Text>
                        )}
                        {item !== null && (
                            <Button
                                type="link"
                                size="small"
                                style={{ padding: 0, height: 'auto', marginLeft: 8 }}
                                onClick={() => onFix(row, 'colour')}
                            >
                                {item.colour ? 'Change' : 'Set the colour'}
                            </Button>
                        )}
                    </Field>
                </Col>
                <Col xs={24} sm={12}>
                    <Typography.Title level={5} style={{ marginTop: 0 }}>
                        Active recipe
                    </Typography.Title>
                    <Field label="What one piece consumes">
                        {row.active_recipe ? (
                            <>
                                {row.active_recipe.name}
                                {row.active_recipe.version ? ` · v${row.active_recipe.version}` : ''}
                            </>
                        ) : (
                            <Typography.Text type="secondary">No active recipe</Typography.Text>
                        )}
                    </Field>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        Recipes belong to the item and are maintained on{' '}
                        <Link to="/production/boms">Bills of Material</Link>. A missing recipe does not stop a batch —
                        it stops the material consumption being calculated for you.
                    </Typography.Text>
                </Col>
            </Row>

            <Divider style={{ margin: '16px 0' }} />

            <Typography.Title level={5} style={{ marginTop: 0 }}>
                Tally identity
            </Typography.Title>
            {item ? (
                <Field label="Attached Tally item">
                    <Space size={6} wrap>
                        <span>{itemLabel(item)}</span>
                        {row.tally.guid_present ? (
                            <Tag color="green">in Tally</Tag>
                        ) : (
                            <Tag color="orange">not in Tally</Tag>
                        )}
                        <ProvisionalSkuTag item={item} />
                        {/* Editable identity (DEC-20260810-003) — the modal
                            asks for confirmation and the backend records the
                            re-point with a name and date. */}
                        <Button
                            type="link"
                            size="small"
                            style={{ padding: 0, height: 'auto' }}
                            onClick={() => onAttach(row)}
                        >
                            Change
                        </Button>
                    </Space>
                    {attachmentNote(row) !== null && (
                        <Typography.Text type="secondary" style={{ fontSize: 11, display: 'block' }}>
                            {attachmentNote(row)}
                        </Typography.Text>
                    )}
                </Field>
            ) : (
                <Space size={8} wrap style={{ marginBottom: 6 }}>
                    <Tag>not attached</Tag>
                    <Button size="small" onClick={() => onAttach(row)}>
                        Attach a Tally item
                    </Button>
                </Space>
            )}
            {/* The backend's own sentence, verbatim. Production is not blocked
                by this and the wording says so — a screen that said "not
                ready" here would refuse work the floor is allowed to do. */}
            {row.tally.sentence !== null && (
                <Alert type="warning" showIcon message={row.tally.sentence} style={{ marginTop: 4 }} />
            )}

            <Divider style={{ margin: '16px 0' }} />

            <Typography.Title level={5} style={{ marginTop: 0 }}>
                Machine exceptions
            </Typography.Title>
            <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
                A machine that runs <b>{row.source_product_name}</b> to figures of its own. Everywhere else, the
                standard above applies — most products need none of these.
            </Typography.Paragraph>
            <MachineExceptions
                standardId={row.id}
                itemId={item?.id ?? null}
                productName={row.source_product_name}
            />
        </Drawer>
    );
}

// ---------------------------------------------------------------------------

/**
 * `embedded` is set when this renders as the Product Standards TAB of
 * Production Configuration, which is now the only way a user reaches it. All
 * it suppresses is the page-level heading — the workspace already sits under
 * a tab labelled "Product Standards", and repeating it as an H3 two lines
 * below reads as two screens stacked on top of each other.
 *
 * Nothing else is conditional on it. The data, the endpoints, the filters,
 * the drawer and the permission checks are identical in both modes, because
 * "preserve all data and permissions" is only true if there is no second code
 * path that could quietly stop being.
 */
export default function ProductStandardsPage({ embedded = false }: { embedded?: boolean }) {
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

    /**
     * Production-ready is the default view — it answers the question the floor
     * asks. EXCEPT on arrival from a blocked Start Batch, which is by
     * construction about a product that is NOT ready: landing that supervisor
     * on the ready view would show them an empty table and a page that looks
     * broken. Initialised from the URL rather than corrected in an effect, so
     * the wrong view is never even fetched.
     */
    const [view, setView] = useState<ProductStandardsView>(() =>
        hasStartBatchResume(new URLSearchParams(window.location.search), 'configure') ? 'all' : 'ready',
    );
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState<number>(DEFAULT_PAGE_SIZE);
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [missingTally, setMissingTally] = useState(false);
    const [packingMode, setPackingMode] = useState<StandardPackagingMode | undefined>();
    const [machineId, setMachineId] = useState<number | undefined>();

    const [attaching, setAttaching] = useState<ProductStandardsWorkspaceRow | null>(null);
    const [adding, setAdding] = useState(false);
    const [openRow, setOpenRow] = useState<ProductStandardsWorkspaceRow | null>(null);
    const [figures, setFigures] = useState<{ row: ProductStandardsWorkspaceRow; focus: FigureField } | null>(null);

    // Typing goes to the server, so it waits for a pause rather than firing a
    // request per keystroke.
    useEffect(() => {
        const timer = setTimeout(() => {
            setSearch(searchInput.trim());
            setPage(1);
        }, 300);
        return () => clearTimeout(timer);
    }, [searchInput]);

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
        setSearchInput(resumeItem.name);
        // Set directly as well as through the debounce, so the first fetch
        // after arrival is already the filtered one.
        setSearch(resumeItem.name);
        setPage(1);
    }, [resumeDraft, resumeItem, resumeQuery]);

    const { data, isFetching } = useQuery({
        queryKey: [
            'production',
            'standards',
            'workspace',
            { view, page, pageSize, search, missingTally, packingMode, machineId },
        ],
        queryFn: () =>
            listProductionStandards({
                view,
                page,
                per_page: pageSize,
                search: search === '' ? undefined : search,
                // Literal 1, never a boolean: Laravel's `boolean` rule refuses
                // the string "true" axios would send.
                missing_tally: missingTally ? 1 : undefined,
                packing_mode: packingMode,
                work_center_id: machineId,
            }),
        // The table keeps the page it is showing while the next one loads, so
        // paging does not blink the master away.
        placeholderData: keepPreviousData,
    });

    const rows = useMemo(() => data?.data ?? [], [data]);
    const summary = data?.summary ?? { ready: 0, incomplete: 0, all: 0 };
    const total = data?.meta.total ?? 0;

    // The drawer must show the CURRENT row, not the one that was clicked: a
    // save invalidates the list, and a gap list still naming a figure that has
    // just been filled is worse than no gap list at all.
    useEffect(() => {
        if (openRow === null) return;
        const fresh = rows.find((r) => r.id === openRow.id);
        if (fresh !== undefined && fresh !== openRow) setOpenRow(fresh);
    }, [rows, openRow]);
    const openRowStillListed = openRow === null || rows.some((r) => r.id === openRow.id);

    // The factory's machine rule, from the backend that also enforces it — so
    // the machines this page names cannot disagree with the machines Start
    // Batch allows. Absent on an older backend, in which case the column stays
    // silent rather than guessing.
    const settings = useProductionSettings();
    const rule = settings?.machine_capability ?? null;
    const threshold = rule?.cavity_threshold ?? null;
    const restrictedNames = (rule?.restricted_machines ?? []).map((m) => m.name);

    const machines = useQuery({
        queryKey: ['production', 'work-centers', 'active'],
        queryFn: () => listWorkCenters(true),
    });

    /** Open the control that closes a gap. Attaching is a different act, so it is a different door. */
    const openFix = (row: ProductStandardsWorkspaceRow, field: FigureField) => {
        if (row.item === null) {
            setAttaching(row);
            return;
        }
        setFigures({ row, focus: field });
    };

    const viewOption = (label: string, value: ProductStandardsView, count: number, colour?: string) => ({
        value,
        label: (
            <Space size={6}>
                <span>{label}</span>
                <Typography.Text strong style={{ ...numeric, color: view === value ? undefined : colour }}>
                    {count}
                </Typography.Text>
            </Space>
        ),
    });

    return (
        <>
            <Row justify={embedded ? 'end' : 'space-between'} align="middle" gutter={[8, 8]} style={{ marginBottom: 4 }}>
                {!embedded && (
                    <Col>
                        <Typography.Title level={3} style={{ marginBottom: 0 }}>
                            Product Standards
                        </Typography.Title>
                    </Col>
                )}
                <Col>
                    <Button type="primary" onClick={() => setAdding(true)}>
                        New product standard
                    </Button>
                </Col>
            </Row>
            <Typography.Paragraph type="secondary">
                Every product's whole configuration in one place — the workbook's cavities, weight, cycle time and
                packing, the Tally item it applies to, the colour it needs, the recipe it consumes and the machines
                that run it differently. Open a product to see what stands between it and a shift, and to fix it.
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
                                whole reason they were sent. Read from the
                                server's total for the filtered set, since the
                                page is no longer the whole master. */}
                            {resumeItem && total === 0 && !isFetching ? (
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

            {/* TWO LIVE MACHINE SETTINGS FOR ONE PRODUCT.
                A warning, never a block: these rows already exist on a running
                factory, so refusing work over them would stop the floor rather
                than fix the data. What was missing is that the software picked
                one silently and no screen ever said so. */}
            {(data?.configuration_overlaps?.length ?? 0) > 0 && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={`${data!.configuration_overlaps.length} product${data!.configuration_overlaps.length === 1 ? ' has' : 's have'} two machine settings that both apply`}
                    // WHAT IT SAYS, NOT WHY IT MATTERS. Three sentences used to
                    // explain resolution order and unreliable efficiency to
                    // somebody who cannot act on either. The owner's verdict
                    // (06-Aug): "why these errors" — a warning without an action
                    // is anxiety, and a paragraph is not an action.
                    //
                    // The newest approved setting wins; that rule is in the
                    // resolver and its own tests, which is where a rule belongs.
                    // What a person needs HERE is which two rows clash.
                    description={
                        <ul style={{ margin: 0, paddingLeft: 18 }}>
                            {data!.configuration_overlaps.map((o) => (
                                <li key={`${o.item_id}-${o.work_center_id}`}>
                                    Settings {o.configuration_ids.join(' and ')}
                                    {o.values_differ ? (
                                        <Typography.Text type="danger"> — figures disagree</Typography.Text>
                                    ) : (
                                        ' — same figures'
                                    )}
                                </li>
                            ))}
                        </ul>
                    }
                />
            )}

            {/* NEEDS REVIEW (Phase 5, P5-03): every configuration that still
                waits on a person — a packing with no Tally identity of its
                own, one whose identity name is carried by more than one item,
                an item still on its provisional SKU — with the Tally items
                that match by name so the person LINKS one. The panel writes
                through the same packaging PUT the drawer uses; it never
                creates an item and never picks a match itself. */}
            <ConfigurationReviewPanel
                onFindInTable={(product) => {
                    setView('all');
                    setSearchInput(product);
                    setSearch(product);
                    setPage(1);
                }}
            />

            {/* THE "Which machines a product runs on" PANEL IS GONE.

                Five sentences explaining a rule the software already applies by
                itself: under the threshold a mould runs anywhere, at or above it
                the mould is set up on the big machine. The MACHINES column shows
                the answer on every row. The owner's verdict (06-Aug): "this is
                unnecessary."

                He is right, and the test is the one worth keeping: if a column
                needs a paragraph above it to be understood, the column is wrong.
                This one is not — it names machines. Nothing needed saying. */}
            <Space style={{ marginBottom: 8 }} wrap size={12}>
                <Segmented<ProductStandardsView>
                    value={view}
                    onChange={(v) => {
                        setView(v);
                        setPage(1);
                    }}
                    options={[
                        viewOption('Production ready', 'ready', summary.ready, '#237804'),
                        viewOption('Incomplete', 'incomplete', summary.incomplete, '#ad6800'),
                        viewOption('All', 'all', summary.all),
                    ]}
                />
            </Space>

            <Space style={{ marginBottom: 12 }} wrap>
                <Input.Search
                    allowClear
                    placeholder="Search product or Tally item…"
                    value={searchInput}
                    onChange={(e) => setSearchInput(e.target.value)}
                    style={{ width: 300 }}
                />
                <Select
                    allowClear
                    placeholder="Any packing"
                    style={{ width: 200 }}
                    value={packingMode}
                    onChange={(v) => {
                        setPackingMode(v);
                        setPage(1);
                    }}
                    options={(['pouch', 'tray', 'direct_box'] as StandardPackagingMode[]).map((m) => ({
                        value: m,
                        label: PACKING_MODE_LABEL[m],
                    }))}
                />
                <Select
                    allowClear
                    showSearch
                    optionFilterProp="label"
                    placeholder="Runs on machine…"
                    style={{ width: 220 }}
                    value={machineId}
                    loading={machines.isLoading}
                    onChange={(v) => {
                        setMachineId(v);
                        setPage(1);
                    }}
                    options={(machines.data?.data ?? []).map((m) => ({ value: m.id, label: machineLabel(m) }))}
                />
                <Tooltip title="Standards with no Tally item at all, and standards attached to an item Tally has never heard of. Both are the same job.">
                    <Checkbox
                        checked={missingTally}
                        onChange={(e) => {
                            setMissingTally(e.target.checked);
                            setPage(1);
                        }}
                    >
                        Missing Tally identity
                    </Checkbox>
                </Tooltip>
            </Space>

            {/* Figures line up column-wise across every cell of the grid. */}
            <div style={numeric}>
                <Table<ProductStandardsWorkspaceRow>
                    rowKey="id"
                    size="small"
                    loading={isFetching}
                    dataSource={rows}
                    scroll={{ x: 'max-content' }}
                    // Frozen below the app bar, not under it: the bar is
                    // sticky at top:0 and 64px tall (measured in the browser,
                    // not assumed), so the table header freezes at 64.
                    sticky={{ offsetHeader: APP_HEADER_HEIGHT }}
                    pagination={{
                        current: page,
                        pageSize,
                        total,
                        showSizeChanger: true,
                        pageSizeOptions: [...PRODUCT_STANDARDS_PAGE_SIZES],
                        onChange: (nextPage, nextSize) => {
                            // A size change re-slices the master, so the old
                            // page number is meaningless.
                            if (nextSize !== pageSize) {
                                setPageSize(nextSize);
                                setPage(1);
                                return;
                            }
                            setPage(nextPage);
                        },
                        // The server filters and pages, so this total IS what
                        // matches — no client-side subset to caveat.
                        showTotal: (t, range) => `${range[0]}–${range[1]} of ${t} products`,
                    }}
                    columns={[
                        {
                            // Stable across pages: row 26 on page two is the
                            // 26th product, which is how a person reads a
                            // number back over the phone.
                            title: '#',
                            width: 56,
                            fixed: 'left' as const,
                            align: 'right' as const,
                            render: (_: unknown, __: ProductStandardsWorkspaceRow, index: number) => (
                                <Typography.Text type="secondary">{(page - 1) * pageSize + index + 1}</Typography.Text>
                            ),
                        },
                        {
                            title: 'PRODUCT',
                            width: 240,
                            fixed: 'left' as const,
                            render: (_, r) => (
                                <Space direction="vertical" size={0}>
                                    <Typography.Text strong>{r.source_product_name}</Typography.Text>
                                    <Tooltip title={r.source ? `From ${r.source}` : undefined}>
                                        <Typography.Text type="secondary" style={{ fontSize: 11 }}>
                                            {r.source_reference ? `SL ${r.source_reference}` : '—'}
                                        </Typography.Text>
                                    </Tooltip>
                                </Space>
                            ),
                        },
                        {
                            title: 'GAPS',
                            width: 116,
                            fixed: 'left' as const,
                            render: (_, r) =>
                                r.ready ? (
                                    <Tag color="green">Ready</Tag>
                                ) : (
                                    <Tooltip
                                        title={r.gaps.map((g) => `${g.number}. ${g.label}`).join(' · ')}
                                    >
                                        <Button
                                            type="link"
                                            size="small"
                                            style={{ padding: 0 }}
                                            onClick={() => setOpenRow(r)}
                                        >
                                            <Tag color="gold" style={{ marginInlineEnd: 0, cursor: 'pointer' }}>
                                                {r.gaps.length} to fix
                                            </Tag>
                                        </Button>
                                    </Tooltip>
                                ),
                        },
                        {
                            title: 'Tally item it applies to',
                            width: 280,
                            render: (_, r) =>
                                r.item ? (
                                    <Space direction="vertical" size={0}>
                                        <Space size={6}>
                                            <span>{itemLabel(r.item)}</span>
                                            {!r.tally.guid_present && (
                                                <Tooltip title={r.tally.sentence ?? undefined}>
                                                    <Tag color="orange">not in Tally</Tag>
                                                </Tooltip>
                                            )}
                                            <ProvisionalSkuTag item={r.item} />
                                        </Space>
                                        {/* Silent for a row the IMPORTER matched
                                            by name — which is the truth about
                                            it, and the distinction someone
                                            checking this column is looking for. */}
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
                                        <Tooltip title={r.tally.sentence ?? undefined}>
                                            <Tag>not attached</Tag>
                                        </Tooltip>
                                        <Button size="small" type="link" style={{ padding: 0 }} onClick={() => setAttaching(r)}>
                                            Attach
                                        </Button>
                                    </Space>
                                ),
                        },
                        {
                            // Each packing's OWN Tally identity (DEC-20260810-003)
                            // and whether it is configured (Phase 5). One line
                            // per packing: the mode, then "sku · name" or the
                            // honest "no Tally identity of its own", then the
                            // missing pieces — judged on the identity it WILL
                            // post as (its own, else the product's). Beside the
                            // product's item on purpose — the two identities
                            // are read together.
                            title: 'PACKING → TALLY',
                            width: 260,
                            render: (_, r) =>
                                r.packagings.length === 0 ? (
                                    <Typography.Text type="secondary">—</Typography.Text>
                                ) : (
                                    <Space direction="vertical" size={2}>
                                        {r.packagings.map((p) => {
                                            const state = packagingState(p, r.item);
                                            return (
                                                <div key={p.id} style={{ fontSize: 12, lineHeight: '18px' }}>
                                                    <Typography.Text type="secondary">{PACKING_MODE_LABEL[p.mode]}: </Typography.Text>
                                                    <Tooltip
                                                        title={
                                                            p.tally_item
                                                                ? 'This packing posts to Tally under its own item.'
                                                                : 'No identity of its own — this packing posts as the product\'s item.'
                                                        }
                                                    >
                                                        <Typography.Text type={p.tally_item ? undefined : 'secondary'}>
                                                            {p.tally_item ? tallyIdentityLabel(p.tally_item) : `${tallyIdentityLabel(null)} of its own`}
                                                        </Typography.Text>
                                                    </Tooltip>
                                                    {!state.complete && (
                                                        <IncompleteTag state={state} style={{ marginLeft: 6, fontSize: 11 }} />
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </Space>
                                ),
                        },
                        {
                            title: 'NO. OF CAVITY',
                            width: 110,
                            align: 'right' as const,
                            render: (_, r) => r.cavities ?? '—',
                        },
                        {
                            // Which machines this product may run on — the
                            // factory's own rule (below the threshold,
                            // anywhere; at or above it, the named machines),
                            // applied to this row's cavity count. Computed,
                            // never stored: the rule is one sentence, and
                            // storing it per product-machine pair would mean
                            // ~790 rows to approve that then outrank the
                            // workbook the moment a figure is corrected there.
                            title: 'MACHINES',
                            width: 170,
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
                            width: 96,
                            align: 'right' as const,
                            render: (_, r) => fmt(r.unit_weight_grams),
                        },
                        {
                            title: 'CYCLE TIME (s)',
                            width: 120,
                            align: 'right' as const,
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
                            // The workbook's own three pouch columns, as
                            // columns — not folded into a tooltip. This page is
                            // read against the printed sheet, so it must line
                            // up with it.
                            title: 'POUCH',
                            children: [
                                { title: 'BOTL/POUCH', width: 100, align: 'right' as const, render: (_: unknown, r: ProductStandardsWorkspaceRow) => packCountCell(r, 'pouch', 'nos_per_pouch') },
                                { title: 'BOT/BOX', width: 92, align: 'right' as const, render: (_: unknown, r: ProductStandardsWorkspaceRow) => packCountCell(r, 'pouch', 'nos_per_box') },
                                { title: 'POUCH/BOX', width: 100, align: 'right' as const, render: (_: unknown, r: ProductStandardsWorkspaceRow) => packCountCell(r, 'pouch', 'pouches_per_box') },
                            ],
                        },
                        {
                            title: 'TRAY',
                            children: [
                                { title: 'BOTL/TRAY', width: 96, align: 'right' as const, render: (_: unknown, r: ProductStandardsWorkspaceRow) => packCountCell(r, 'tray', 'nos_per_tray') },
                                { title: 'BOT/BOX', width: 92, align: 'right' as const, render: (_: unknown, r: ProductStandardsWorkspaceRow) => packCountCell(r, 'tray', 'nos_per_box') },
                                { title: 'TRAY/BOX', width: 96, align: 'right' as const, render: (_: unknown, r: ProductStandardsWorkspaceRow) => packCountCell(r, 'tray', 'trays_per_box') },
                            ],
                        },
                        {
                            title: 'Box only',
                            width: 96,
                            align: 'right' as const,
                            render: (_, r) => packCountCell(r, 'direct_box', 'nos_per_box'),
                        },
                        {
                            // The three right-hand spec columns of the sheet:
                            // which carton, which tray, which pouch film.
                            title: 'Packaging materials',
                            children: [
                                { title: 'CARTON', width: 130, render: (_: unknown, r: ProductStandardsWorkspaceRow) => specCell(r, 'carton_spec') },
                                { title: 'TRAY', width: 130, render: (_: unknown, r: ProductStandardsWorkspaceRow) => specCell(r, 'tray_spec') },
                                { title: 'POUCH', width: 130, render: (_: unknown, r: ProductStandardsWorkspaceRow) => specCell(r, 'pouch_spec') },
                            ],
                        },
                        {
                            title: 'Exceptions',
                            width: 110,
                            align: 'right' as const,
                            render: (_, r) =>
                                r.machine_exceptions.length === 0 ? (
                                    <Typography.Text type="secondary">—</Typography.Text>
                                ) : (
                                    <Tooltip
                                        title={r.machine_exceptions
                                            .map((e) => `${e.work_center.code ?? e.work_center.name ?? `#${e.work_center.id}`} · ${e.status}`)
                                            .join(' · ')}
                                    >
                                        <Tag>{r.machine_exceptions.length}</Tag>
                                    </Tooltip>
                                ),
                        },
                        {
                            title: 'Recipe',
                            width: 170,
                            render: (_, r) =>
                                r.active_recipe ? (
                                    <Tooltip title="The active Bill of Material — maintained on Bills of Material, shown here read-only.">
                                        <Typography.Text>{r.active_recipe.name}</Typography.Text>
                                    </Tooltip>
                                ) : (
                                    <Typography.Text type="secondary">—</Typography.Text>
                                ),
                        },
                        {
                            title: 'Status',
                            width: 150,
                            render: (_, r) => {
                                const s = STATUS[r.status] ?? { colour: 'default', label: r.status, help: '' };
                                return (
                                    <Space size={4} wrap>
                                        <Tooltip title={r.status === 'unresolved' ? (r.unresolved_reason ?? s.help) : s.help}>
                                            <Tag color={s.colour}>{s.label}</Tag>
                                        </Tooltip>
                                        {/* A DIFFERENT axis from the readiness
                                            tag beside it, so it is shown only
                                            when it has something to say: this
                                            master has no active flag, archiving
                                            IS its soft delete, and an archived
                                            standard is normally not listed at
                                            all. Rendering "Active" on every row
                                            next to "Production ready" would be
                                            two tags for one fact. */}
                                        {r.is_archived ? (
                                            <ConfigurationStatusTag entity="production-standard" row={r} />
                                        ) : null}
                                    </Space>
                                );
                            },
                        },
                        {
                            title: '',
                            width: 190,
                            fixed: 'right' as const,
                            render: (_, r) => (
                                <ConfigurationActionsCell
                                    entity="production-standard"
                                    id={r.id}
                                    can={r.can}
                                    recordName={r.source_product_name}
                                    // Configure IS this master's view-and-edit
                                    // door, and the factory calls it that. It
                                    // rides along as the page's own act rather
                                    // than being renamed "Edit".
                                    extra={
                                        <Button size="small" onClick={() => setOpenRow(r)}>
                                            Configure
                                        </Button>
                                    }
                                />
                            ),
                        },
                    ]}
                />
            </div>

            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 12, fontSize: 12 }}>
                A cycle time marked <b>*</b> came from a workbook cell holding more than one value; each became its own
                variant rather than being averaged, because the mean of two real cycle times is a rate no machine runs
                at. A packing spec shown with a{' '}
                <span style={{ borderBottom: '1px dotted #ad6800' }}>dotted underline</span> was blank in the workbook
                and filled from a same-family row — hover it to see which row it came from; it is a reasonable guess,
                not the factory's word. Hover any tag for the figures behind it.
            </Typography.Text>

            {openRow !== null && (
                <ProductConfigurationDrawer
                    row={openRow}
                    stillListed={openRowStillListed}
                    onClose={() => setOpenRow(null)}
                    // The drawer hands back the row IT is showing, which is the
                    // freshly re-read one — so an editor never opens on figures
                    // the screen behind it has already replaced.
                    onFix={openFix}
                    onAttach={setAttaching}
                />
            )}

            {/* Mounted only while open, and keyed by row, so each dialog starts
                with nothing selected rather than inheriting the last row's pick. */}
            {attaching !== null && (
                <AttachItemModal key={attaching.id} standard={attaching} onClose={() => setAttaching(null)} />
            )}
            {figures !== null && (
                <ProductConfigurationFiguresModal
                    key={`${figures.row.id}-${figures.focus}`}
                    row={figures.row}
                    focus={figures.focus}
                    onClose={() => setFigures(null)}
                />
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

// ---------------------------------------------------------------------------

/**
 * Add or correct ONE packing option of a product — counts and its own Tally
 * identity (DEC-20260810-003).
 *
 * The identity select is the whole point of the modal existing: which Tally
 * item a tray-packed box posts as was not editable anywhere, and the floor's
 * complaint arrived as "the tally configuration is wrong and there is no
 * option to change it". Leaving the identity blank is a real answer — the
 * packing posts as the product's own item, and the drawer says so in words —
 * so the select allows clear and the extra text states the fallback.
 */
function PackagingEditModal({
    standardId,
    productItemName,
    packaging,
    onClose,
}: {
    standardId: number;
    productItemName: string | null;
    packaging: StandardPackaging | null;
    onClose: () => void;
}) {
    const queryClient = useQueryClient();
    const [form] = Form.useForm<{
        mode: StandardPackagingMode;
        nos_per_pouch?: number | null;
        pouches_per_box?: number | null;
        nos_per_tray?: number | null;
        trays_per_box?: number | null;
        nos_per_box?: number | null;
        item_id?: number | null;
    }>();
    const modeWatch = Form.useWatch('mode', form) ?? packaging?.mode ?? 'tray';

    const items = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems, staleTime: 5 * 60 * 1000 });
    const itemOptions = useMemo(
        () =>
            (items.data?.data ?? [])
                .filter((i) => i.is_active)
                .map((i) => ({ value: i.id, label: itemLabel(i) })),
        [items.data],
    );

    const save = useMutation({
        mutationFn: (payload: StandardPackagingPayload) =>
            packaging === null
                ? addStandardPackaging(standardId, payload)
                : updateStandardPackaging(standardId, packaging.id, payload),
        onSuccess: () => {
            // The workspace rows, the Start Batch preview and the completion
            // drawer all read the packagings — every cache is wrong the
            // moment a count or an identity changes.
            queryClient.invalidateQueries({ queryKey: ['production', 'standards'] });
            queryClient.invalidateQueries({ queryKey: ['production', 'batch-preview'] });
            onClose();
        },
        onError: (error: any) => showSaveError(error, 'Could not save this packing option'),
    });

    const submit = (v: {
        mode: StandardPackagingMode;
        nos_per_pouch?: number | null;
        pouches_per_box?: number | null;
        nos_per_tray?: number | null;
        trays_per_box?: number | null;
        nos_per_box?: number | null;
        item_id?: number | null;
    }) => {
        save.mutate({
            mode: v.mode,
            nos_per_pouch: v.mode === 'pouch' ? (v.nos_per_pouch ?? null) : undefined,
            pouches_per_box: v.mode === 'pouch' ? (v.pouches_per_box ?? null) : undefined,
            nos_per_tray: v.mode === 'tray' ? (v.nos_per_tray ?? null) : undefined,
            trays_per_box: v.mode === 'tray' ? (v.trays_per_box ?? null) : undefined,
            nos_per_box: v.mode === 'direct_box' ? (v.nos_per_box ?? null) : undefined,
            // Always sent, null included: clearing the identity IS an answer
            // ("back to the product's item"), and an omitted key would leave
            // the old value standing behind the person's back.
            item_id: v.item_id ?? null,
        });
    };

    return (
        <Modal
            open
            title={packaging === null ? 'Add a packing option' : `Packing — ${PACKING_MODE_LABEL[packaging.mode]}`}
            onCancel={onClose}
            okText={packaging === null ? 'Add packing option' : 'Save'}
            confirmLoading={save.isPending}
            onOk={() => form.submit()}
            maskClosable={false}
            destroyOnHidden
            width={560}
        >
            <Form
                form={form}
                layout="vertical"
                onFinish={submit}
                requiredMark={false}
                initialValues={
                    packaging === null
                        ? { mode: 'tray' }
                        : {
                              mode: packaging.mode,
                              nos_per_pouch: packaging.nos_per_pouch,
                              pouches_per_box: packaging.pouches_per_box,
                              nos_per_tray: packaging.nos_per_tray,
                              trays_per_box: packaging.trays_per_box,
                              nos_per_box: packaging.nos_per_box,
                              item_id: packaging.tally_item?.id ?? null,
                          }
                }
            >
                <Form.Item name="mode" label="Packing mode" rules={[{ required: true }]}>
                    <Select
                        disabled={packaging !== null}
                        options={[
                            { value: 'tray', label: 'Tray + Box' },
                            { value: 'pouch', label: 'Pouch + Box' },
                            { value: 'direct_box', label: 'Direct Box' },
                        ]}
                    />
                </Form.Item>

                {modeWatch === 'pouch' && (
                    <Row gutter={12}>
                        <Col span={12}>
                            <Form.Item name="nos_per_pouch" label="Bottles per pouch">
                                <InputNumber min={1} precision={0} style={{ width: '100%' }} />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="pouches_per_box" label="Pouches per box">
                                <InputNumber min={1} precision={0} style={{ width: '100%' }} />
                            </Form.Item>
                        </Col>
                    </Row>
                )}
                {modeWatch === 'tray' && (
                    <Row gutter={12}>
                        <Col span={12}>
                            <Form.Item name="nos_per_tray" label="Bottles per tray">
                                <InputNumber min={1} precision={0} style={{ width: '100%' }} />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="trays_per_box" label="Trays per box">
                                <InputNumber min={1} precision={0} style={{ width: '100%' }} />
                            </Form.Item>
                        </Col>
                    </Row>
                )}
                {modeWatch === 'direct_box' && (
                    <Form.Item name="nos_per_box" label="Bottles per box">
                        <InputNumber min={1} precision={0} style={{ width: '100%' }} />
                    </Form.Item>
                )}

                <Form.Item
                    name="item_id"
                    label="Tally identity of this packing"
                    extra={
                        productItemName
                            ? `Leave blank to post as the product's own item (${productItemName}). Fill both counts of the mode, or the option is refused.`
                            : 'Leave blank to post as the product’s own item. Fill both counts of the mode, or the option is refused.'
                    }
                >
                    <Select
                        allowClear
                        showSearch
                        loading={items.isFetching}
                        optionFilterProp="label"
                        placeholder="Search the Tally catalogue…"
                        options={itemOptions}
                    />
                </Form.Item>
            </Form>
        </Modal>
    );
}
