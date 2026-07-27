import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, Checkbox, Col, Descriptions, Drawer, Form, Input, InputNumber, Modal, Radio, Row, Select, Space, Table, Tag, TimePicker, Typography } from 'antd';
import dayjs from 'dayjs';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listAllEmployees } from '@/features/hrms/api';
import { listAllItems, listAllWarehouses } from '@/features/inventory/api';
import type { Item } from '@/features/inventory/types';
import {
    closeDowntimeLog,
    closeMoldChangeLog,
    completeBatch,
    createPowerInterruptionLog,
    createShiftStockCount,
    listMachineDowntimeLogs,
    listAllMolds,
    listMoldChangeLogs,
    listPowerInterruptionLogs,
    listAllScrapReasons,
    listShiftProductionEntries,
    listShifts,
    listWorkCenters,
    openDowntimeLog,
    openMoldChangeLog,
    startBatch,
} from '@/features/production/api';
import type {
    MachineDowntimeLog,
    MoldChangeLog,
    Shift,
    ShiftProductionEntry,
    ShiftProductionEntryStatus,
    WorkCenter,
} from '@/features/production/types';
import { currentShift, justEndedShift, productionDateFor } from '@/features/production/shiftClock';
import { roundPer, useProductionSettings } from '@/features/production/packing';

// Combines a picked "HH:mm" with today's date into a full ISO datetime for
// the API — shared by every backdate-capable modal below (Report Down,
// Close Breakdown, Mold Change, Finish Mold Change). Mirrors the same
// combine step used for Power Interruption.
function combineWithToday(today: string, time: string): string {
    return dayjs(`${today} ${time}`).toISOString();
}

/** "10.60" → 10.6; null for null/empty/unparseable — never NaN. */
function toNum(v: string | null | undefined): number | null {
    if (v === null || v === undefined || v === '') return null;
    const n = parseFloat(v);
    return Number.isNaN(n) ? null : n;
}

/** Display helper: trims trailing zeros, "—" for missing. */
function fmtNum(n: number | null | undefined, dp = 2): string {
    return n === null || n === undefined || Number.isNaN(n) ? '—' : String(parseFloat(n.toFixed(dp)));
}

/**
 * Shift length in hours from the shift master's start/end times — the default
 * "planned hours" for the live expected figures and the Running Hours prefill.
 * A "to" earlier than "from" is the Night shift crossing midnight.
 */
function shiftLengthHours(shift: Shift | null | undefined): number | null {
    if (!shift?.start_time || !shift.end_time) return null;
    const [sh, sm] = shift.start_time.split(':').map(Number);
    const [eh, em] = shift.end_time.split(':').map(Number);
    if ([sh, sm, eh, em].some((n) => Number.isNaN(n))) return null;
    let minutes = eh * 60 + em - (sh * 60 + sm);
    if (minutes <= 0) minutes += 24 * 60;
    return Math.round((minutes / 60) * 100) / 100;
}

/**
 * The expected-output formula from the shared contract, duplicated here for
 * the live (pre-completion) screens — the backend's metrics block is the
 * authoritative figure once the batch completes.
 * expected pieces = 3600/CT × active cavities × hours; boxes = ROUND(pieces/pack);
 * pouches = pieces/nos-per-pouch rounded per the packing-rounding config
 * (mirrors metrics.expected_pouches).
 * Null (show nothing — never 0 or NaN) when any input is missing or zero.
 */
function expectedOutput(
    cycleTimeSeconds: number | null,
    cavities: number | null | undefined,
    hours: number | null,
    nosPerBox: number | null,
    nosPerPouch: number | null,
    mode?: import('@/features/production/packing').PackingRounding,
): { pieces: number; boxes: number | null; pouches: number | null } | null {
    if (!cycleTimeSeconds || cycleTimeSeconds <= 0 || !cavities || cavities <= 0 || !hours || hours <= 0) return null;
    const pieces = Math.round((3600 / cycleTimeSeconds) * cavities * hours * 100) / 100;
    const boxes = nosPerBox && nosPerBox >= 1 ? Math.round(pieces / nosPerBox) : null;
    const pouches = nosPerPouch && nosPerPouch >= 1 ? roundPer(pieces / nosPerPouch, mode) : null;
    return { pieces, boxes, pouches };
}

/** ">= 1" with null-safety — the shared test for "this packing standard exists". */
function hasPackStd(v: number | null | undefined): boolean {
    return (v ?? 0) >= 1;
}

const isMasterbatchItem = (item: Item): boolean => /master ?batch/i.test(`${item.sku} ${item.name}`);
const isResinItem = (item: Item): boolean => /resin/i.test(`${item.sku} ${item.name}`);
const isClearColour = (colour: string | null | undefined): boolean => /^clear$/i.test((colour ?? '').trim());

/**
 * Masterbatch suggested from the product's colour — matched on the MB item's
 * own colour field first, then on its name containing the colour. Clear
 * products get no suggestion (no masterbatch goes into Clear).
 */
function suggestMasterbatchId(items: Item[] | undefined, colour: string | null | undefined): number | undefined {
    if (!items || !colour || isClearColour(colour)) return undefined;
    const c = colour.trim().toLowerCase();
    if (c === '') return undefined;
    const mbs = items.filter((i) => i.is_active && isMasterbatchItem(i));
    const match =
        mbs.find((i) => (i.colour ?? '').trim().toLowerCase() === c) ??
        mbs.find((i) => i.name.toLowerCase().includes(c));
    return match?.id;
}

const efficiencyTag = (pct: number | null) => {
    if (pct === null) return null;
    if (pct >= 95) return <Tag color="green">OK</Tag>;
    if (pct >= 85) return <Tag color="orange">Watch</Tag>;
    return <Tag color="red">Investigate</Tag>;
};

/** One row of the pre-submit results panel: value + its business formula. */
function ResultRow({ label, value, formula, danger }: { label: string; value: ReactNode; formula?: string; danger?: boolean }) {
    return (
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 12, padding: '6px 0' }}>
            <div style={{ minWidth: 0 }}>
                <Typography.Text>{label}</Typography.Text>
                {formula && (
                    <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                        {formula}
                    </Typography.Text>
                )}
            </div>
            <Typography.Text strong type={danger ? 'danger' : undefined} style={{ whiteSpace: 'nowrap' }}>
                {value}
            </Typography.Text>
        </div>
    );
}

const locationLabelOptions = [
    'Hoppers', 'Day Bin', 'Loose Bag', 'Store',
    'MB-Clear', 'MB-Blue', 'MB-Amber', 'MB-White', 'MB-Green', 'MB-Orange', 'MB-Black',
].map((label) => ({ value: label, label }));

const startBatchSchema = z.object({
    item_id: z.number({ error: 'Pick an item' }),
    warehouse_id: z.number({ error: 'Pick a warehouse' }),
    operator_id: z.number().optional(),
    // Prefilled with the item's standard cavity count; editable for the real
    // case of a blocked cavity. nullish: clearing the InputNumber emits null.
    active_cavities: z.number().int().min(1, 'At least 1').nullish(),
});
type StartBatchFormValues = z.infer<typeof startBatchSchema>;

const completeBatchSchema = z.object({
    batch_number: z.string().optional(),
    quantity_produced: z.number().gt(0, 'Must be greater than 0'),
    quantity_scrap: z.number().min(0).optional(),
    scrap_reason_id: z.number().optional(),
    // nullish, not optional: antd InputNumber emits null when cleared, and a
    // cleared auto-suggestion must never dead-end the Complete Batch button.
    nos_per_tray: z.number().min(0).nullish(),
    no_of_trays: z.number().min(0).nullish(),
    nos_per_box: z.number().min(0).nullish(),
    no_of_box: z.number().min(0).nullish(),
    // Pouch count for pouch-packed items (item.nos_per_pouch set) — hidden
    // and never populated for everything else.
    no_of_pouches: z.number().min(0).nullish(),
    // Loose pieces beyond full boxes/pouches — feeds the quantity_produced
    // derivation and is persisted on the entry (formalized in Wave A packaging).
    loose_pieces: z.number().min(0).nullish(),
    // Expected-output engine inputs (all optional — a batch with no standards
    // must complete exactly as before these fields existed).
    running_hours: z.number().gt(0, 'Must be greater than 0').max(24, 'Max 24 hours').nullish(),
    actual_cycle_time: z.number().min(0.1, 'At least 0.1 s').nullish(),
    active_cavities: z.number().int().min(1, 'At least 1').nullish(),
    qc_rejection_kg: z.number().min(0).nullish(),
    // The two fixed material rows (resin + masterbatch). Only rows with a
    // quantity are sent — merged into material_consumptions on submit.
    resin_item_id: z.number().nullish(),
    resin_warehouse_id: z.number().nullish(),
    resin_kg: z.number().min(0).nullish(),
    mb_item_id: z.number().nullish(),
    mb_warehouse_id: z.number().nullish(),
    mb_kg: z.number().min(0).nullish(),
    helper_name: z.string().max(120, 'Max 120 characters').optional(),
    notes: z.string().optional(),
    material_consumptions: z
        .array(
            z.object({
                item_id: z.number({ error: 'Item is required' }),
                warehouse_id: z.number({ error: 'Warehouse is required' }),
                quantity_issued_kg: z.number().gt(0, 'Must be greater than 0'),
            }),
        )
        .optional(),
    scraps: z
        .array(
            z.object({
                type: z.enum(['rejected_finished_good', 'lumps']),
                quantity_nos: z.number().min(0).optional(),
                quantity_kg: z.number().min(0).optional(),
                scrap_reason_id: z.number().optional(),
            }),
        )
        .optional(),
}).superRefine((data, ctx) => {
    // A fixed row with kg entered needs its item and source — otherwise the
    // kilograms would be silently dropped from the payload.
    const requireRow = (
        kg: number | null | undefined,
        itemId: number | null | undefined,
        warehouseId: number | null | undefined,
        itemPath: string,
        warehousePath: string,
    ) => {
        if (!kg || kg <= 0) return;
        if (!itemId) ctx.addIssue({ code: 'custom', path: [itemPath], message: 'Pick the item' });
        if (!warehouseId) ctx.addIssue({ code: 'custom', path: [warehousePath], message: 'Pick the source' });
    };
    requireRow(data.resin_kg, data.resin_item_id, data.resin_warehouse_id, 'resin_item_id', 'resin_warehouse_id');
    requireRow(data.mb_kg, data.mb_item_id, data.mb_warehouse_id, 'mb_item_id', 'mb_warehouse_id');
});
type CompleteBatchFormValues = z.infer<typeof completeBatchSchema>;

const reportDownSchema = z.object({
    nature_of_problem: z.string().min(1, 'Describe the problem'),
    backdate: z.boolean().optional(),
    time: z.string().optional(),
});
type ReportDownFormValues = z.infer<typeof reportDownSchema>;

const closeDowntimeSchema = z.object({
    remedy: z.string().optional(),
    parts_changed: z.string().optional(),
    backdate: z.boolean().optional(),
    time: z.string().optional(),
});
type CloseDowntimeFormValues = z.infer<typeof closeDowntimeSchema>;

const moldChangeSchema = z.object({
    changed_from_mold_id: z.number().optional(),
    changed_to_mold_id: z.number({ error: 'Pick the mold going in' }),
    changed_to_item_id: z.number({ error: 'Pick the item it will produce' }),
    backdate: z.boolean().optional(),
    time: z.string().optional(),
    end_time: z.string().optional(),
});
type MoldChangeFormValues = z.infer<typeof moldChangeSchema>;

const finishMoldChangeSchema = z.object({
    backdate: z.boolean().optional(),
    time: z.string().optional(),
});
type FinishMoldChangeFormValues = z.infer<typeof finishMoldChangeSchema>;

const powerInterruptionSchema = z.object({
    from_time: z.string({ error: 'Start time is required' }),
    to_time: z.string({ error: 'End time is required' }),
});
type PowerInterruptionFormValues = z.infer<typeof powerInterruptionSchema>;

const stockCountSchema = z.object({
    location_label: z.string({ error: 'Pick a location' }),
    item_id: z.number({ error: 'Pick an item' }),
    quantity_kg: z.number().min(0),
});
type StockCountFormValues = z.infer<typeof stockCountSchema>;

const approvalColor: Record<ShiftProductionEntryStatus, string> = {
    pending: 'processing',
    pm_approved: 'cyan',
    accountant_approved: 'geekblue',
    approved: 'success',
    rejected: 'error',
    synced: 'success',
    failed: 'error',
};

// Every "stopwatch" log (downtime open/close, mold change open/close)
// defaults to stamping the current time — the common case of logging it
// live. This is the shared override for the other real case: a supervisor
// catching up on paperwork after the fact, where "now" would be wrong.
function BackdateField({
    control,
    backdateEnabled,
    // Mold changes commonly run well over an hour, so that modal wants
    // both ends of the range up front: give it a second field name and
    // this renders "From"/"To" (To optional — still-in-progress mold
    // changes can leave it blank). Every other modal only ever needs one
    // moment (when a breakdown was reported, when it was fixed, ...), so
    // they omit this and get a single unlabeled time field as before.
    rangeEndFieldName,
}: {
    control: any;
    backdateEnabled: boolean;
    rangeEndFieldName?: string;
}) {
    return (
        <Form.Item style={{ marginBottom: backdateEnabled ? 8 : 0 }}>
            <Controller
                name="backdate"
                control={control}
                render={({ field }) => (
                    <Checkbox checked={field.value ?? false} onChange={(e) => field.onChange(e.target.checked)}>
                        This already happened — enter the actual time
                    </Checkbox>
                )}
            />
            {backdateEnabled && (
                <Space style={{ marginTop: 8, width: '100%' }}>
                    <Controller
                        name="time"
                        control={control}
                        render={({ field }) => (
                            <TimePicker
                                format="HH:mm"
                                placeholder={rangeEndFieldName ? 'From' : 'Select time'}
                                value={field.value ? dayjs(field.value, 'HH:mm') : null}
                                onChange={(_, timeString) => field.onChange((Array.isArray(timeString) ? timeString[0] : timeString) || undefined)}
                            />
                        )}
                    />
                    {rangeEndFieldName && (
                        <Controller
                            name={rangeEndFieldName}
                            control={control}
                            render={({ field }) => (
                                <TimePicker
                                    format="HH:mm"
                                    placeholder="To (optional — still in progress?)"
                                    style={{ width: 220 }}
                                    value={field.value ? dayjs(field.value, 'HH:mm') : null}
                                    onChange={(_, timeString) => field.onChange((Array.isArray(timeString) ? timeString[0] : timeString) || undefined)}
                                />
                            )}
                        />
                    )}
                </Space>
            )}
        </Form.Item>
    );
}

export default function ShiftProductionEntryPage() {
    const [selectedShiftId, setSelectedShiftId] = useState<number | undefined>(undefined);
    const [graceBannerDismissed, setGraceBannerDismissed] = useState(false);
    const [startingMachine, setStartingMachine] = useState<WorkCenter | null>(null);
    const [completingEntry, setCompletingEntry] = useState<ShiftProductionEntry | null>(null);
    const [reportingDownMachine, setReportingDownMachine] = useState<WorkCenter | null>(null);
    const [closingDowntimeLog, setClosingDowntimeLog] = useState<MachineDowntimeLog | null>(null);
    const [startingMoldChangeMachine, setStartingMoldChangeMachine] = useState<WorkCenter | null>(null);
    const [finishingMoldChangeLog, setFinishingMoldChangeLog] = useState<MoldChangeLog | null>(null);
    const [powerInterruptionOpen, setPowerInterruptionOpen] = useState(false);
    const [stockCountOpen, setStockCountOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data: shifts } = useQuery({ queryKey: ['production', 'shifts'], queryFn: listShifts });
    const { data: workCenters } = useQuery({ queryKey: ['production', 'work-centers'], queryFn: listWorkCenters });
    // Shop-floor pickers need the WHOLE reference list, not the default first
    // 20 — with 642 items the type-to-search Select would otherwise only ever
    // see page 1 and most items would be unselectable. Distinct query keys so
    // this full-list fetch doesn't collide with the paginated list-page caches.
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses', 'all'], queryFn: listAllWarehouses });
    const { data: scrapReasons } = useQuery({ queryKey: ['production', 'scrap-reasons', 'all'], queryFn: listAllScrapReasons });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees', 'all'], queryFn: listAllEmployees });
    const { data: entries, isLoading: entriesLoading } = useQuery({
        queryKey: ['production', 'shift-production-entries'],
        queryFn: () => listShiftProductionEntries(),
        // Several people can act on any of the floor's machines ad hoc, no
        // fixed assignment — poll so one supervisor's screen reflects what
        // another just did. See PRODUCTION-SUPERVISOR-UX-PLAN.md §2.
        refetchInterval: 20000,
    });
    const { data: downtimeLogs } = useQuery({
        queryKey: ['production', 'machine-downtime-logs'],
        queryFn: listMachineDowntimeLogs,
        refetchInterval: 20000,
    });
    const { data: moldChangeLogs } = useQuery({
        queryKey: ['production', 'mold-change-logs'],
        queryFn: listMoldChangeLogs,
        refetchInterval: 20000,
    });
    const { data: powerInterruptionLogs } = useQuery({
        queryKey: ['production', 'power-interruption-logs'],
        queryFn: listPowerInterruptionLogs,
    });
    const { data: molds } = useQuery({ queryKey: ['production', 'molds', 'all'], queryFn: listAllMolds });

    const shiftOptions = shifts?.data.filter((s) => s.is_active).map((s) => ({ value: s.id, label: s.name })) ?? [];
    // Inactive items (retired demo/legacy masters) must not be selectable —
    // Tally rejects vouchers for items it doesn't know.
    const itemOptions = items?.data.filter((i) => i.is_active).map((i) => ({ value: i.id, label: `${i.sku} — ${i.name}` })) ?? [];
    // Focused pickers for the two fixed consumption rows — a supervisor
    // filling "Resin (kg)" should only ever see resins, not all 642 items.
    const resinOptions =
        items?.data.filter((i) => i.is_active && isResinItem(i)).map((i) => ({ value: i.id, label: `${i.sku} — ${i.name}` })) ?? [];
    const mbOptions =
        items?.data.filter((i) => i.is_active && isMasterbatchItem(i)).map((i) => ({ value: i.id, label: `${i.sku} — ${i.name}` })) ?? [];
    const moldOptions =
        molds?.data.filter((m) => m.status === 'active').map((m) => ({ value: m.id, label: `${m.code} — ${m.name}` })) ?? [];
    // "Changed From" is a historical record of what just came out, not a
    // pick of something to install — it can be any mold regardless of
    // current status (it may have gone straight to "under repair").
    const allMoldOptions = molds?.data.map((m) => ({ value: m.id, label: `${m.code} — ${m.name}` })) ?? [];
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];
    const scrapReasonOptions = scrapReasons?.data.map((r) => ({ value: r.id, label: `${r.code} — ${r.name}` })) ?? [];
    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];

    // Default to the shift whose time window contains "now" (Night handled
    // across midnight), so a supervisor who never touches the picker still
    // logs against the right shift. The picker stays overridable for the
    // rare backdate.
    const activeShifts = shifts?.data.filter((s) => s.is_active) ?? [];
    const detectedShift = currentShift(activeShifts);
    const effectiveShiftId = selectedShiftId ?? detectedShift?.id ?? shiftOptions[0]?.value;
    const effectiveShift = activeShifts.find((s) => s.id === effectiveShiftId);
    // Shift-boundary grace: for ~30 min after a shift ends, a supervisor may
    // still be wrapping up the OLD shift while auto-selection has moved on.
    // Only relevant while they haven't picked a shift themselves.
    const endedShift = selectedShiftId === undefined ? justEndedShift(activeShifts) : undefined;
    const showGraceBanner =
        !graceBannerDismissed && endedShift !== undefined && detectedShift !== undefined && endedShift.id !== detectedShift.id;
    // Shift-aware, LOCAL production date: at 02:00 on the Night shift this is
    // yesterday (the shift's start date), so the whole night files together.
    const today = productionDateFor(effectiveShift);

    // Last-touched-by-someone-else state for every machine, derived from the
    // shared entry list rather than a per-machine assignment — nobody owns a
    // fixed subset of the floor here (UX doc §2).
    const runningByMachine = useMemo(() => {
        const map = new Map<number, ShiftProductionEntry>();
        for (const entry of entries?.data ?? []) {
            if (entry.batch_status !== 'in_progress') continue;
            if (entry.production_date !== today) continue;
            if (effectiveShiftId && entry.shift.id !== effectiveShiftId) continue;
            const existing = map.get(entry.work_center.id);
            if (!existing || entry.id > existing.id) map.set(entry.work_center.id, entry);
        }
        return map;
    }, [entries, today, effectiveShiftId]);

    const openDowntimeByMachine = useMemo(() => {
        const map = new Map<number, MachineDowntimeLog>();
        for (const log of downtimeLogs?.data ?? []) {
            if (log.status === 'open') map.set(log.work_center.id, log);
        }
        return map;
    }, [downtimeLogs]);

    const openMoldChangeByMachine = useMemo(() => {
        const map = new Map<number, MoldChangeLog>();
        for (const log of moldChangeLogs?.data ?? []) {
            if (log.status === 'open') map.set(log.work_center.id, log);
        }
        return map;
    }, [moldChangeLogs]);

    const completedToday = (entries?.data ?? [])
        .filter((e) => e.batch_status === 'completed' && e.production_date === today)
        .slice(0, 15);

    // A grid outage can happen more than once in a shift — this is a list,
    // not a single per-shift value, so every "Log Power Interruption" adds
    // a row rather than overwriting one.
    const powerInterruptionsToday = (powerInterruptionLogs?.data ?? []).filter((p) => p.production_date === today);

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['production', 'shift-production-entries'] });
        queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
    };
    const invalidateDowntime = () => queryClient.invalidateQueries({ queryKey: ['production', 'machine-downtime-logs'] });
    const invalidateMoldChange = () => queryClient.invalidateQueries({ queryKey: ['production', 'mold-change-logs'] });

    const startForm = useForm<StartBatchFormValues>({ resolver: zodResolver(startBatchSchema) });
    // The picked item's master record — drives the read-only "Product
    // standards" summary and the Active Cavities prefill in Start Batch.
    const startItemId = startForm.watch('item_id');
    const startItem = useMemo(() => items?.data.find((i) => i.id === startItemId), [items, startItemId]);
    // Active cavities is a per-item value: every item change re-prefills it
    // with that item's standard (an earlier edit belonged to the old item).
    // Items without a standard leave it blank — fully manual, as before.
    useEffect(() => {
        if (!startingMachine) return;
        startForm.setValue('active_cavities', startItem?.standard_cavities ?? undefined);
    }, [startItem, startingMachine, startForm]);
    const startMutation = useMutation({
        mutationFn: (values: StartBatchFormValues) => {
            if (!startingMachine || !effectiveShiftId) throw new Error('Missing machine or shift');
            const { active_cavities, ...rest } = values;
            // production_date sent explicitly (shift-aware): a batch started at
            // 02:00 on the Night shift files under the shift's START date.
            return startBatch({
                ...rest,
                // null (cleared InputNumber) → omitted; backend then defaults
                // active cavities to the item's standard.
                active_cavities: active_cavities ?? undefined,
                work_center_id: startingMachine.id,
                shift_id: effectiveShiftId,
                production_date: today,
            });
        },
        onSuccess: () => {
            invalidate();
            setStartingMachine(null);
            startForm.reset();
        },
        onError: (error: any) => {
            Modal.error({
                title: 'Could not start batch',
                content: error?.response?.data?.message ?? 'Someone may have just started this machine — refresh and try again.',
            });
        },
    });

    const completeForm = useForm<CompleteBatchFormValues>({
        resolver: zodResolver(completeBatchSchema),
        defaultValues: { material_consumptions: [], scraps: [] },
    });
    const materialFields = useFieldArray({ control: completeForm.control, name: 'material_consumptions' });
    const scrapFields = useFieldArray({ control: completeForm.control, name: 'scraps' });
    const quantityProduced = completeForm.watch('quantity_produced');
    const quantityScrap = completeForm.watch('quantity_scrap');
    const settings = useProductionSettings();
    const goodBoxesWatch = completeForm.watch('no_of_box');
    const pouchesWatch = completeForm.watch('no_of_pouches');
    const loosePiecesWatch = completeForm.watch('loose_pieces');
    const runningHoursWatch = completeForm.watch('running_hours');
    const activeCavitiesWatch = completeForm.watch('active_cavities');
    const qcRejectionWatch = completeForm.watch('qc_rejection_kg');
    const resinKgWatch = completeForm.watch('resin_kg');
    const mbKgWatch = completeForm.watch('mb_kg');
    const scrapsWatch = completeForm.watch('scraps');
    const consumptionsWatch = completeForm.watch('material_consumptions');

    // Packing auto-fill from the item's packing master (nos_per_tray /
    // nos_per_box). Auto-writes never mark the field dirty, so the dirty flag
    // is exactly "the user touched this" — dirty fields are never overwritten.
    // Items without standards never enter this path — the form stays fully
    // manual, exactly as before the packing master existed.
    useEffect(() => {
        if (!completingEntry || !quantityProduced || quantityProduced <= 0) return;
        const suggest = (field: 'no_of_trays' | 'no_of_pouches' | 'no_of_box', standard: number | null) => {
            if (!standard || standard < 1) return;
            // Auto-writes never set dirty; any user interaction does. A field the
            // user typed in (or cleared) stays theirs, even across quantity edits.
            if (completeForm.getFieldState(field).isDirty) return;
            // Rounding mode mirrors backend production.packing_rounding.
            completeForm.setValue(field, roundPer(quantityProduced / standard, settings?.packing_rounding));
        };
        suggest('no_of_trays', completingEntry.item.nos_per_tray);
        suggest('no_of_pouches', completingEntry.item.nos_per_pouch);
        suggest('no_of_box', completingEntry.item.nos_per_box);
    }, [quantityProduced, completingEntry, completeForm]);

    // The inverse direction, with box-first precedence: Good Boxes × pcs/box
    // + loose derives Quantity Produced when a pack size is known; otherwise
    // Pouches × item pcs/pouch + loose when the item has a pouch standard;
    // otherwise fully manual. Same dirty rule as the packing auto-fill above —
    // a quantity the user corrected by hand is theirs and never overwritten,
    // and the derived write itself never marks the field dirty. Items without
    // any standard never enter either path (manual entry, exactly as today).
    // Only USER-TYPED (dirty) counts drive a derivation — a suggestion-filled
    // box count must not re-derive (and inflate) a pouch-derived quantity via
    // its rounded-up value. In the box-only world this dirty requirement is
    // behaviour-identical: a non-dirty box count with a value only ever
    // coexists with a user-typed quantity, which already blocks this effect.
    // The form's own Nos/Box (supervisor-corrected pack size) beats the
    // master standard — a run packed at 800/box must derive with 800.
    const nosPerBoxWatch = completeForm.watch('nos_per_box');
    useEffect(() => {
        if (!completingEntry) return;
        if (completeForm.getFieldState('quantity_produced').isDirty) return;
        const loose = loosePiecesWatch ?? 0;
        const nosPerBox = nosPerBoxWatch ?? completingEntry.item.nos_per_box;
        if (
            hasPackStd(nosPerBox) &&
            goodBoxesWatch !== null &&
            goodBoxesWatch !== undefined &&
            completeForm.getFieldState('no_of_box').isDirty
        ) {
            completeForm.setValue('quantity_produced', goodBoxesWatch * nosPerBox! + loose);
            return;
        }
        const nosPerPouch = completingEntry.item.nos_per_pouch;
        if (
            hasPackStd(nosPerPouch) &&
            pouchesWatch !== null &&
            pouchesWatch !== undefined &&
            completeForm.getFieldState('no_of_pouches').isDirty
        ) {
            completeForm.setValue('quantity_produced', pouchesWatch * nosPerPouch! + loose);
        }
    }, [goodBoxesWatch, pouchesWatch, loosePiecesWatch, nosPerBoxWatch, completingEntry, completeForm]);

    const nominalWeight = completingEntry?.item.nominal_weight_grams ? Number(completingEntry.item.nominal_weight_grams) : null;
    const previewProducedKg = nominalWeight && quantityProduced ? ((quantityProduced * nominalWeight) / 1000).toFixed(4) : null;
    const previewRejectionKg = nominalWeight && quantityScrap ? ((quantityScrap * nominalWeight) / 1000).toFixed(4) : null;

    // Packaging applicability — data-driven from the item's packing master, no
    // mode column. Boxes are the factory's universal outer and always visible;
    // trays show when the item has a tray standard OR no packing standards at
    // all (an item with NO standards renders exactly the pre-pouch field set);
    // pouches show only when the item has a pouch standard.
    const completingItem = completingEntry?.item ?? null;
    const hasAnyPackagingStandard =
        completingItem !== null &&
        [
            completingItem.nos_per_tray,
            completingItem.trays_per_box,
            completingItem.nos_per_box,
            completingItem.nos_per_pouch,
            completingItem.pouches_per_box,
        ].some(hasPackStd);
    const showTrayFields = hasPackStd(completingItem?.nos_per_tray) || !hasAnyPackagingStandard;
    const showPouchFields = hasPackStd(completingItem?.nos_per_pouch);

    // Everything the pre-submit results panel shows, computed live from the
    // form + the entry's Start Batch snapshots. Frontend duplicate of the
    // contract formulas — the backend metrics block is authoritative once
    // completed. Null members mean "inputs missing, show nothing".
    const results = useMemo(() => {
        if (!completingEntry) return null;
        const ct = toNum(completingEntry.standard_cycle_time);
        const cavities = activeCavitiesWatch ?? completingEntry.active_cavities ?? completingEntry.standard_cavities ?? null;
        const hours = runningHoursWatch ?? null;
        // Form's corrected pack size wins over the master (mirrors backend).
        const nosPerBox = nosPerBoxWatch ?? completingEntry.item.nos_per_box ?? null;
        // Pouch standard has no per-run correction field — always the master's.
        const nosPerPouch = completingEntry.item.nos_per_pouch ?? null;
        const expected = expectedOutput(ct, cavities, hours, nosPerBox, nosPerPouch, settings?.packing_rounding);
        const goodKg = nominalWeight && quantityProduced ? (quantityProduced * nominalWeight) / 1000 : null;
        const rejProdKg = nominalWeight && quantityScrap ? (quantityScrap * nominalWeight) / 1000 : null;
        const qcKg = qcRejectionWatch ?? null;
        const rejDiffKg = rejProdKg !== null && qcKg !== null ? rejProdKg - qcKg : null;
        const lumpsKg = (scrapsWatch ?? []).reduce((sum, s) => sum + (s?.type === 'lumps' ? (s.quantity_kg ?? 0) : 0), 0);
        const issuedKg =
            (resinKgWatch ?? 0) + (mbKgWatch ?? 0) + (consumptionsWatch ?? []).reduce((sum, c) => sum + (c?.quantity_issued_kg ?? 0), 0);
        const confirmedRejKg = qcKg ?? rejProdKg;
        const unaccountedKg = issuedKg > 0 && goodKg !== null ? issuedKg - goodKg - (confirmedRejKg ?? 0) - lumpsKg : null;
        const actualBoxes = goodBoxesWatch ?? null;
        const actualPouches = pouchesWatch ?? null;
        const efficiencyPct = expected?.boxes && actualBoxes !== null ? Math.round((actualBoxes / expected.boxes) * 1000) / 10 : null;
        return { ct, cavities, hours, nosPerBox, nosPerPouch, expected, goodKg, rejProdKg, qcKg, rejDiffKg, lumpsKg, issuedKg, unaccountedKg, actualBoxes, actualPouches, efficiencyPct };
    }, [
        completingEntry,
        nominalWeight,
        quantityProduced,
        quantityScrap,
        goodBoxesWatch,
        pouchesWatch,
        runningHoursWatch,
        activeCavitiesWatch,
        qcRejectionWatch,
        resinKgWatch,
        mbKgWatch,
        scrapsWatch,
        consumptionsWatch,
    ]);

    const completeMutation = useMutation({
        mutationFn: (values: CompleteBatchFormValues) => {
            if (!completingEntry) throw new Error('No batch selected');
            // loose_pieces and no_of_pouches ride through in ...rest — both are
            // real persisted fields since Wave A packaging.
            const {
                resin_item_id,
                resin_warehouse_id,
                resin_kg,
                mb_item_id,
                mb_warehouse_id,
                mb_kg,
                running_hours,
                qc_rejection_kg,
                actual_cycle_time,
                active_cavities,
                material_consumptions,
                ...rest
            } = values;
            // The fixed resin/MB rows are ordinary consumption lines on the
            // wire — same payload shape as before, no backend change.
            const consumptions = [
                ...(resin_item_id && resin_warehouse_id && resin_kg && resin_kg > 0
                    ? [{ item_id: resin_item_id, warehouse_id: resin_warehouse_id, quantity_issued_kg: resin_kg }]
                    : []),
                ...(mb_item_id && mb_warehouse_id && mb_kg && mb_kg > 0
                    ? [{ item_id: mb_item_id, warehouse_id: mb_warehouse_id, quantity_issued_kg: mb_kg }]
                    : []),
                ...(material_consumptions ?? []),
            ];
            return completeBatch(completingEntry.id, {
                ...rest,
                material_consumptions: consumptions,
                // Cleared InputNumbers emit null — omit rather than send null.
                running_hours: running_hours ?? undefined,
                qc_rejection_kg: qc_rejection_kg ?? undefined,
                actual_cycle_time: actual_cycle_time ?? undefined,
                active_cavities: active_cavities ?? undefined,
            });
        },
        onSuccess: () => {
            invalidate();
            setCompletingEntry(null);
            completeForm.reset({ material_consumptions: [], scraps: [] });
        },
        onError: (error: any) => {
            Modal.error({
                title: 'Could not complete batch',
                content: error?.response?.data?.message ?? 'Someone may have already completed this batch — refresh and try again.',
            });
        },
    });

    const reportDownForm = useForm<ReportDownFormValues>({ resolver: zodResolver(reportDownSchema) });
    const reportDownBackdate = reportDownForm.watch('backdate');
    const reportDownMutation = useMutation({
        mutationFn: (values: ReportDownFormValues) => {
            if (!reportingDownMachine || !effectiveShiftId) throw new Error('Missing machine or shift');
            return openDowntimeLog({
                nature_of_problem: values.nature_of_problem,
                work_center_id: reportingDownMachine.id,
                shift_id: effectiveShiftId,
                from_time: values.backdate && values.time ? combineWithToday(today, values.time) : undefined,
            });
        },
        onSuccess: () => {
            invalidateDowntime();
            setReportingDownMachine(null);
            reportDownForm.reset();
        },
        onError: (error: any) => {
            Modal.error({
                title: 'Could not report breakdown',
                content: error?.response?.data?.message ?? 'Someone may have already reported this machine down — refresh and try again.',
            });
        },
    });

    const closeDowntimeForm = useForm<CloseDowntimeFormValues>({ resolver: zodResolver(closeDowntimeSchema) });
    const closeDowntimeBackdate = closeDowntimeForm.watch('backdate');
    const closeDowntimeMutation = useMutation({
        mutationFn: (values: CloseDowntimeFormValues) => {
            if (!closingDowntimeLog) throw new Error('No breakdown selected');
            return closeDowntimeLog(closingDowntimeLog.id, {
                remedy: values.remedy,
                parts_changed: values.parts_changed,
                to_time: values.backdate && values.time ? combineWithToday(today, values.time) : undefined,
            });
        },
        onSuccess: () => {
            invalidateDowntime();
            setClosingDowntimeLog(null);
            closeDowntimeForm.reset();
        },
        onError: (error: any) => {
            Modal.error({
                title: 'Could not close breakdown',
                content: error?.response?.data?.message ?? 'This breakdown may have already been closed — refresh and try again.',
            });
        },
    });

    const moldChangeForm = useForm<MoldChangeFormValues>({ resolver: zodResolver(moldChangeSchema) });
    const moldChangeBackdate = moldChangeForm.watch('backdate');
    const moldChangeMutation = useMutation({
        mutationFn: (values: MoldChangeFormValues) => {
            if (!startingMoldChangeMachine || !effectiveShiftId) throw new Error('Missing machine or shift');
            return openMoldChangeLog({
                changed_from_mold_id: values.changed_from_mold_id,
                changed_to_mold_id: values.changed_to_mold_id,
                changed_to_item_id: values.changed_to_item_id,
                work_center_id: startingMoldChangeMachine.id,
                shift_id: effectiveShiftId,
                from_time: values.backdate && values.time ? combineWithToday(today, values.time) : undefined,
                // Given alongside a From time, this logs the change as
                // already complete — no separate Finish step needed.
                to_time: values.backdate && values.time && values.end_time ? combineWithToday(today, values.end_time) : undefined,
            });
        },
        onSuccess: () => {
            invalidateMoldChange();
            setStartingMoldChangeMachine(null);
            moldChangeForm.reset();
        },
        onError: (error: any) => {
            Modal.error({
                title: 'Could not log mold change',
                content: error?.response?.data?.message ?? 'Someone may have already started a mold change on this machine — refresh and try again.',
            });
        },
    });

    const finishMoldChangeForm = useForm<FinishMoldChangeFormValues>({ resolver: zodResolver(finishMoldChangeSchema) });
    const finishMoldChangeBackdate = finishMoldChangeForm.watch('backdate');
    const finishMoldChangeMutation = useMutation({
        mutationFn: (values: FinishMoldChangeFormValues) => {
            if (!finishingMoldChangeLog) throw new Error('No mold change selected');
            return closeMoldChangeLog(
                finishingMoldChangeLog.id,
                values.backdate && values.time ? combineWithToday(today, values.time) : undefined,
            );
        },
        onSuccess: () => {
            invalidateMoldChange();
            setFinishingMoldChangeLog(null);
            finishMoldChangeForm.reset();
        },
        onError: (error: any) => {
            Modal.error({
                title: 'Could not finish mold change',
                content: error?.response?.data?.message ?? 'This mold change may have already been finished — refresh and try again.',
            });
        },
    });

    const powerInterruptionForm = useForm<PowerInterruptionFormValues>({ resolver: zodResolver(powerInterruptionSchema) });
    const powerInterruptionMutation = useMutation({
        mutationFn: (values: PowerInterruptionFormValues) => {
            if (!effectiveShiftId) throw new Error('Pick a shift');
            // Only the time-of-day is picked (values.from_time/to_time are
            // "HH:mm" strings) — the date is always today's shift, never a
            // separate field to fill in. If the picked "to" clock time is
            // earlier than "from," the interruption crossed midnight (the
            // Night shift runs 22:00-06:00), so it's rolled to the next day.
            const from = dayjs(`${today} ${values.from_time}`);
            let to = dayjs(`${today} ${values.to_time}`);
            if (to.isBefore(from)) to = to.add(1, 'day');

            return createPowerInterruptionLog({
                shift_id: effectiveShiftId,
                production_date: today,
                from_time: from.toISOString(),
                to_time: to.toISOString(),
            });
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'power-interruption-logs'] });
            queryClient.invalidateQueries({ queryKey: ['production', 'shift-kpi-report'] });
            // Stays open, only the fields reset — a grid outage that just
            // happened once often happens again the same shift, and the
            // "Logged today" list right below confirms each one landed
            // instead of silently overwriting the last.
            powerInterruptionForm.reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not log power interruption', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const stockCountForm = useForm<StockCountFormValues>({ resolver: zodResolver(stockCountSchema) });
    const stockCountMutation = useMutation({
        mutationFn: (values: StockCountFormValues) => {
            if (!effectiveShiftId) throw new Error('Pick a shift');
            return createShiftStockCount({ ...values, shift_id: effectiveShiftId });
        },
        onSuccess: () => {
            setStockCountOpen(false);
            stockCountForm.reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not log stock count', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>Shift Floor</Typography.Title>
            <Typography.Paragraph type="secondary">
                Tap a machine to start or complete a batch, close a breakdown, or finish a mold change. One machine
                can run several items in a shift — complete the current item, change the mold, then start the next.
            </Typography.Paragraph>

            {showGraceBanner && (
                <Alert
                    type="info"
                    showIcon
                    closable
                    onClose={() => setGraceBannerDismissed(true)}
                    style={{ marginBottom: 12, maxWidth: 560 }}
                    message={`Auto-selected ${detectedShift?.name} (started ${detectedShift?.start_time.slice(0, 5)}). Still finishing ${endedShift?.name}?`}
                    action={
                        <Button size="small" onClick={() => setSelectedShiftId(endedShift!.id)}>
                            Use {endedShift?.name}
                        </Button>
                    }
                />
            )}

            <Form.Item label="Shift" style={{ maxWidth: 480 }}>
                <Radio.Group
                    value={effectiveShiftId}
                    onChange={(e) => setSelectedShiftId(e.target.value)}
                    optionType="button"
                    buttonStyle="solid"
                    size="large"
                    options={shiftOptions}
                />
            </Form.Item>

            <Row gutter={[12, 12]} style={{ marginBottom: 16 }}>
                {(workCenters?.data ?? []).filter((w) => w.is_active).map((wc) => {
                    const running = runningByMachine.get(wc.id);
                    const down = openDowntimeByMachine.get(wc.id);
                    const moldChange = openMoldChangeByMachine.get(wc.id);
                    // Priority order matches how urgent each state is to
                    // surface — a breakdown or an in-progress mold change
                    // takes precedence over "Running", since those are the
                    // states that need someone's attention next.
                    const cardColor = down ? '#ff4d4f' : moldChange ? '#faad14' : running ? '#52c41a' : undefined;
                    // Live expected output for the running card — the contract
                    // formula at the STANDARD cycle time snapshot, active
                    // cavities, and planned hours = the shift's full length.
                    // Null (nothing shown) when the item has no standards.
                    const liveExpected =
                        !down && !moldChange && running
                            ? expectedOutput(
                                  toNum(running.standard_cycle_time),
                                  running.active_cavities ?? running.standard_cavities,
                                  shiftLengthHours(running.shift),
                                  running.item.nos_per_box,
                                  running.item.nos_per_pouch,
                                  settings?.packing_rounding,
                              )
                            : null;

                    const primaryClick = () => {
                        if (down) {
                            setClosingDowntimeLog(down);
                            closeDowntimeForm.reset();
                        } else if (moldChange) {
                            setFinishingMoldChangeLog(moldChange);
                        } else if (running) {
                            setCompletingEntry(running);
                            // Prefill Nos/Tray and Nos/Box from the item's packing
                            // master when set — for items without standards both are
                            // undefined and this reset is identical to before.
                            // Expected-output prefills: Running Hours defaults to
                            // the shift's full length, Active Cavities to what Start
                            // Batch recorded (itself defaulted from the standard),
                            // and the Masterbatch row to the colour-matched MB item.
                            completeForm.reset({
                                material_consumptions: [],
                                scraps: [],
                                // Minted at Start Batch — prefilled here so nobody
                                // types it; still editable as the exception path.
                                batch_number: running.batch_number ?? undefined,
                                nos_per_tray: running.item.nos_per_tray ?? undefined,
                                nos_per_box: running.item.nos_per_box ?? undefined,
                                running_hours: shiftLengthHours(running.shift) ?? undefined,
                                active_cavities: running.active_cavities ?? running.standard_cavities ?? undefined,
                                mb_item_id: suggestMasterbatchId(items?.data, running.item.colour),
                            });
                        } else {
                            setStartingMachine(wc);
                            // Default the warehouse to a finished-goods godown that
                            // Tally actually knows (tally_guid set) — the voucher's
                            // godown is this warehouse's name, so a seeded lookalike
                            // would fail every voucher. Still editable; with no match
                            // the form opens empty exactly as before.
                            const fgWarehouse = warehouses?.data.find(
                                (w) => w.tally_guid && (/\bfg\b|\bfinished\b/i.test(w.code) || /\bfg\b|\bfinished\b/i.test(w.name)),
                            );
                            startForm.reset(fgWarehouse ? { warehouse_id: fgWarehouse.id } : undefined);
                        }
                    };

                    return (
                        <Col key={wc.id} xs={12} sm={8} md={6} lg={4}>
                            <Card hoverable size="small" onClick={primaryClick} style={cardColor ? { borderColor: cardColor } : undefined}>
                                <Typography.Text strong>{wc.name}</Typography.Text>
                                <div style={{ marginTop: 4, marginBottom: 6 }}>
                                    {down && <Tag color="error">Down — {down.nature_of_problem}</Tag>}
                                    {!down && moldChange && <Tag color="warning">Mold Change</Tag>}
                                    {!down && !moldChange && running && <Tag color="success">Running — {running.item.sku}</Tag>}
                                    {!down && !moldChange && !running && <Tag>Idle</Tag>}
                                </div>
                                {running?.batch_number && (
                                    <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>
                                        Batch {running.batch_number}
                                    </Typography.Text>
                                )}
                                {liveExpected && running && (
                                    <div style={{ marginBottom: 6 }}>
                                        <Typography.Text strong style={{ fontSize: 12 }}>
                                            ≈ {Math.round(liveExpected.pieces).toLocaleString('en-IN')} pcs
                                            {liveExpected.pouches !== null ? ` · ${liveExpected.pouches} pouches` : ''}
                                            {liveExpected.boxes !== null ? ` · ${liveExpected.boxes} boxes` : ''}
                                        </Typography.Text>
                                        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 11 }}>
                                            {fmtNum(toNum(running.standard_cycle_time))} s × {running.active_cavities ?? running.standard_cavities} cav ×{' '}
                                            {fmtNum(shiftLengthHours(running.shift))} h
                                        </Typography.Text>
                                    </div>
                                )}
                                {!down && !moldChange && (
                                    // Stacked full-width buttons: side-by-side small
                                    // buttons overlapped on a phone-width card and
                                    // were too small to hit with a thumb.
                                    <Space direction="vertical" size={6} style={{ width: '100%' }}>
                                        <Button
                                            block
                                            danger
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                setReportingDownMachine(wc);
                                                reportDownForm.reset();
                                            }}
                                        >
                                            Report Down
                                        </Button>
                                        {!running && (
                                            <Button
                                                block
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setStartingMoldChangeMachine(wc);
                                                    moldChangeForm.reset();
                                                }}
                                            >
                                                Mold Change
                                            </Button>
                                        )}
                                    </Space>
                                )}
                            </Card>
                        </Col>
                    );
                })}
            </Row>

            <Space style={{ marginBottom: 32 }}>
                <Button onClick={() => setPowerInterruptionOpen(true)}>
                    Log Power Interruption{powerInterruptionsToday.length > 0 ? ` (${powerInterruptionsToday.length} today)` : ''}
                </Button>
                <Button onClick={() => setStockCountOpen(true)}>Log Stock Count</Button>
            </Space>

            <Typography.Title level={5}>Completed Today</Typography.Title>
            <Table<ShiftProductionEntry>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                size="small"
                loading={entriesLoading}
                pagination={false}
                dataSource={completedToday}
                locale={{ emptyText: 'Nothing completed yet today.' }}
                columns={[
                    { title: 'Machine', render: (_, row) => row.work_center.name },
                    { title: 'Shift', render: (_, row) => row.shift.name },
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Batch #', dataIndex: 'batch_number', render: (v: string | null) => v ?? '—' },
                    { title: 'Produced', dataIndex: 'quantity_produced' },
                    { title: 'Produced (Kg)', dataIndex: 'quantity_produced_kg', render: (v: string | null) => v ?? '—' },
                    { title: 'Rejected', dataIndex: 'quantity_scrap' },
                    {
                        title: 'Approval',
                        dataIndex: 'status',
                        render: (status: ShiftProductionEntryStatus) => <Tag color={approvalColor[status]}>{status}</Tag>,
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title={`Start Batch — ${startingMachine?.name}`}
                open={startingMachine !== null}
                onCancel={() => setStartingMachine(null)}
                onOk={startForm.handleSubmit((values) => startMutation.mutate(values))}
                confirmLoading={startMutation.isPending}
                destroyOnHidden
            >
                {/* Confirmation of where this batch will be filed — the shift is
                    auto-picked from the clock, so show it rather than ask again. */}
                <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
                    Shift: <Typography.Text strong>{effectiveShift?.name ?? '—'}</Typography.Text>
                    {' · '}Date: <Typography.Text strong>{today}</Typography.Text>
                </Typography.Paragraph>
                <Form layout="vertical">
                    <Form.Item
                        label="Item"
                        validateStatus={startForm.formState.errors.item_id ? 'error' : ''}
                        help={startForm.formState.errors.item_id?.message}
                    >
                        <Controller
                            name="item_id"
                            control={startForm.control}
                            render={({ field }) => (
                                <Select {...field} size="large" options={itemOptions} showSearch optionFilterProp="label" placeholder="Search item…" />
                            )}
                        />
                    </Form.Item>
                    {startItem && (
                        <>
                            {/* Read-only card of the item master's standards — what the
                                expected-output engine will hold this run against. */}
                            <Descriptions
                                size="small"
                                column={2}
                                bordered
                                style={{ marginBottom: 16 }}
                                title={<Typography.Text strong>Product standards</Typography.Text>}
                            >
                                <Descriptions.Item label="Colour">{startItem.colour ?? '—'}</Descriptions.Item>
                                <Descriptions.Item label="Weight">
                                    {startItem.nominal_weight_grams ? `${fmtNum(toNum(startItem.nominal_weight_grams))} g` : '—'}
                                </Descriptions.Item>
                                <Descriptions.Item label="Std CT">
                                    {startItem.standard_cycle_time ? `${fmtNum(toNum(startItem.standard_cycle_time))} s` : '—'}
                                </Descriptions.Item>
                                <Descriptions.Item label="Std cavities">{startItem.standard_cavities ?? '—'}</Descriptions.Item>
                                <Descriptions.Item label="Pcs/box">{startItem.nos_per_box ?? '—'}</Descriptions.Item>
                                <Descriptions.Item label="Pcs/tray">{startItem.nos_per_tray ?? '—'}</Descriptions.Item>
                            </Descriptions>
                            <Form.Item
                                label="Active Cavities"
                                validateStatus={startForm.formState.errors.active_cavities ? 'error' : ''}
                                help={startForm.formState.errors.active_cavities?.message}
                                extra={startItem.standard_cavities ? `std: ${startItem.standard_cavities}` : undefined}
                            >
                                <Controller
                                    name="active_cavities"
                                    control={startForm.control}
                                    render={({ field }) => (
                                        <InputNumber {...field} size="large" min={1} style={{ width: '100%' }} placeholder="Cavities actually running" />
                                    )}
                                />
                            </Form.Item>
                        </>
                    )}
                    <Form.Item
                        label="Finished Goods Warehouse"
                        validateStatus={startForm.formState.errors.warehouse_id ? 'error' : ''}
                        help={startForm.formState.errors.warehouse_id?.message}
                    >
                        <Controller
                            name="warehouse_id"
                            control={startForm.control}
                            render={({ field }) => <Select {...field} size="large" options={warehouseOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item label="Operator (optional)">
                        <Controller
                            name="operator_id"
                            control={startForm.control}
                            render={({ field }) => <Select {...field} options={employeeOptions} showSearch optionFilterProp="label" allowClear />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Drawer
                title={`Complete Batch — ${completingEntry?.work_center.name} · ${completingEntry?.item.sku}`}
                open={completingEntry !== null}
                onClose={() => setCompletingEntry(null)}
                width="min(100vw, 560px)"
                destroyOnHidden
                extra={
                    <Button
                        type="primary"
                        loading={completeMutation.isPending}
                        onClick={completeForm.handleSubmit((values) => completeMutation.mutate(values))}
                    >
                        Complete Batch
                    </Button>
                }
            >
                <Form layout="vertical">
                    <Form.Item label="Batch Number (optional)">
                        <Controller name="batch_number" control={completeForm.control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>

                    {/* Box-first: boxes are what the floor physically counts.
                        Pieces derive from boxes × pcs/box + loose, and stay
                        editable for corrections. */}
                    <Row gutter={16}>
                        <Col span={12}>
                            <Form.Item
                                label="Good Boxes"
                                validateStatus={completeForm.formState.errors.no_of_box ? 'error' : ''}
                                help={completeForm.formState.errors.no_of_box?.message}
                            >
                                <Controller
                                    name="no_of_box"
                                    control={completeForm.control}
                                    render={({ field }) => <InputNumber {...field} size="large" min={0} style={{ width: '100%' }} />}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item label="Loose Pieces (optional)">
                                <Controller
                                    name="loose_pieces"
                                    control={completeForm.control}
                                    render={({ field }) => <InputNumber {...field} size="large" min={0} style={{ width: '100%' }} />}
                                />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Row gutter={16}>
                        <Col span={12}>
                            <Form.Item
                                label="Quantity Produced (Nos)"
                                validateStatus={completeForm.formState.errors.quantity_produced ? 'error' : ''}
                                help={completeForm.formState.errors.quantity_produced?.message}
                                extra={
                                    completingEntry?.item.nos_per_box
                                        ? `= boxes × ${completingEntry.item.nos_per_box} pcs/box + loose — editable`
                                        : showPouchFields
                                          ? `= pouches × ${completingItem?.nos_per_pouch} pcs/pouch + loose — editable`
                                          : undefined
                                }
                            >
                                <Controller
                                    name="quantity_produced"
                                    control={completeForm.control}
                                    render={({ field }) => <InputNumber {...field} size="large" min={0} style={{ width: '100%' }} />}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item label="Quantity Produced (Kg)">
                                <Input size="large" disabled value={previewProducedKg ?? (nominalWeight ? '—' : 'No nominal weight set')} />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Row gutter={16}>
                        <Col span={12}>
                            <Form.Item label="Quantity Rejected (Nos)">
                                <Controller
                                    name="quantity_scrap"
                                    control={completeForm.control}
                                    render={({ field }) => <InputNumber {...field} size="large" min={0} style={{ width: '100%' }} />}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item label="Quantity Rejected (Kg)">
                                <Input size="large" disabled value={previewRejectionKg ?? '—'} />
                            </Form.Item>
                        </Col>
                    </Row>

                    {!!quantityScrap && quantityScrap > 0 && (
                        <Form.Item label="Rejection Reason">
                            <Controller
                                name="scrap_reason_id"
                                control={completeForm.control}
                                render={({ field }) => <Select {...field} options={scrapReasonOptions} showSearch optionFilterProp="label" allowClear />}
                            />
                        </Form.Item>
                    )}

                    <Form.Item
                        label="QC Rejection (Kg) — optional"
                        validateStatus={completeForm.formState.errors.qc_rejection_kg ? 'error' : ''}
                        help={completeForm.formState.errors.qc_rejection_kg?.message}
                        extra="QC's weighed figure — overrides the calculated rejection kg when present"
                    >
                        <Controller
                            name="qc_rejection_kg"
                            control={completeForm.control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} suffix="Kg" />}
                        />
                    </Form.Item>

                    <Typography.Text strong>Run Details</Typography.Text>
                    <Row gutter={16} style={{ marginTop: 8 }}>
                        <Col xs={12} sm={8}>
                            <Form.Item
                                label="Running Hours"
                                validateStatus={completeForm.formState.errors.running_hours ? 'error' : ''}
                                help={completeForm.formState.errors.running_hours?.message}
                                extra="default: full shift"
                            >
                                <Controller
                                    name="running_hours"
                                    control={completeForm.control}
                                    render={({ field }) => <InputNumber {...field} min={0} max={24} step={0.5} style={{ width: '100%' }} />}
                                />
                            </Form.Item>
                        </Col>
                        <Col xs={12} sm={8}>
                            <Form.Item
                                label="Actual Cycle Time (s)"
                                validateStatus={completeForm.formState.errors.actual_cycle_time ? 'error' : ''}
                                help={completeForm.formState.errors.actual_cycle_time?.message}
                            >
                                <Controller
                                    name="actual_cycle_time"
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        <InputNumber
                                            {...field}
                                            min={0}
                                            step={0.1}
                                            style={{ width: '100%' }}
                                            placeholder={
                                                completingEntry?.standard_cycle_time
                                                    ? `std ${fmtNum(toNum(completingEntry.standard_cycle_time))}`
                                                    : undefined
                                            }
                                        />
                                    )}
                                />
                            </Form.Item>
                        </Col>
                        <Col xs={12} sm={8}>
                            <Form.Item
                                label="Active Cavities"
                                validateStatus={completeForm.formState.errors.active_cavities ? 'error' : ''}
                                help={completeForm.formState.errors.active_cavities?.message}
                                extra={completingEntry?.standard_cavities ? `std: ${completingEntry.standard_cavities}` : undefined}
                            >
                                <Controller
                                    name="active_cavities"
                                    control={completeForm.control}
                                    render={({ field }) => <InputNumber {...field} min={1} style={{ width: '100%' }} />}
                                />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Typography.Text strong>Packing</Typography.Text>
                    {/* Applicability is data-driven from the item's packing
                        master: tray fields for tray-packed items (or items
                        with no standards at all — the pre-pouch manual set),
                        pouch count only for pouch-packed items, Nos/Box
                        always (boxes are the universal outer). */}
                    <Row gutter={16} style={{ marginTop: 8, marginBottom: 16 }}>
                        {showTrayFields && (
                            <Col xs={12} sm={8}>
                                <Form.Item label="Nos/Tray">
                                    <Controller name="nos_per_tray" control={completeForm.control} render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />} />
                                </Form.Item>
                            </Col>
                        )}
                        {showTrayFields && (
                            <Col xs={12} sm={8}>
                                <Form.Item label="Trays">
                                    <Controller name="no_of_trays" control={completeForm.control} render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />} />
                                </Form.Item>
                            </Col>
                        )}
                        {showPouchFields && (
                            <Col xs={12} sm={8}>
                                <Form.Item label="Pouches" extra={`std: ${completingItem?.nos_per_pouch}/pouch`}>
                                    <Controller name="no_of_pouches" control={completeForm.control} render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />} />
                                </Form.Item>
                            </Col>
                        )}
                        <Col xs={12} sm={8}>
                            <Form.Item label="Nos/Box">
                                <Controller name="nos_per_box" control={completeForm.control} render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />} />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Typography.Text strong>Material Consumption</Typography.Text>

                    {/* Fixed rows for the two materials every molding batch
                        consumes — pickers scoped to resins / masterbatches so
                        the right item is one tap, not a 642-item search. Rows
                        without a quantity are simply not sent. */}
                    <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                        Resin (kg)
                    </Typography.Text>
                    <Row gutter={[8, 8]} align="top" style={{ marginTop: 4 }}>
                        <Col xs={24} sm={10}>
                            <Form.Item
                                style={{ marginBottom: 0 }}
                                validateStatus={completeForm.formState.errors.resin_item_id ? 'error' : ''}
                                help={completeForm.formState.errors.resin_item_id?.message}
                            >
                                <Controller
                                    name="resin_item_id"
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        <Select {...field} size="large" options={resinOptions} showSearch optionFilterProp="label" allowClear style={{ width: '100%' }} placeholder="Resin…" />
                                    )}
                                />
                            </Form.Item>
                        </Col>
                        <Col xs={12} sm={7}>
                            <Form.Item
                                style={{ marginBottom: 0 }}
                                validateStatus={completeForm.formState.errors.resin_warehouse_id ? 'error' : ''}
                                help={completeForm.formState.errors.resin_warehouse_id?.message}
                            >
                                <Controller
                                    name="resin_warehouse_id"
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        <Select {...field} size="large" options={warehouseOptions} showSearch optionFilterProp="label" allowClear style={{ width: '100%' }} placeholder="From" />
                                    )}
                                />
                            </Form.Item>
                        </Col>
                        <Col xs={12} sm={7}>
                            <Controller
                                name="resin_kg"
                                control={completeForm.control}
                                render={({ field }) => <InputNumber {...field} size="large" min={0} placeholder="Kg" suffix="Kg" style={{ width: '100%' }} />}
                            />
                        </Col>
                    </Row>

                    <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                        Masterbatch (kg)
                        {completingEntry && isClearColour(completingEntry.item.colour) && ' — No masterbatch for Clear'}
                    </Typography.Text>
                    <Row gutter={[8, 8]} align="top" style={{ marginTop: 4 }}>
                        <Col xs={24} sm={10}>
                            <Form.Item
                                style={{ marginBottom: 0 }}
                                validateStatus={completeForm.formState.errors.mb_item_id ? 'error' : ''}
                                help={completeForm.formState.errors.mb_item_id?.message}
                            >
                                <Controller
                                    name="mb_item_id"
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        <Select {...field} size="large" options={mbOptions} showSearch optionFilterProp="label" allowClear style={{ width: '100%' }} placeholder="Masterbatch…" />
                                    )}
                                />
                            </Form.Item>
                        </Col>
                        <Col xs={12} sm={7}>
                            <Form.Item
                                style={{ marginBottom: 0 }}
                                validateStatus={completeForm.formState.errors.mb_warehouse_id ? 'error' : ''}
                                help={completeForm.formState.errors.mb_warehouse_id?.message}
                            >
                                <Controller
                                    name="mb_warehouse_id"
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        <Select {...field} size="large" options={warehouseOptions} showSearch optionFilterProp="label" allowClear style={{ width: '100%' }} placeholder="From" />
                                    )}
                                />
                            </Form.Item>
                        </Col>
                        <Col xs={12} sm={7}>
                            <Controller
                                name="mb_kg"
                                control={completeForm.control}
                                render={({ field }) => <InputNumber {...field} size="large" min={0} placeholder="Kg" suffix="Kg" style={{ width: '100%' }} />}
                            />
                        </Col>
                    </Row>

                    <Space style={{ justifyContent: 'space-between', width: '100%', marginTop: 12 }}>
                        <Typography.Text type="secondary">Other materials (exceptions)</Typography.Text>
                        <Button
                            size="small"
                            onClick={() =>
                                materialFields.append({
                                    item_id: undefined as unknown as number,
                                    warehouse_id: undefined as unknown as number,
                                    quantity_issued_kg: undefined as unknown as number,
                                })
                            }
                        >
                            Add Line
                        </Button>
                    </Space>
                    {materialFields.fields.map((field, index) => {
                        // Show the quantity in the selected material's own unit —
                        // resin/masterbatch are Kg, but caps/cartons/trays are Nos
                        // (factory answer: UOM comes from the item master).
                        const selectedItemId = completeForm.watch(`material_consumptions.${index}.item_id`);
                        const selectedUom = items?.data.find((i) => i.id === selectedItemId)?.uom ?? 'Kg';
                        return (
                        <Row key={field.id} gutter={[8, 8]} align="middle" style={{ marginTop: 8 }}>
                            <Col xs={24} sm={10}>
                                <Controller
                                    name={`material_consumptions.${index}.item_id`}
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        <Select {...field} size="large" options={itemOptions} showSearch optionFilterProp="label" style={{ width: '100%' }} placeholder="Resin/Masterbatch" />
                                    )}
                                />
                            </Col>
                            <Col xs={12} sm={6}>
                                <Controller
                                    name={`material_consumptions.${index}.warehouse_id`}
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        <Select {...field} size="large" options={warehouseOptions} showSearch optionFilterProp="label" style={{ width: '100%' }} placeholder="From" />
                                    )}
                                />
                            </Col>
                            <Col xs={12} sm={5}>
                                <Controller
                                    name={`material_consumptions.${index}.quantity_issued_kg`}
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        <InputNumber {...field} size="large" min={0} placeholder={selectedUom} suffix={selectedUom} style={{ width: '100%' }} />
                                    )}
                                />
                            </Col>
                            <Col xs={24} sm={3}>
                                <Button danger block onClick={() => materialFields.remove(index)}>Remove</Button>
                            </Col>
                        </Row>
                        );
                    })}

                    <Space style={{ justifyContent: 'space-between', width: '100%', marginTop: 16 }}>
                        <Typography.Text strong>Lumps / Other Scrap</Typography.Text>
                        <Button
                            size="small"
                            onClick={() => scrapFields.append({ type: 'lumps', quantity_nos: undefined, quantity_kg: undefined, scrap_reason_id: undefined })}
                        >
                            Add Line
                        </Button>
                    </Space>
                    {scrapFields.fields.map((field, index) => (
                        <Row key={field.id} gutter={[8, 8]} align="middle" style={{ marginTop: 8 }}>
                            <Col xs={24} sm={10}>
                                <Controller
                                    name={`scraps.${index}.type`}
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        <Select
                                            {...field}
                                            size="large"
                                            style={{ width: '100%' }}
                                            options={[
                                                { value: 'lumps', label: 'Lumps' },
                                                { value: 'rejected_finished_good', label: 'Rejected FG' },
                                            ]}
                                        />
                                    )}
                                />
                            </Col>
                            <Col xs={12} sm={6}>
                                <Controller
                                    name={`scraps.${index}.quantity_kg`}
                                    control={completeForm.control}
                                    render={({ field }) => <InputNumber {...field} size="large" min={0} placeholder="Kg" style={{ width: '100%' }} />}
                                />
                            </Col>
                            <Col xs={12} sm={5}>
                                <Controller
                                    name={`scraps.${index}.quantity_nos`}
                                    control={completeForm.control}
                                    render={({ field }) => <InputNumber {...field} size="large" min={0} placeholder="Nos" style={{ width: '100%' }} />}
                                />
                            </Col>
                            <Col xs={24} sm={3}>
                                <Button danger block onClick={() => scrapFields.remove(index)}>Remove</Button>
                            </Col>
                        </Row>
                    ))}

                    <Form.Item
                        label="Helper name (optional)"
                        style={{ marginTop: 16 }}
                        validateStatus={completeForm.formState.errors.helper_name ? 'error' : ''}
                        help={completeForm.formState.errors.helper_name?.message}
                    >
                        <Controller
                            name="helper_name"
                            control={completeForm.control}
                            render={({ field }) => <Input {...field} maxLength={120} placeholder="Who helped the operator this batch" />}
                        />
                    </Form.Item>
                    <Form.Item label="Notes (optional)">
                        <Controller name="notes" control={completeForm.control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>

                    {/* Pre-submit results: the same numbers the approvers will
                        see, computed live so surprises surface BEFORE the
                        entry enters the approval chain. Rows with missing
                        inputs are hidden — never a fake 0. */}
                    {results && (
                        <Card size="small" title="Results — check before you submit" style={{ marginTop: 8 }}>
                            {results.expected && (
                                <ResultRow
                                    label="Expected output"
                                    value={`${fmtNum(results.expected.pieces, 0)} pcs${
                                        results.expected.pouches !== null ? ` · ${results.expected.pouches} pouches` : ''
                                    }${results.expected.boxes !== null ? ` · ${results.expected.boxes} boxes` : ''}`}
                                    formula={`3600 / ${fmtNum(results.ct)} s × ${results.cavities} cavities × ${fmtNum(results.hours)} h${
                                        results.expected.pouches !== null && results.nosPerPouch ? ` ÷ ${results.nosPerPouch} pcs/pouch` : ''
                                    }${results.expected.boxes !== null && results.nosPerBox ? ` ÷ ${results.nosPerBox} pcs/box` : ''}`}
                                />
                            )}
                            {!!quantityProduced && quantityProduced > 0 && (
                                <ResultRow
                                    label="Actual output"
                                    value={`${quantityProduced.toLocaleString('en-IN')} pcs${
                                        results.actualPouches !== null && results.nosPerPouch ? ` · ${results.actualPouches} pouches` : ''
                                    }${results.actualBoxes !== null ? ` · ${results.actualBoxes} boxes` : ''}`}
                                    formula={
                                        results.nosPerBox && results.nosPerBox >= 1
                                            ? 'good boxes × pcs/box + loose'
                                            : results.nosPerPouch && results.nosPerPouch >= 1
                                              ? 'pouches × pcs/pouch + loose'
                                              : 'good boxes × pcs/box + loose'
                                    }
                                />
                            )}
                            {results.efficiencyPct !== null && (
                                <ResultRow
                                    label="Efficiency"
                                    value={
                                        <Space size={6}>
                                            {`${results.efficiencyPct}%`}
                                            {efficiencyTag(results.efficiencyPct)}
                                        </Space>
                                    }
                                    formula={`${results.actualBoxes} boxes ÷ ${results.expected?.boxes} expected × 100`}
                                />
                            )}
                            {results.goodKg !== null && (
                                <ResultRow
                                    label="Production"
                                    value={`${fmtNum(results.goodKg)} kg`}
                                    formula={`${quantityProduced} pcs × ${fmtNum(nominalWeight)} g ÷ 1000`}
                                />
                            )}
                            {results.rejProdKg !== null && (
                                <ResultRow
                                    label="Rejection (production)"
                                    value={`${fmtNum(results.rejProdKg)} kg`}
                                    formula={`${quantityScrap} pcs × ${fmtNum(nominalWeight)} g ÷ 1000`}
                                />
                            )}
                            {results.qcKg !== null && (
                                <ResultRow label="Rejection (QC weighed)" value={`${fmtNum(results.qcKg)} kg`} formula="QC's figure wins when present" />
                            )}
                            {results.rejDiffKg !== null && (
                                <ResultRow label="Rejection difference" value={`${fmtNum(results.rejDiffKg)} kg`} formula="production − QC" />
                            )}
                            {results.lumpsKg > 0 && <ResultRow label="Lumps" value={`${fmtNum(results.lumpsKg)} kg`} formula="sum of lump scrap lines" />}
                            {results.issuedKg > 0 && (
                                <ResultRow label="Material issued" value={`${fmtNum(results.issuedKg)} kg`} formula="resin + masterbatch + other lines" />
                            )}
                            {results.unaccountedKg !== null && (
                                <ResultRow
                                    label="Unaccounted"
                                    value={`${fmtNum(results.unaccountedKg)} kg`}
                                    formula="issued − good − rejection (QC wins) − lumps"
                                    danger={Math.abs(results.unaccountedKg) > 0.5}
                                />
                            )}
                        </Card>
                    )}
                </Form>
            </Drawer>

            <Modal
                maskClosable={false}
                title={`Report Down — ${reportingDownMachine?.name}`}
                open={reportingDownMachine !== null}
                onCancel={() => setReportingDownMachine(null)}
                onOk={reportDownForm.handleSubmit((values) => reportDownMutation.mutate(values))}
                confirmLoading={reportDownMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Nature of Problem"
                        validateStatus={reportDownForm.formState.errors.nature_of_problem ? 'error' : ''}
                        help={reportDownForm.formState.errors.nature_of_problem?.message}
                    >
                        <Controller
                            name="nature_of_problem"
                            control={reportDownForm.control}
                            render={({ field }) => <Input {...field} size="large" placeholder="e.g. Heater fault" autoFocus />}
                        />
                    </Form.Item>
                    <BackdateField control={reportDownForm.control} backdateEnabled={!!reportDownBackdate} />
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Close Breakdown — ${closingDowntimeLog?.work_center.name}`}
                open={closingDowntimeLog !== null}
                onCancel={() => setClosingDowntimeLog(null)}
                onOk={closeDowntimeForm.handleSubmit((values) => closeDowntimeMutation.mutate(values))}
                confirmLoading={closeDowntimeMutation.isPending}
                destroyOnHidden
            >
                <Typography.Paragraph type="secondary">
                    {closingDowntimeLog?.nature_of_problem}
                </Typography.Paragraph>
                <Form layout="vertical">
                    <Form.Item label="Remedy">
                        <Controller name="remedy" control={closeDowntimeForm.control} render={({ field }) => <Input.TextArea {...field} rows={2} />} />
                    </Form.Item>
                    <Form.Item label="Parts Changed (optional)">
                        <Controller name="parts_changed" control={closeDowntimeForm.control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <BackdateField control={closeDowntimeForm.control} backdateEnabled={!!closeDowntimeBackdate} />
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Mold Change — ${startingMoldChangeMachine?.name}`}
                open={startingMoldChangeMachine !== null}
                onCancel={() => setStartingMoldChangeMachine(null)}
                onOk={moldChangeForm.handleSubmit((values) => moldChangeMutation.mutate(values))}
                confirmLoading={moldChangeMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Mold Coming Out (optional)">
                        <Controller
                            name="changed_from_mold_id"
                            control={moldChangeForm.control}
                            render={({ field }) => <Select {...field} options={allMoldOptions} showSearch optionFilterProp="label" allowClear placeholder="Which mold was running…" />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Mold Going In"
                        validateStatus={moldChangeForm.formState.errors.changed_to_mold_id ? 'error' : ''}
                        help={moldChangeForm.formState.errors.changed_to_mold_id?.message ?? (moldOptions.length === 0 ? 'No active molds — add one on the Molds page.' : undefined)}
                    >
                        <Controller
                            name="changed_to_mold_id"
                            control={moldChangeForm.control}
                            render={({ field }) => <Select {...field} size="large" options={moldOptions} showSearch optionFilterProp="label" placeholder="Which mold…" />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Item It Will Produce"
                        validateStatus={moldChangeForm.formState.errors.changed_to_item_id ? 'error' : ''}
                        help={moldChangeForm.formState.errors.changed_to_item_id?.message}
                    >
                        <Controller
                            name="changed_to_item_id"
                            control={moldChangeForm.control}
                            render={({ field }) => <Select {...field} size="large" options={itemOptions} showSearch optionFilterProp="label" placeholder="Which item/colour…" />}
                        />
                    </Form.Item>
                    <BackdateField control={moldChangeForm.control} backdateEnabled={!!moldChangeBackdate} rangeEndFieldName="end_time" />
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Finish Mold Change — ${finishingMoldChangeLog?.work_center.name}`}
                open={finishingMoldChangeLog !== null}
                onCancel={() => setFinishingMoldChangeLog(null)}
                onOk={finishMoldChangeForm.handleSubmit((values) => finishMoldChangeMutation.mutate(values))}
                confirmLoading={finishMoldChangeMutation.isPending}
                okText="Finish"
                destroyOnHidden
            >
                <Typography.Paragraph>
                    Ready to start <strong>{finishingMoldChangeLog?.changed_to_item?.sku}</strong>? This stops the mold-change clock.
                </Typography.Paragraph>
                <Form layout="vertical">
                    <BackdateField control={finishMoldChangeForm.control} backdateEnabled={!!finishMoldChangeBackdate} />
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title="Log Power Interruption"
                open={powerInterruptionOpen}
                onCancel={() => setPowerInterruptionOpen(false)}
                onOk={powerInterruptionForm.handleSubmit((values) => powerInterruptionMutation.mutate(values))}
                confirmLoading={powerInterruptionMutation.isPending}
                destroyOnHidden
            >
                <Typography.Paragraph type="secondary">
                    Plant-wide, not per-machine. Just the time — today&apos;s date is assumed, and a "To" time earlier
                    than "From" is taken as crossing midnight (Night shift).
                </Typography.Paragraph>
                <Form layout="vertical">
                    <Row gutter={16}>
                        <Col span={12}>
                            <Form.Item
                                label="From"
                                validateStatus={powerInterruptionForm.formState.errors.from_time ? 'error' : ''}
                                help={powerInterruptionForm.formState.errors.from_time?.message}
                            >
                                <Controller
                                    name="from_time"
                                    control={powerInterruptionForm.control}
                                    render={({ field }) => (
                                        <TimePicker
                                            format="HH:mm"
                                            size="large"
                                            style={{ width: '100%' }}
                                            value={field.value ? dayjs(field.value, 'HH:mm') : null}
                                            onChange={(_, timeString) => field.onChange((Array.isArray(timeString) ? timeString[0] : timeString) || undefined)}
                                        />
                                    )}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item
                                label="To"
                                validateStatus={powerInterruptionForm.formState.errors.to_time ? 'error' : ''}
                                help={powerInterruptionForm.formState.errors.to_time?.message}
                            >
                                <Controller
                                    name="to_time"
                                    control={powerInterruptionForm.control}
                                    render={({ field }) => (
                                        <TimePicker
                                            format="HH:mm"
                                            size="large"
                                            style={{ width: '100%' }}
                                            value={field.value ? dayjs(field.value, 'HH:mm') : null}
                                            onChange={(_, timeString) => field.onChange((Array.isArray(timeString) ? timeString[0] : timeString) || undefined)}
                                        />
                                    )}
                                />
                            </Form.Item>
                        </Col>
                    </Row>
                </Form>

                {powerInterruptionsToday.length > 0 && (
                    <>
                        <Typography.Text strong>Logged today</Typography.Text>
                        <Table
                            size="small"
                            rowKey="id"
                            pagination={false}
                            showHeader={false}
                            style={{ marginTop: 8 }}
                            dataSource={powerInterruptionsToday}
                            columns={[
                                { render: (_, row) => dayjs(row.from_time).format('HH:mm') },
                                { render: () => '→' },
                                { render: (_, row) => dayjs(row.to_time).format('HH:mm') },
                                { render: (_, row) => `${row.idle_hours} hrs` },
                            ]}
                        />
                    </>
                )}
            </Modal>

            <Modal
                maskClosable={false}
                title="Log Stock Count"
                open={stockCountOpen}
                onCancel={() => setStockCountOpen(false)}
                onOk={stockCountForm.handleSubmit((values) => stockCountMutation.mutate(values))}
                confirmLoading={stockCountMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Location"
                        validateStatus={stockCountForm.formState.errors.location_label ? 'error' : ''}
                        help={stockCountForm.formState.errors.location_label?.message}
                    >
                        <Controller
                            name="location_label"
                            control={stockCountForm.control}
                            render={({ field }) => <Select {...field} options={locationLabelOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Item"
                        validateStatus={stockCountForm.formState.errors.item_id ? 'error' : ''}
                        help={stockCountForm.formState.errors.item_id?.message}
                    >
                        <Controller
                            name="item_id"
                            control={stockCountForm.control}
                            render={({ field }) => <Select {...field} options={itemOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item label="Quantity (Kg)">
                        <Controller
                            name="quantity_kg"
                            control={stockCountForm.control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
