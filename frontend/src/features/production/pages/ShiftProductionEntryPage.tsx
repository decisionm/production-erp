import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, Checkbox, Col, Descriptions, Drawer, Form, Input, InputNumber, type InputRef, Modal, Radio, Row, Select, Space, Table, Tag, TimePicker, Typography } from 'antd';
import dayjs from 'dayjs';
import type { ReactNode } from 'react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { z } from 'zod';
import { listUsers } from '@/features/access/api';
import { useAuthStore } from '@/features/auth/store';
import { listAllEmployees } from '@/features/hrms/api';
import { listAllItems, listAllWarehouses } from '@/features/inventory/api';
import type { Item } from '@/features/inventory/types';
import DayBinDrawer from '@/features/production/components/DayBinDrawer';
import HandoverModal from '@/features/production/components/HandoverModal';
import {
    closeDowntimeLog,
    closeMoldChangeLog,
    completeBatch,
    createPowerInterruptionLog,
    createShiftStockCount,
    findMaterialBagByBarcode,
    getBinBayAvailability,
    getEntryDayBinSummary,
    getFactoryDayBin,
    listDowntimeReasons,
    listMachineDowntimeLogs,
    listAllMolds,
    listMoldChangeLogs,
    listPowerInterruptionLogs,
    listAllScrapReasons,
    listActiveBatches,
    listStandardCoverage,
    listShiftProductionEntries,
    listShifts,
    listWorkCenters,
    loadBagToFactoryDayBin,
    openDowntimeLog,
    openMoldChangeLog,
    getBatchPreview,
    saveDowntimeReason,
    startBatch,
} from '@/features/production/api';
import type {
    BinBayAvailability,
    BinBayRequirementComponent,
    DowntimeReason,
    EntryDayBinMaterialSummary,
    MachineDowntimeLog,
    MaterialBag,
    MoldChangeLog,
    Shift,
    ShiftProductionEntry,
    ShiftProductionEntryStatus,
    StandardPackaging,
    WorkCenter,
} from '@/features/production/types';
import { currentShift, justEndedShift, productionDateFor } from '@/features/production/shiftClock';
import { roundPer, useProductionSettings } from '@/features/production/packing';
import { itemLabel } from '@/lib/itemLabel';
import {
    buildStartBatchRecipeUrl,
    hasStartBatchResume,
    parseStartBatchResume,
    type StartBatchResumeDraft,
    type StartBatchResumeOutcome,
} from '@/features/production/startBatchResume';

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
 * Minutes between two "HH:mm" picks on a downtime line. A "to" earlier than
 * "from" crossed midnight (Night shift) — same convention as
 * shiftLengthHours, except equal times mean 0 minutes, not a full day.
 * Null (line ignored) while either pick is missing or unparseable.
 */
function downtimeLineMinutes(fromTime: string | null | undefined, toTime: string | null | undefined): number | null {
    if (!fromTime || !toTime) return null;
    const [fh, fm] = fromTime.split(':').map(Number);
    const [th, tm] = toTime.split(':').map(Number);
    if ([fh, fm, th, tm].some((n) => Number.isNaN(n))) return null;
    let minutes = th * 60 + tm - (fh * 60 + fm);
    if (minutes < 0) minutes += 24 * 60;
    return minutes;
}

/**
 * A code for a downtime reason typed on the shift floor: "compressor trip"
 * → "DT-COMPRESSOR-TRIP". The backend requires code (unique, max 32) but a
 * supervisor should only ever have to type the words.
 */
function downtimeReasonCode(description: string): string {
    const slug = description
        .toUpperCase()
        .replace(/[^A-Z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    return `DT-${slug}`.slice(0, 32).replace(/-+$/, '');
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

// ---------------------------------------------------------------------------
// Multi-mode packing
//
// A product standard exposes ONLY the packaging modes its imported row
// carries — pouch is never offered universally. A run may genuinely use more
// than one of them (part of the shift trayed, part pouched), so packing is a
// LIST of lines, one per mode, and the batch's pieces are the sum of them.
//
// The carton/box is the OUTER package in every mode; tray and pouch are the
// inner ones. Every figure comes from the imported nos_per_box /
// nos_per_tray / nos_per_pouch — never from an assumed 5 per box.
// ---------------------------------------------------------------------------

type PackingMode = StandardPackaging['mode'];

const MODE_LABEL: Record<PackingMode, string> = {
    pouch: 'Pouch → box',
    tray: 'Tray → box',
    direct_box: 'Straight into the box',
};

/** The inner container's plural, or null for direct-to-box (no inner). */
function innerNoun(mode: PackingMode): string | null {
    return mode === 'pouch' ? 'pouches' : mode === 'tray' ? 'trays' : null;
}

/** Pieces per inner container for a mode, straight from the imported standard. */
function innerPackSize(packaging: StandardPackaging): number | null {
    if (packaging.mode === 'pouch') return packaging.nos_per_pouch;
    if (packaging.mode === 'tray') return packaging.nos_per_tray;
    return null;
}

/** Inner containers per carton — used for the tray/pouch COUNT, never for pieces. */
function innersPerBox(packaging: StandardPackaging): number | null {
    if (packaging.mode === 'pouch') return packaging.pouches_per_box;
    if (packaging.mode === 'tray') return packaging.trays_per_box;
    return null;
}

interface PackingLineValues {
    mode: PackingMode;
    production_standard_packaging_id?: number | null;
    boxes?: number | null;
    loose_inner?: number | null;
    nos_per_box?: number | null;
    nos_per_inner?: number | null;
    actual_pieces?: number | null;
    override_reason?: string;
}

/**
 * What one line's counts SHOULD come to:
 *     boxes × pcs/box + loose inner containers × pcs/inner
 * The backend recomputes this identically and refuses a line that disagrees.
 */
function linePieces(line: PackingLineValues | undefined): number {
    if (!line) return 0;
    return (line.boxes ?? 0) * (line.nos_per_box ?? 0) + (line.loose_inner ?? 0) * (line.nos_per_inner ?? 0);
}

/** A fresh line for a mode, pre-loaded with that mode's imported pack sizes. */
function blankPackingLine(packaging: StandardPackaging): PackingLineValues {
    return {
        mode: packaging.mode,
        production_standard_packaging_id: packaging.id,
        boxes: null,
        loose_inner: null,
        nos_per_box: packaging.nos_per_box,
        nos_per_inner: innerPackSize(packaging),
        actual_pieces: null,
        override_reason: undefined,
    };
}

// Structural (sku+name) so both full Items and the day-bin aggregates'
// item-lite slices ({id, name, sku}) classify the same way.
const isMasterbatchItem = (item: Pick<Item, 'sku' | 'name'>): boolean => /master ?batch/i.test(`${item.sku} ${item.name}`);
// The whole raw-material family, not just the word "resin" — the live
// catalogue names its PET raw material without it (owner screenshot: an
// empty Resin picker on every completion).
const isResinItem = (item: Pick<Item, 'sku' | 'name'>): boolean =>
    /resin|granule|polymer|pet\s*(chip|raw)/i.test(`${item.sku} ${item.name}`);
const isClearColour = (colour: string | null | undefined): boolean => /^clear$/i.test((colour ?? '').trim());

/**
 * A product name reduced to what a human would call "the same product":
 * case and every separator dropped, so "200 ML ROUND", "200ml round" and
 * "200ML-ROUND" collapse together. The trailing "(LOCAL FIXTURE)" marker is
 * stripped first — it names the item's provenance, not the product.
 *
 * Deliberately NOT fuzzy. This is the whole basis on which Start Batch may
 * offer to swap the supervisor's chosen product for a different one, and a
 * near-miss there puts the wrong bottle on the machine for a whole shift.
 * Equal-after-normalising or no suggestion at all — nothing in between.
 */
function normaliseProductName(name: string | null | undefined): string {
    return (name ?? '')
        .replace(/\(LOCAL FIXTURE\)/gi, '')
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '');
}

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
    // Which colour is running. Optional in the schema and required in the
    // dialog only when the masters don't already fix one — most products
    // carry no colour, a few do, and asking a supervisor to re-state a
    // colour the master already knows is how a form starts getting
    // click-through answers.
    colour: z.string().min(1).nullish(),
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
    // Day-bin closing weight per material — what is left in the bin at the
    // end of the run. Without it, consumed kg (opening + loaded − closing
    // − returned) is unknowable and reports null.
    closing_day_bin: z
        .array(
            z.object({
                item_id: z.number(),
                quantity_kg: z.number().min(0, 'Cannot be negative').nullish(),
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
    // Downtime lines for THIS run — reason + from/to clock times + optional
    // note. All-empty lines are allowed here (an added-then-abandoned line
    // must not block completion) and dropped from the payload; a line that
    // says anything is forced complete in superRefine below.
    downtime_events: z
        .array(
            z.object({
                downtime_reason_id: z.number().nullish(),
                from_time: z.string().optional(),
                to_time: z.string().optional(),
                note: z.string().max(255, 'Max 255 characters').optional(),
            }),
        )
        .optional(),
    // One line per packaging mode actually used this run. Empty for products
    // with no imported standard — those complete through the plain tray/box
    // fields exactly as they did before packing lines existed.
    packing_lines: z
        .array(
            z.object({
                mode: z.enum(['pouch', 'tray', 'direct_box']),
                production_standard_packaging_id: z.number().nullish(),
                boxes: z.number().int().min(0, 'Cannot be negative').nullish(),
                loose_inner: z.number().int().min(0, 'Cannot be negative').nullish(),
                nos_per_box: z.number().int().min(1, 'At least 1').nullish(),
                nos_per_inner: z.number().int().min(1, 'At least 1').nullish(),
                actual_pieces: z.number().int().min(0, 'Cannot be negative').nullish(),
                override_reason: z.string().max(255, 'Max 255 characters').optional(),
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

    // A downtime line that says anything must say everything — reason and
    // both clock times — or its minutes are unknowable and it would be
    // silently dropped from the payload.
    (data.downtime_events ?? []).forEach((line, index) => {
        const touched =
            line.downtime_reason_id != null || !!line.from_time || !!line.to_time || (line.note ?? '').trim() !== '';
        if (!touched) return;
        if (line.downtime_reason_id == null) {
            ctx.addIssue({ code: 'custom', path: ['downtime_events', index, 'downtime_reason_id'], message: 'Pick the reason' });
        }
        if (!line.from_time) {
            ctx.addIssue({ code: 'custom', path: ['downtime_events', index, 'from_time'], message: 'From time' });
        }
        if (!line.to_time) {
            ctx.addIssue({ code: 'custom', path: ['downtime_events', index, 'to_time'], message: 'To time' });
        }
        // The backend refuses minutes <= 0 — surface it on the field instead.
        if (line.from_time && line.to_time && downtimeLineMinutes(line.from_time, line.to_time) === 0) {
            ctx.addIssue({
                code: 'custom',
                path: ['downtime_events', index, 'to_time'],
                message: 'To equals From — enter when it actually ended',
            });
        }
    });

    // Packing lines. Errors land on the offending FIELD, so the drawer stays
    // open with every entered value intact and the message says what to do —
    // a supervisor mid-count must never have to retype the shift.
    const seenModes = new Map<string, number>();
    (data.packing_lines ?? []).forEach((line, index) => {
        if (seenModes.has(line.mode)) {
            ctx.addIssue({
                code: 'custom',
                path: ['packing_lines', index, 'mode'],
                message: `This run already has a ${MODE_LABEL[line.mode].toLowerCase()} line. Put every carton of that kind on the one line — the same cartons counted twice would double the batch.`,
            });
        } else {
            seenModes.set(line.mode, index);
        }

        // Without a carton size no line total is computable — surfaced on
        // the field rather than as a bare backend rejection.
        if ((line.nos_per_box ?? 0) < 1) {
            ctx.addIssue({
                code: 'custom',
                path: ['packing_lines', index, 'nos_per_box'],
                message: 'Enter how many pieces go in one carton — this product standard does not say.',
            });
        }

        const derived = linePieces(line);
        const actual = line.actual_pieces ?? null;
        if (actual !== null && actual !== derived && (line.override_reason ?? '').trim() === '') {
            ctx.addIssue({
                code: 'custom',
                path: ['packing_lines', index, 'override_reason'],
                message: `Counted ${actual} but the pack sizes give ${derived}. Say why they differ (short box, part carton, miscount) or correct the count.`,
            });
        }
    });
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
    const [pendingStartBatchResume, setPendingStartBatchResume] = useState<StartBatchResumeDraft | null>(null);
    const pendingStartBatchResumeRef = useRef<StartBatchResumeDraft | null>(null);
    const processedStartBatchResumeQueryRef = useRef<string | null>(null);
    const [startProductionDateOverride, setStartProductionDateOverride] = useState<string | null>(null);
    const [startResumeNotice, setStartResumeNotice] = useState<StartBatchResumeOutcome | null>(null);
    const [completingEntry, setCompletingEntry] = useState<ShiftProductionEntry | null>(null);
    const [reportingDownMachine, setReportingDownMachine] = useState<WorkCenter | null>(null);
    const [closingDowntimeLog, setClosingDowntimeLog] = useState<MachineDowntimeLog | null>(null);
    const [startingMoldChangeMachine, setStartingMoldChangeMachine] = useState<WorkCenter | null>(null);
    const [finishingMoldChangeLog, setFinishingMoldChangeLog] = useState<MoldChangeLog | null>(null);
    const [powerInterruptionOpen, setPowerInterruptionOpen] = useState(false);
    const [stockCountOpen, setStockCountOpen] = useState(false);
    // Central "Load Material" — one scan point feeding the factory day bin
    // for every machine (the owner retired the per-machine Bin Bay page in
    // favour of this). Plain state, not a form: the driver is a barcode
    // scanner typing a code and sending Enter, not a keyboard user tabbing.
    const [loadMaterialOpen, setLoadMaterialOpen] = useState(false);
    const [loadBagBarcode, setLoadBagBarcode] = useState('');
    const [scannedLoadBag, setScannedLoadBag] = useState<MaterialBag | null>(null);
    const [loadBagKg, setLoadBagKg] = useState<number | null>(null);
    const [loadBagSupervisorId, setLoadBagSupervisorId] = useState<number | null>(null);
    const [loadBagSuccess, setLoadBagSuccess] = useState<string | null>(null);
    const [loadBagError, setLoadBagError] = useState<{ text: string; needsWarehouse: boolean } | null>(null);
    const loadBagInputRef = useRef<InputRef>(null);
    const currentUser = useAuthStore((s) => s.user);
    // Phase 6 traceability targets — only ever set from UI that itself only
    // renders when settings.traceability_enabled is true.
    const [dayBinTarget, setDayBinTarget] = useState<{ workCenter: WorkCenter; entry: ShiftProductionEntry } | null>(null);
    const [handoverEntry, setHandoverEntry] = useState<ShiftProductionEntry | null>(null);
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const resumeQuery = searchParams.toString();
    const resumeFlowRequested = useMemo(
        () => hasStartBatchResume(new URLSearchParams(resumeQuery), 'resume'),
        [resumeQuery],
    );
    const parsedStartBatchResume = useMemo(
        () => (resumeFlowRequested ? parseStartBatchResume(new URLSearchParams(resumeQuery)) : null),
        [resumeFlowRequested, resumeQuery],
    );

    const { data: shifts } = useQuery({ queryKey: ['production', 'shifts'], queryFn: listShifts });
    const { data: workCenters } = useQuery({ queryKey: ['production', 'work-centers', 'active'], queryFn: () => listWorkCenters(true) });
    // Shop-floor pickers need the WHOLE reference list, not the default first
    // 20 — with 642 items the type-to-search Select would otherwise only ever
    // see page 1 and most items would be unselectable. Distinct query keys so
    // this full-list fetch doesn't collide with the paginated list-page caches.
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses', 'all'], queryFn: listAllWarehouses });
    // The FACTORY DAY BIN — the central warehouse raw material sits in once it
    // leaves the store. Read here for two reasons: consumption lines default
    // to issuing FROM it (so completing a batch reduces it automatically, no
    // new maths), and the supervisor is shown its live balance beside the kg
    // they type. Not traceability-gated, and `warehouse: null` (nobody has
    // named it yet) simply means every field behaves as it did before.
    const { data: factoryDayBin } = useQuery({
        queryKey: ['production', 'factory-day-bin'],
        queryFn: getFactoryDayBin,
        // A login without production.view 403s — a normal answer, not an
        // error worth retrying or shouting about.
        retry: false,
        staleTime: 60 * 1000,
    });
    const { data: scrapReasons } = useQuery({ queryKey: ['production', 'scrap-reasons', 'all'], queryFn: listAllScrapReasons });
    // The GLOBAL downtime reason list — shared with Production Configuration
    // (same query key), so a reason saved from either screen appears in both.
    const { data: downtimeReasons } = useQuery({
        queryKey: ['production', 'downtime-reasons'],
        queryFn: () => listDowntimeReasons(),
    });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees', 'all'], queryFn: listAllEmployees });
    const { data: entries, isLoading: entriesLoading } = useQuery({
        queryKey: ['production', 'shift-production-entries'],
        queryFn: () => listShiftProductionEntries(),
        // Several people can act on any of the floor's machines ad hoc, no
        // fixed assignment — poll so one supervisor's screen reflects what
        // another just did. See PRODUCTION-SUPERVISOR-UX-PLAN.md §2.
        refetchInterval: 20000,
    });
    // Authoritative machine-running state — every in-progress batch across
    // all shifts/dates, unpaginated. Distinct from `entries` (a paginated,
    // today-scoped view for the completed list) so a batch left running from
    // a past shift can never leave a machine looking idle while Start Batch
    // is refused by the backend's global guard.
    const { data: activeBatches } = useQuery({
        queryKey: ['production', 'active-batches'],
        queryFn: listActiveBatches,
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
    // Which products the factory's standards actually cover. Two scalars per
    // row, so it costs almost nothing to hold — and without it the Start
    // Batch picker cannot tell a set-up product from a legacy master, which
    // is precisely how a supervisor ends up staring at a wall of missing
    // masters after choosing.
    const { data: standardCoverage } = useQuery({
        queryKey: ['production', 'standards', 'coverage'],
        queryFn: listStandardCoverage,
    });
    // Supervisor picker for the central Load Material modal, fetched only
    // while it is open. A floor login often has no user-admin rights, so
    // /users 403s — a normal answer, not an error: the picker quietly
    // collapses to just the logged-in user.
    const { data: loadBagUsers, isError: loadBagUsersUnavailable } = useQuery({
        queryKey: ['access', 'users', 'shift-floor'],
        queryFn: listUsers,
        retry: false,
        enabled: loadMaterialOpen,
    });
    // Active users only — a deactivated supervisor must not be creditable
    // with new loads. The logged-in user is always present (and preselected)
    // even when the users list didn't include them or didn't load at all.
    const loadBagSupervisorOptions = useMemo(() => {
        const listed = loadBagUsersUnavailable || !loadBagUsers ? [] : loadBagUsers.data.filter((u) => u.is_active);
        const options = listed.map((u) => ({
            value: u.id,
            label: u.id === currentUser?.id ? `${u.name} (you)` : u.name,
        }));
        if (currentUser && !listed.some((u) => u.id === currentUser.id)) {
            options.unshift({ value: currentUser.id, label: `${currentUser.name} (you)` });
        }
        return options;
    }, [loadBagUsers, loadBagUsersUnavailable, currentUser]);

    const shiftOptions = shifts?.data.filter((s) => s.is_active).map((s) => ({ value: s.id, label: s.name })) ?? [];
    // Inactive items (retired demo/legacy masters) must not be selectable —
    // Tally rejects vouchers for items it doesn't know.
    const itemOptions = items?.data.filter((i) => i.is_active).map((i) => ({ value: i.id, label: itemLabel(i) })) ?? [];
    // Which items the factory standards cover. Undefined coverage (still in
    // flight, or an older backend) is deliberately distinguished from empty
    // coverage below — see startItemOptions.
    const configuredItemIds = useMemo(
        () => (standardCoverage ? new Set(standardCoverage.data.map((row) => row.item_id)) : undefined),
        [standardCoverage],
    );
    // The PRODUCT picker for Start Batch, split into "set up" and "not set
    // up". Separate from itemOptions on purpose: that list is also the
    // resin/masterbatch/stock-count picker, and those choose MATERIALS,
    // which no production standard covers — grouping them by standards
    // coverage would file every resin under "Unconfigured".
    //
    // The leaf label stays "{sku} — {name}", so optionFilterProp="label"
    // search by natural product name keeps working inside the groups.
    const startItemOptions = useMemo(() => {
        const active = items?.data.filter((i) => i.is_active) ?? [];
        const toOption = (i: Item) => ({ value: i.id, label: itemLabel(i) });

        // Coverage not answered yet: show the flat list rather than filing
        // every product under "Unconfigured — setup required" for a beat.
        // A wrong answer that corrects itself a moment later is worse than
        // no answer: the supervisor may already have read it.
        if (!configuredItemIds) return active.map(toOption);

        const ready = active.filter((i) => configuredItemIds.has(i.id)).map(toOption);
        const unconfigured = active.filter((i) => !configuredItemIds.has(i.id)).map(toOption);

        return [
            // Production ready first — the common case must be what the
            // supervisor's eye lands on, and the legacy masters that caused
            // this whole problem must be somewhere they have to scroll to.
            ...(ready.length > 0 ? [{ label: 'Production ready', options: ready }] : []),
            ...(unconfigured.length > 0 ? [{ label: 'Unconfigured — setup required', options: unconfigured }] : []),
        ];
    }, [items, configuredItemIds]);
    // Focused pickers for the two fixed consumption rows — a supervisor
    // filling "Resin (kg)" should only ever see resins, not all 642 items.
    const resinMatches = items?.data.filter((i) => i.is_active && isResinItem(i)) ?? [];
    // When the family matcher finds NOTHING on the live catalogue, fall back
    // to every active item (still searchable) — a scoped-but-empty dropdown
    // is a dead end that blocks the whole completion.
    const resinOptions =
        resinMatches.length > 0 ? resinMatches.map((i) => ({ value: i.id, label: itemLabel(i) })) : itemOptions;
    const mbOptions =
        items?.data.filter((i) => i.is_active && isMasterbatchItem(i)).map((i) => ({ value: i.id, label: itemLabel(i) })) ?? [];
    const moldOptions =
        molds?.data.filter((m) => m.status === 'active').map((m) => ({ value: m.id, label: `${m.code} — ${m.name}` })) ?? [];
    // "Changed From" is a historical record of what just came out, not a
    // pick of something to install — it can be any mold regardless of
    // current status (it may have gone straight to "under repair").
    const allMoldOptions = molds?.data.map((m) => ({ value: m.id, label: `${m.code} — ${m.name}` })) ?? [];
    const warehouseOptions =
        warehouses?.data
            .filter((warehouse) => warehouse.is_active)
            .map((warehouse) => ({ value: warehouse.id, label: `${warehouse.code} — ${warehouse.name}` })) ?? [];
    const scrapReasonOptions = scrapReasons?.data.map((r) => ({ value: r.id, label: `${r.code} — ${r.name}` })) ?? [];
    const downtimeReasonOptions =
        downtimeReasons?.data.filter((r) => r.is_active).map((r) => ({ value: r.id, label: r.description })) ?? [];
    const employeeOptions =
        employees?.data
            .filter((employee) => employee.status === 'active')
            .map((employee) => ({ value: employee.id, label: `${employee.employee_code} — ${employee.name}` })) ?? [];

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
    // A Configure Recipe round-trip may cross a shift/date boundary. Preserve
    // the date the supervisor originally reviewed instead of silently filing
    // the batch under whatever the wall clock says when they return.
    const startProductionDate = startProductionDateOverride ?? today;
    // The clock's ACTUAL current context (not the shift the user is viewing) —
    // a running batch outside it is a carryover to flag, independent of which
    // shift tab is selected.
    const clockProductionDate = productionDateFor(detectedShift);

    // Last-touched-by-someone-else state for every machine, derived from the
    // shared entry list rather than a per-machine assignment — nobody owns a
    // fixed subset of the floor here (UX doc §2).
    const runningByMachine = useMemo(() => {
        const map = new Map<number, ShiftProductionEntry>();
        // Global, NOT filtered to today/current shift: the backend refuses a
        // second batch on a machine that holds ANY in-progress one, so the
        // card must reflect that same global reality (carryover batches from
        // an earlier shift/date included) or the machine reads idle yet won't
        // start. The list is unpaginated, so nothing can fall past a page.
        for (const entry of activeBatches?.data ?? []) {
            if (entry.batch_status !== 'in_progress') continue;
            const existing = map.get(entry.work_center.id);
            if (!existing || entry.id > existing.id) map.set(entry.work_center.id, entry);
        }
        return map;
    }, [activeBatches]);

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
        queryClient.invalidateQueries({ queryKey: ['production', 'active-batches'] });
        queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
        // A completed batch issued material out of the day bin — the balance
        // shown beside the consumption rows must fall, not go stale.
        queryClient.invalidateQueries({ queryKey: ['production', 'factory-day-bin'] });
    };
    const invalidateDowntime = () => queryClient.invalidateQueries({ queryKey: ['production', 'machine-downtime-logs'] });
    const invalidateMoldChange = () => queryClient.invalidateQueries({ queryKey: ['production', 'mold-change-logs'] });

    const settings = useProductionSettings();
    // Phase 6 master switch: anything traceability-related renders/fetches ONLY
    // when the backend says so — with the flag off (or an older backend that
    // doesn't send the field) this page is byte-for-byte today's UI.
    // Declared here rather than beside the Complete Batch form because Start
    // Batch's bin-bay read is gated on it too, and the bin-bay routes 404
    // with the flag off.
    const traceabilityEnabled = settings?.traceability_enabled === true;

    const startForm = useForm<StartBatchFormValues>({ resolver: zodResolver(startBatchSchema) });
    // The picked item's master record — drives the read-only "Product
    // standards" summary and the Active Cavities prefill in Start Batch.
    const startItemId = startForm.watch('item_id');
    const startItem = useMemo(() => items?.data.find((i) => i.id === startItemId), [items, startItemId]);
    // The chosen product has no factory standard at all. Only ever true once
    // coverage has actually answered — an unanswered read must not accuse a
    // product of being unconfigured.
    const startItemUnconfigured = !!startItemId && !!configuredItemIds && !configuredItemIds.has(startItemId);
    /**
     * A configured product carrying the SAME name as the unconfigured one the
     * supervisor just picked — the legacy-master case this whole panel exists
     * for, where the factory does have standards, filed under a different
     * item row.
     *
     * Offered only on an exact match after normalisation, and only when that
     * match is unambiguous: two configured products sharing a normalised name
     * means nobody can say which one is meant, so nothing is suggested. A
     * suggestion here changes what physically runs, so silence is the correct
     * answer to any doubt.
     */
    const replacementSuggestion = useMemo(() => {
        if (!startItemUnconfigured || !startItem || !standardCoverage) return undefined;
        const target = normaliseProductName(startItem.name);
        if (target === '') return undefined;

        const matchedIds = new Set(
            standardCoverage.data
                .filter((row) => row.item_id !== startItem.id && normaliseProductName(row.source_product_name) === target)
                .map((row) => row.item_id),
        );
        // Ambiguous (or nothing) — say nothing.
        if (matchedIds.size !== 1) return undefined;

        const [matchedId] = [...matchedIds];
        return items?.data.find((i) => i.id === matchedId && i.is_active);
    }, [startItemUnconfigured, startItem, standardCoverage, items]);
    /**
     * The colours the catalogue actually knows about, derived from the item
     * masters rather than hardcoded — the factory adds a colour by giving an
     * item one, and this list follows without a deploy.
     */
    const colourOptions = useMemo(() => {
        const seen = new Map<string, string>();
        for (const item of items?.data ?? []) {
            const colour = (item.colour ?? '').trim();
            if (colour !== '' && !seen.has(colour.toLowerCase())) seen.set(colour.toLowerCase(), colour);
        }
        return [...seen.values()].sort((a, b) => a.localeCompare(b)).map((c) => ({ value: c, label: c }));
    }, [items]);
    // Whether the masters already fix this run's colour. When they don't, the
    // supervisor must say — the factory workbook has no reliable colour
    // column, and colour picks the masterbatch and the amber/clear scrap item
    // downstream. Never defaulted: a wrong colour nobody chose is worse than
    // a question nobody likes being asked.
    const startColourFixed = (startItem?.colour ?? '').trim() !== '';
    const startColourRequired = !!startItemId && !startColourFixed;
    // Active cavities is a per-item value: every item change re-prefills it
    // with that item's standard (an earlier edit belonged to the old item).
    // Items without a standard leave it blank — fully manual, as before.
    useEffect(() => {
        if (!startingMachine) return;
        // During a Configure Recipe round-trip the supervisor's draft is the
        // source of truth. The normal item-default effect must not overwrite
        // the cavities/colour they already reviewed.
        if (pendingStartBatchResumeRef.current?.item_id === startItem?.id) return;
        startForm.setValue('active_cavities', startItem?.standard_cavities ?? undefined);
        // Colour is per-item too. Cleared on every product change so a colour
        // chosen for the last product can never ride along onto this one —
        // and left cleared, never pre-filled with a guess.
        startForm.setValue('colour', undefined);
    }, [startItem, startingMachine, startForm]);
    // Readiness + estimation for the run being set up. Fetched from the
    // backend rather than recomputed here: the gate that REFUSES the start
    // is server-side, so the screen must show that same verdict, not a
    // second opinion that could disagree with it.
    // Variant/packaging selection. Reset whenever the product changes — a
    // choice made for one product is meaningless for the next.
    const [selectedStandardId, setSelectedStandardId] = useState<number | undefined>();
    const [selectedPackagingId, setSelectedPackagingId] = useState<number | undefined>();
    useEffect(() => {
        if (pendingStartBatchResumeRef.current?.item_id === startItemId) return;
        setSelectedStandardId(undefined);
        setSelectedPackagingId(undefined);
    }, [startItemId]);

    const startWarehouseId = startForm.watch('warehouse_id');
    const startOperatorId = startForm.watch('operator_id');
    const startActiveCavities = startForm.watch('active_cavities');
    const startColour = startForm.watch('colour');
    const { data: batchPreview, isFetching: previewLoading } = useQuery({
        queryKey: [
            'production',
            'batch-preview',
            startItemId,
            startingMachine?.id,
            startWarehouseId,
            effectiveShiftId,
            startActiveCavities,
            selectedStandardId,
            selectedPackagingId,
        ],
        queryFn: () =>
            getBatchPreview({
                item_id: startItemId!,
                work_center_id: startingMachine?.id,
                warehouse_id: startWarehouseId,
                shift_id: effectiveShiftId ?? undefined,
                active_cavities: startActiveCavities ?? undefined,
                production_standard_id: selectedStandardId,
                production_standard_packaging_id: selectedPackagingId,
            }),
        enabled: startingMachine !== null && !!startItemId,
    });

    // A single standard is intentionally not shown as a choice, but it is
    // still the standard behind any packaging option and must travel through
    // Configure Recipe and into Start Batch. Without this resolved id, a
    // pouch choice would be detached from the only standard it belongs to.
    const resolvedStartStandardId =
        selectedStandardId
        ?? (batchPreview?.variants?.length === 1 ? batchPreview.variants[0].id : undefined);
    const startBatchRecipeDraft = useMemo<StartBatchResumeDraft | null>(() => {
        if (!startingMachine || !effectiveShiftId || !startItemId || !startWarehouseId) return null;
        return {
            machine_id: startingMachine.id,
            shift_id: effectiveShiftId,
            production_date: startProductionDate,
            item_id: startItemId,
            warehouse_id: startWarehouseId,
            operator_id: startOperatorId,
            active_cavities: startActiveCavities ?? undefined,
            standard_id: resolvedStartStandardId,
            packaging_id: selectedPackagingId,
            colour: startColour ?? undefined,
        };
    }, [
        effectiveShiftId,
        selectedPackagingId,
        resolvedStartStandardId,
        startActiveCavities,
        startColour,
        startItemId,
        startOperatorId,
        startProductionDate,
        startWarehouseId,
        startingMachine,
    ]);

    // Imported factory standards live on production_standards, while the
    // legacy item-master cavity may be empty. Once the server resolves the
    // exact standard for this run, use that cavity as the editable default.
    // Primitive dependencies keep a supervisor's later manual edit intact;
    // the effect reruns only when the product/standard itself changes.
    const resolvedStartCavities =
        batchPreview?.standard?.cavities ?? startItem?.standard_cavities ?? undefined;
    useEffect(() => {
        if (!startingMachine || !startItemId || resolvedStartCavities === undefined) return;
        if (pendingStartBatchResumeRef.current?.item_id === startItemId) return;
        if (selectedStandardId && batchPreview?.standard?.id !== selectedStandardId) return;

        startForm.setValue('active_cavities', resolvedStartCavities);
    }, [
        batchPreview?.standard?.id,
        resolvedStartCavities,
        selectedStandardId,
        startForm,
        startItemId,
        startingMachine,
    ]);

    // Restore a Start Batch draft after the supervisor creates/cancels a BOM.
    // Query parameters are only a transport: every id is checked against the
    // freshly loaded reference data, then consumed with replace so refresh or
    // Back cannot reopen the modal forever.
    useEffect(() => {
        if (!resumeFlowRequested) {
            processedStartBatchResumeQueryRef.current = null;
            return;
        }
        if (!workCenters || !items || !warehouses || !employees || !shifts || !activeBatches) return;
        if (processedStartBatchResumeQueryRef.current === resumeQuery) return;
        processedStartBatchResumeQueryRef.current = resumeQuery;

        if (
            !parsedStartBatchResume
            || parsedStartBatchResume.phase !== 'resume'
            || !parsedStartBatchResume.outcome
        ) {
            setSearchParams({}, { replace: true });
            Modal.error({
                title: 'Could not restore Start Batch',
                content: 'The saved setup link is incomplete or invalid. Open the machine and review the batch again.',
            });
            return;
        }

        const { draft, outcome } = parsedStartBatchResume;
        const machine = workCenters.data.find((candidate) => candidate.id === draft.machine_id && candidate.is_active);
        const shift = shifts.data.find((candidate) => candidate.id === draft.shift_id && candidate.is_active);
        const item = items.data.find((candidate) => candidate.id === draft.item_id && candidate.is_active);
        const warehouse = warehouses.data.find(
            (candidate) => candidate.id === draft.warehouse_id && candidate.is_active,
        );
        const operatorExists =
            draft.operator_id === undefined
            || employees.data.some(
                (candidate) => candidate.id === draft.operator_id && candidate.status === 'active',
            );
        const machineRunning = activeBatches.data.some(
            (entry) => entry.batch_status === 'in_progress' && entry.work_center.id === draft.machine_id,
        );

        const invalidReason =
            !machine
                ? 'The selected machine is no longer active.'
                : machineRunning
                    ? 'Another batch is now running on this machine.'
                    : !shift
                        ? 'The selected shift is no longer active.'
                        : !item
                            ? 'The selected product is no longer active.'
                            : !warehouse
                                ? 'The selected finished-goods warehouse no longer exists.'
                                : !operatorExists
                                    ? 'The selected operator is no longer available.'
                                    : null;

        setSearchParams({}, { replace: true });
        if (invalidReason || !machine) {
            Modal.error({
                title: 'Start Batch was not reopened',
                content: `${invalidReason ?? 'The saved setup is no longer valid'} Review the current floor state and start again.`,
            });
            return;
        }

        // A newly created recipe changes readiness and material estimation.
        // Refetch those facts; never carry a preview through the side trip.
        if (outcome === 'created') {
            queryClient.invalidateQueries({ queryKey: ['production', 'batch-preview'] });
            // Availability is recipe-dependent. Remove, rather than merely
            // invalidate, so a cached old recipe cannot keep Start enabled
            // while the new component/shortage calculation is in flight.
            queryClient.removeQueries({ queryKey: ['production', 'bin-bay', 'availability'] });
        }

        pendingStartBatchResumeRef.current = draft;
        setPendingStartBatchResume(draft);
        setStartProductionDateOverride(draft.production_date);
        setStartResumeNotice(outcome);
        setSelectedShiftId(draft.shift_id);
        setSelectedStandardId(undefined);
        setSelectedPackagingId(undefined);
        setStartingMachine(machine);
        startForm.reset({
            item_id: draft.item_id,
            warehouse_id: draft.warehouse_id,
            operator_id: draft.operator_id,
            active_cavities: draft.active_cavities,
            colour: draft.colour,
        });
        if (draft.active_cavities !== undefined) {
            startForm.setValue('active_cavities', draft.active_cavities, { shouldDirty: true });
        }
        if (draft.colour !== undefined) {
            startForm.setValue('colour', draft.colour, { shouldDirty: true });
        }
    }, [
        activeBatches,
        employees,
        items,
        parsedStartBatchResume,
        queryClient,
        resumeQuery,
        resumeFlowRequested,
        setSearchParams,
        shifts,
        startForm,
        warehouses,
        workCenters,
    ]);

    // The variant/package ids are validated against a fresh base preview for
    // the restored product. A stale or cross-product id is dropped rather
    // than being attached to the wrong run.
    useEffect(() => {
        if (!pendingStartBatchResume || !batchPreview) return;

        let selectionWarning: string | null = null;
        const restoredStandard = pendingStartBatchResume.standard_id
            ? batchPreview.variants.find((variant) => variant.id === pendingStartBatchResume.standard_id)
            : undefined;

        if (pendingStartBatchResume.standard_id && !restoredStandard) {
            selectionWarning = 'The previously selected production standard is no longer available; select it again.';
        } else {
            setSelectedStandardId(restoredStandard?.id);
        }

        if (pendingStartBatchResume.packaging_id) {
            const restoredPackaging = restoredStandard?.packagings.find(
                (packaging) => packaging.id === pendingStartBatchResume.packaging_id,
            );
            if (restoredPackaging) {
                setSelectedPackagingId(restoredPackaging.id);
            } else {
                selectionWarning =
                    'The previously selected packaging option is no longer available; select it again.';
            }
        }

        setPendingStartBatchResume(null);
        pendingStartBatchResumeRef.current = null;
        if (selectionWarning) {
            Modal.warning({ title: 'Production setup changed', content: selectionWarning });
        }
    }, [batchPreview, pendingStartBatchResume]);

    // ---------------------------------------------------------------------
    // Material availability, read from the CENTRAL bin bay.
    //
    // Read-only here on purpose. Material is scanned into a machine's bin
    // ONCE, at the bay, on the Bin Bay page — this dialog only reports what
    // is already in there against what the recipe needs, and never opens a
    // load form. Asking the same question a second time is how the bin and
    // the batch end up disagreeing.
    //
    // The gate this drives fails OPEN, unlike the readiness gate above: a
    // bay mid-load, a flag-off instance (these routes 404), a product with
    // no recipe, or a piece count the estimator could not produce are all
    // ordinary, and none of them may stop a machine the floor can run. No
    // data therefore means NO shortage, never an assumed one — the backend
    // records the override rather than refusing the start, so the worst a
    // missing read can cost is an unrecorded reason, not lost production.
    // ---------------------------------------------------------------------
    const startExpectedPieces = batchPreview?.estimation.expected_pieces ?? null;
    const { data: binAvailability, isFetching: binAvailabilityLoading } = useQuery({
        queryKey: ['production', 'bin-bay', 'availability', startingMachine?.id, startItemId, startExpectedPieces],
        queryFn: () =>
            getBinBayAvailability({
                work_center_id: startingMachine!.id,
                // The PRODUCT about to run, paired with its piece count —
                // the endpoint requires both together, never one alone.
                product_item_id: startItemId!,
                expected_pieces: startExpectedPieces!,
            }),
        enabled:
            traceabilityEnabled && startingMachine !== null && !!startItemId && startExpectedPieces !== null,
    });

    // Only mass components live in the bin. A Nos consumable (caps, labels)
    // is not bin-tracked, so its shortage_quantity is null by design and it
    // must never appear as short — a false shortage on every single run is
    // how a real one stops being read.
    const startMassComponents = useMemo<BinBayRequirementComponent[]>(
        () => (binAvailability?.requirement?.components ?? []).filter((c) => c.is_mass),
        [binAvailability],
    );

    // One availability read per mass component, this time by MATERIAL, to
    // pull the lot layers behind the balance. The product-level call above
    // returns `bin: null` (it names no item_id), so without these the card
    // could only quote a number with nothing behind it.
    const startBinLayerQueries = useQueries({
        queries: startMassComponents.map((component) => ({
            queryKey: ['production', 'bin-bay', 'availability', startingMachine?.id, 'material', component.item_id],
            queryFn: () =>
                getBinBayAvailability({ work_center_id: startingMachine!.id, item_id: component.item_id }),
            enabled: traceabilityEnabled && startingMachine !== null,
        })),
    });
    const startBinByItemId = useMemo(() => {
        const map = new Map<number, BinBayAvailability>();
        startMassComponents.forEach((component, index) => {
            const bin = startBinLayerQueries[index]?.data?.bin;
            if (bin) map.set(component.item_id, bin);
        });
        return map;
    }, [startMassComponents, startBinLayerQueries]);

    const startShortComponents = useMemo(
        () => startMassComponents.filter((c) => c.shortage_quantity !== null && (toNum(c.shortage_quantity) ?? 0) > 0),
        [startMassComponents],
    );
    const startHasShortage = startShortComponents.length > 0;

    // The supervisor's explicit "start anyway" — deliberately useState and
    // NOT part of startBatchSchema: the mutation spreads the form values
    // straight into the request body, so a UI-only tick-box added there
    // would ride along to an API that never asked for it.
    const [startAnyway, setStartAnyway] = useState(false);
    const [shortageReason, setShortageReason] = useState('');
    const shortageReasonOk = shortageReason.trim().length >= 5;
    // Reset on machine/product change ONLY. Never on the availability data:
    // a background refetch while the supervisor is mid-sentence would wipe
    // what they had typed.
    useEffect(() => {
        setStartAnyway(false);
        setShortageReason('');
    }, [startingMachine, startItemId]);

    const startMutation = useMutation({
        mutationFn: (values: StartBatchFormValues) => {
            if (!startingMachine || !effectiveShiftId) throw new Error('Missing machine or shift');
            const { active_cavities, colour, ...rest } = values;
            // production_date sent explicitly (shift-aware): a batch started at
            // 02:00 on the Night shift files under the shift's START date.
            return startBatch({
                ...rest,
                // null (cleared InputNumber) → omitted; backend then defaults
                // active cavities to the item's standard.
                active_cavities: active_cavities ?? undefined,
                // Sent only when the supervisor was actually asked. When the
                // masters already fix the colour, the backend resolves it
                // from them — echoing it back from the screen would let a
                // stale render overwrite the master.
                colour: startColourRequired ? (colour ?? undefined) : undefined,
                work_center_id: startingMachine.id,
                shift_id: effectiveShiftId,
                production_date: startProductionDate,
                production_standard_id: resolvedStartStandardId,
                production_standard_packaging_id: selectedPackagingId,
                // Only when the shortage was real AND explicitly waved
                // through — never a stale reason from a shortage that has
                // since been loaded away.
                material_shortage_override_reason:
                    startHasShortage && startAnyway && shortageReasonOk ? shortageReason.trim() : undefined,
            });
        },
        onSuccess: () => {
            invalidate();
            setStartingMachine(null);
            setStartProductionDateOverride(null);
            setStartResumeNotice(null);
            setPendingStartBatchResume(null);
            pendingStartBatchResumeRef.current = null;
            startForm.reset();
            setStartAnyway(false);
            setShortageReason('');
            // Loading material is deliberately NOT part of Start Batch. Bags
            // are scanned into the bins once, for the whole bay, on the PET
            // Resin Bag Loading page — a per-batch material form here asked the
            // same question a second time and let the two disagree.
            //
            // The "Materials" button on the running card stays, and it is now
            // genuinely what this comment always claimed: the balance plus
            // returns, with no load control. It kept a Load mode until the
            // duplicate was removed from DayBinDrawer, so this note described
            // an intention rather than the code — worth stating, because the
            // next person to read it will rely on it.
        },
        onError: (error: any) => {
            const body = error?.response?.data;
            // machine_busy carries the batch that is actually running — the
            // usual cause is one the supervisor cannot see (previous shift,
            // someone else's start), so name it instead of saying "refresh".
            if (body?.code === 'machine_busy' && body?.active_batch) {
                const running = body.active_batch;
                Modal.info({
                    title: 'This machine is already running',
                    content: (
                        <>
                            <Typography.Paragraph style={{ marginBottom: 8 }}>{body.message}</Typography.Paragraph>
                            <Typography.Text type="secondary">
                                Batch {running.batch_number} · {running.item ?? '—'} · {running.shift ?? '—'} ·{' '}
                                {String(running.production_date ?? '').slice(0, 10)}
                            </Typography.Text>
                        </>
                    ),
                    onOk: () => {
                        invalidate();
                        setStartingMachine(null);
                    },
                });
                return;
            }
            Modal.error({
                title: 'Could not start batch',
                content: body?.message ?? 'Someone may have just started this machine — refresh and try again.',
            });
        },
    });

    const completeForm = useForm<CompleteBatchFormValues>({
        resolver: zodResolver(completeBatchSchema),
        defaultValues: { material_consumptions: [], scraps: [], downtime_events: [] },
    });
    const materialFields = useFieldArray({ control: completeForm.control, name: 'material_consumptions' });
    const scrapFields = useFieldArray({ control: completeForm.control, name: 'scraps' });
    const packingFields = useFieldArray({ control: completeForm.control, name: 'packing_lines' });
    const downtimeFields = useFieldArray({ control: completeForm.control, name: 'downtime_events' });
    // Bumped whenever a packing figure changes, purely to force the derived
    // read-outs below to re-render. Deliberately NOT a watch of
    // 'packing_lines': react-hook-form hands back the same array reference
    // after a nested write (see applyDayBinConsumption), so anything keyed on
    // that identity is stale by construction.
    const [packingRevision, setPackingRevision] = useState(0);
    const quantityProduced = completeForm.watch('quantity_produced');
    const quantityScrap = completeForm.watch('quantity_scrap');
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
    const resinItemIdWatch = completeForm.watch('resin_item_id');
    const mbItemIdWatch = completeForm.watch('mb_item_id');
    const downtimeEventsWatch = completeForm.watch('downtime_events');

    // The plain "Lumps (kg)" field beside the rejection figures and the scrap
    // line list are ONE entry path: the field reads and writes the single
    // scraps line of type 'lumps' — created, updated and removed here — so
    // however the figure is entered it exists exactly once.
    const lumpsLineIndex = (scrapsWatch ?? []).findIndex((s) => s?.type === 'lumps');
    const setLumpsKgValue = (value: number | null) => {
        const lines = completeForm.getValues('scraps') ?? [];
        const index = lines.findIndex((s) => s?.type === 'lumps');
        if (index === -1) {
            if (value === null) return;
            scrapFields.append({ type: 'lumps', quantity_nos: undefined, quantity_kg: value, scrap_reason_id: undefined });
            return;
        }
        const line = lines[index];
        if (value === null && !line.quantity_nos && !line.scrap_reason_id) {
            scrapFields.remove(index);
            return;
        }
        // update() rather than setValue on the nested path, so the scraps
        // array gets a NEW identity and everything watching it recomputes —
        // see applyDayBinConsumption for why nested setValue would not.
        scrapFields.update(index, { ...line, quantity_kg: value ?? undefined });
    };

    // Manual-edit latches for the resin auto-calculation: a supervisor-typed
    // figure, or a real day-bin weighment, takes the field over permanently
    // for the batch (both reset when the drawer opens for the next one).
    const resinKgTouchedRef = useRef(false);
    const resinKgWeighedRef = useRef(false);

    // ---- The factory day bin, on the completion form -----------------------
    // Every consumption line already carries its own warehouse, so issuing a
    // line FROM the day-bin warehouse is what makes the bin fall when a batch
    // completes. Nothing below changes a kg the supervisor types or any
    // formula — it only picks the default location and shows the balance.
    const dayBinWarehouseId = factoryDayBin?.warehouse?.id ?? null;
    const dayBinBalances = useMemo(() => {
        const balances = new Map<number, number>();
        for (const row of factoryDayBin?.materials ?? []) {
            const parsed = parseFloat(row.quantity_kg);
            if (!Number.isNaN(parsed)) balances.set(row.item_id, parsed);
        }
        return balances;
    }, [factoryDayBin]);
    /** What the factory day bin holds of a material; null = nothing tracked there. */
    const dayBinKgFor = useCallback(
        (itemId: number | null | undefined): number | null =>
            itemId === null || itemId === undefined ? null : dayBinBalances.get(itemId) ?? null,
        [dayBinBalances],
    );

    // Default every consumption line's warehouse to the day bin — but only
    // for a material the bin actually HOLDS. Defaulting to an empty bin would
    // turn Complete Batch into an insufficient-stock refusal, and a blocked
    // completion is never an acceptable price for a tidier default: those
    // lines stay exactly as they are today (blank, supervisor picks).
    // Never overwrites a value already there, so a manual pick stands.
    useEffect(() => {
        if (dayBinWarehouseId === null || !completingEntry) return;

        const applyDefault = (
            field: 'resin_warehouse_id' | 'mb_warehouse_id' | `material_consumptions.${number}.warehouse_id`,
            itemId: number | null | undefined,
        ) => {
            const held = dayBinKgFor(itemId);
            if (held === null || held <= 0) return;
            if (completeForm.getValues(field) != null) return;
            completeForm.setValue(field, dayBinWarehouseId);
        };

        applyDefault('resin_warehouse_id', resinItemIdWatch);
        applyDefault('mb_warehouse_id', mbItemIdWatch);
        (consumptionsWatch ?? []).forEach((line, index) => {
            applyDefault(`material_consumptions.${index}.warehouse_id`, line?.item_id);
        });
    }, [dayBinWarehouseId, dayBinKgFor, completingEntry, resinItemIdWatch, mbItemIdWatch, consumptionsWatch, completeForm]);

    // With exactly ONE candidate in a fixed-row picker there is nothing to
    // choose — pre-pick it, only while the field is untouched and empty
    // (same contract as every other prefill in this drawer). Clear products
    // never get a masterbatch pick: no masterbatch goes into Clear.
    const soleResinItemId = resinOptions.length === 1 ? resinOptions[0].value : null;
    const soleMbItemId = mbOptions.length === 1 ? mbOptions[0].value : null;
    useEffect(() => {
        if (!completingEntry) return;
        const applySole = (field: 'resin_item_id' | 'mb_item_id', id: number | null) => {
            if (id === null) return;
            if (completeForm.getFieldState(field).isDirty) return;
            if (completeForm.getValues(field) != null) return;
            completeForm.setValue(field, id);
        };
        applySole('resin_item_id', soleResinItemId);
        if (!isClearColour(completingEntry.item.colour)) applySole('mb_item_id', soleMbItemId);
    }, [completingEntry, soleResinItemId, soleMbItemId, completeForm]);

    /**
     * "Day bin: 1250.5 Kg" beside a consumption row, so the supervisor watches
     * the balance fall as batches complete — plus a plain warning when they
     * are about to issue more than the bin holds (the backend would refuse
     * it). Never changes the typed figure.
     */
    const dayBinHint = (itemId: number | null | undefined, typedKg: number | null | undefined, warehouseId: number | null | undefined): ReactNode => {
        if (dayBinWarehouseId === null || itemId === null || itemId === undefined) return null;
        const held = dayBinKgFor(itemId);
        if (held === null) return null;
        const issuingFromBin = warehouseId === dayBinWarehouseId;
        const short = issuingFromBin && typedKg != null && typedKg > held;

        return (
            <Typography.Text type={short ? 'danger' : 'secondary'} style={{ fontSize: 12 }}>
                Day bin: {fmtNum(held, 4)}
                {short && ' — more than the day bin holds; load it or pick another location'}
            </Typography.Text>
        );
    };

    // Packing auto-fill from the item's packing master (nos_per_tray /
    // nos_per_box). Auto-writes never mark the field dirty, so the dirty flag
    // is exactly "the user touched this" — dirty fields are never overwritten.
    // Items without standards never enter this path — the form stays fully
    // manual, exactly as before the packing master existed.
    useEffect(() => {
        if (!completingEntry || !quantityProduced || quantityProduced <= 0) return;
        // Superseded by the packing lines whenever the product's standard
        // declares its modes — those own the counts and derive the total the
        // other way round.
        if (packingModes.length > 0) return;
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
        // Same hand-off as above: with packing lines in play the total comes
        // from the lines, not from a single box count.
        if (packingModes.length > 0) return;
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

    // ------------------------------------------------------------------
    // Packing lines: which modes this batch's standard actually offers, and
    // the totals they add up to.
    // ------------------------------------------------------------------

    // The entry Resource sends production_standard_id; the shared TS type
    // does not declare it yet, so read it narrowly rather than widening a
    // type this page does not own.
    const completingStandardId =
        (completingEntry as unknown as { production_standard_id?: number | null } | null)?.production_standard_id ?? null;
    const completingPackagingMode =
        (completingEntry as unknown as { packaging_mode?: string | null } | null)?.packaging_mode ?? null;

    // Reuses the Start Batch preview endpoint (read-only GET) — the standard's
    // packaging rows are the only place the real modes and pack sizes live.
    const { data: completePreview } = useQuery({
        queryKey: ['production', 'batch-preview', 'complete', completingEntry?.id, completingStandardId],
        queryFn: () =>
            getBatchPreview({
                item_id: completingEntry!.item.id,
                work_center_id: completingEntry!.work_center.id,
                production_standard_id: completingStandardId ?? undefined,
            }),
        enabled: completingEntry !== null,
    });

    const packingModes = useMemo<StandardPackaging[]>(() => {
        const variants = completePreview?.variants ?? [];
        if (variants.length === 0) return [];
        // The variant this batch actually started against; with only one
        // variant there was never a choice to record.
        const chosen =
            (completingStandardId !== null ? variants.find((v) => v.id === completingStandardId) : undefined) ??
            (variants.length === 1 ? variants[0] : undefined);
        return chosen?.packagings ?? [];
    }, [completePreview, completingStandardId]);

    const packagingForLine = useCallback(
        (line: PackingLineValues): StandardPackaging | undefined =>
            packingModes.find((p) => p.id === line.production_standard_packaging_id) ??
            packingModes.find((p) => p.mode === line.mode),
        [packingModes],
    );

    /**
     * Totals across the lines, written back into the fields the rest of the
     * drawer (and the API) already speak: quantity produced, cartons, and the
     * tray/pouch counts. Called from every packing input's onChange rather
     * than from an effect, for the same react-hook-form identity reason as
     * the day-bin prefill.
     *
     * Cartons are summed ONCE across modes — a carton belongs to exactly one
     * mode, and the backend refuses a batch whose lines don't add up to the
     * carton total, so the same boxes can never be counted twice.
     */
    const recomputePackingTotals = useCallback(() => {
        const lines = (completeForm.getValues('packing_lines') ?? []) as PackingLineValues[];
        setPackingRevision((r) => r + 1);
        if (lines.length === 0) return;

        let boxes = 0;
        let pieces = 0;
        let trays = 0;
        let pouches = 0;

        for (const line of lines) {
            const derived = linePieces(line);
            // The counted figure rules; it simply defaults to the derived one
            // until the supervisor types over it.
            pieces += line.actual_pieces ?? derived;
            boxes += line.boxes ?? 0;

            const packaging = packagingForLine(line);
            const perBox = packaging ? innersPerBox(packaging) : null;
            const inners = (line.boxes ?? 0) * (perBox ?? 0) + (line.loose_inner ?? 0);
            if (line.mode === 'tray') trays += inners;
            if (line.mode === 'pouch') pouches += inners;
        }

        completeForm.setValue('no_of_box', boxes);
        completeForm.setValue('quantity_produced', pieces + (completeForm.getValues('loose_pieces') ?? 0));
        completeForm.setValue('no_of_trays', trays > 0 ? trays : null);
        completeForm.setValue('no_of_pouches', pouches > 0 ? pouches : null);
        // Only meaningful with a single mode — two modes have two different
        // pieces-per-carton, and no single value would be true.
        completeForm.setValue('nos_per_box', lines.length === 1 ? (lines[0].nos_per_box ?? null) : null);
    }, [completeForm, packagingForLine]);

    // Seed the first line. One mode means no question is asked; several means
    // start on the one the batch was started against (or the standard's
    // default) and let the supervisor add the other if the run used both.
    useEffect(() => {
        if (!completingEntry || packingModes.length === 0) return;
        if (((completeForm.getValues('packing_lines') ?? []) as PackingLineValues[]).length > 0) return;
        const initial =
            packingModes.length === 1
                ? packingModes[0]
                : (packingModes.find((p) => p.mode === completingPackagingMode) ??
                   packingModes.find((p) => p.is_default) ??
                   packingModes[0]);
        packingFields.replace([blankPackingLine(initial)]);
        recomputePackingTotals();
        // Deliberately keyed on the modes and the batch only: packingFields
        // and recomputePackingTotals are rebuilt every render, and listing
        // them would re-seed the line on every keystroke.
    }, [packingModes, completingEntry, completingPackagingMode, completeForm]); // eslint-disable-line react-hooks/exhaustive-deps

    /** Modes not yet on a line — what "Add packing line" may still offer. */
    const unusedPackingModes = useMemo(() => {
        void packingRevision;
        const used = new Set(((completeForm.getValues('packing_lines') ?? []) as PackingLineValues[]).map((l) => l.mode));
        return packingModes.filter((p) => !used.has(p.mode));
    }, [packingModes, completeForm, packingRevision]);

    // Day-bin consumption for the batch being completed (Phase 6): the
    // backend-computed `opening + Σ loaded − closing − Σ returned` per
    // material. Fetched only with the flag on; null on 404 (older backend).
    const { data: entryDayBin } = useQuery({
        queryKey: ['production', 'entry-day-bin', completingEntry?.id],
        queryFn: () => getEntryDayBinSummary(completingEntry!.id),
        enabled: traceabilityEnabled && completingEntry !== null,
    });

    // Prefill the dedicated Resin/MB rows from the day-bin figure — same
    // dirty-guard contract as every other auto-fill in this drawer: setValue
    // never marks the field dirty, any user-touched field is theirs and is
    // never overwritten. Manual entry stays fully editable throughout; a
    // floor that ignores scanning entirely (has_movements false) prefills
    // nothing and completes exactly as before.
    // One closing-weight row per material that actually moved through this
    // batch — the supervisor is asked about exactly what they used, nothing
    // more.
    useEffect(() => {
        if (!traceabilityEnabled || !completingEntry || !entryDayBin?.has_movements) return;
        if (!completeForm.getFieldState('closing_day_bin').isDirty) {
            completeForm.setValue(
                'closing_day_bin',
                entryDayBin.materials.map((m) => ({ item_id: m.item.id, quantity_kg: null })),
            );
        }
    }, [entryDayBin, completingEntry, traceabilityEnabled, completeForm]);

    /**
     * Write one material's day-bin consumption into whichever fixed row it
     * belongs to (Resin or Masterbatch), from either the server's figure or
     * the closing weight being typed right now:
     *     consumed = opening + loaded − closing − returned
     * `consumption_kg` is the SERVER's figure and stays null until a closing
     * count exists — which only happens AFTER this form is submitted — so
     * during completion the same formula is applied to the supervisor's
     * live closing weight instead.
     *
     * This is called from the closing field's own onChange and NOT from a
     * useEffect keyed on the watched array, because that effect could never
     * fire: react-hook-form's `watch('closing_day_bin')` shallow-spreads
     * only the TOP level of _formValues, and setValue on a nested path
     * mutates the existing row object in place — so the array AND the row
     * keep their identity across an edit and the dependency never changes.
     * (Verified against react-hook-form 7.82: after
     * setValue('closing_day_bin.0.quantity_kg', 4.25) the value is written
     * but `before === after` is true for both the array and the row.)
     *
     * setValue never marks a field dirty, so a supervisor-typed kg is
     * still never overwritten — the same contract as every other auto-fill
     * in this drawer.
     */
    const applyDayBinConsumption = useCallback(
        (material: EntryDayBinMaterialSummary, typedClosingKg: number | null) => {
            const target = isResinItem(material.item) ? ('resin' as const) : isMasterbatchItem(material.item) ? ('mb' as const) : null;
            if (!target) return;
            const kgField = target === 'resin' ? ('resin_kg' as const) : ('mb_kg' as const);
            const itemField = target === 'resin' ? ('resin_item_id' as const) : ('mb_item_id' as const);

            // The bag actually scanned into the machine beats the colour-based
            // MB suggestion — still only while the supervisor hasn't touched it.
            if (!completeForm.getFieldState(itemField).isDirty) {
                completeForm.setValue(itemField, material.item.id);
            }

            const serverConsumed = toNum(material.consumption_kg);
            const derived =
                typedClosingKg === null
                    ? null
                    : (toNum(material.opening_kg) ?? 0) +
                      (toNum(material.loaded_kg) ?? 0) -
                      typedClosingKg -
                      (toNum(material.returned_kg) ?? 0);
            const consumed = serverConsumed ?? derived;
            // A negative result means the closing weight is more than what
            // went in — a real data problem, not a consumption figure.
            if (consumed === null || consumed < 0) return;
            if (completeForm.getFieldState(kgField).isDirty) return;
            completeForm.setValue(kgField, Math.round(consumed * 10000) / 10000);
            // A weighed day-bin figure outranks the calculated estimate —
            // latch so the live resin auto-calculation stops overwriting it.
            if (target === 'resin') resinKgWeighedRef.current = true;
        },
        [completeForm],
    );

    // The server-figure pass, on load. `entryDayBin` is a fresh object each
    // time the query resolves, so this dependency genuinely changes — unlike
    // the watched closing array, which is why the typed case lives in
    // onChange instead.
    useEffect(() => {
        if (!traceabilityEnabled || !completingEntry || !entryDayBin?.has_movements) return;
        for (const material of entryDayBin.materials) {
            applyDayBinConsumption(material, null);
        }
    }, [entryDayBin, completingEntry, traceabilityEnabled, applyDayBinConsumption]);

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
    // The standard's packaging rows win when they exist: they are the only
    // source that knows which modes this product genuinely has. Without them
    // the drawer stays exactly as it was.
    const usePackingLines = packingModes.length > 0;

    // Live totals computed at RENDER, not inside the useMemo below: nested
    // edits keep the watched arrays' identity (see applyDayBinConsumption),
    // so a primitive total is the only dependency that actually changes.
    const lumpsKgLive = (scrapsWatch ?? []).reduce((sum, s) => sum + (s?.type === 'lumps' ? (s.quantity_kg ?? 0) : 0), 0);
    // Reasons flagged reduces_runtime = false are excluded from the netting,
    // mirroring the backend's completionDowntimeMinutes; an un-picked reason
    // still counts, since every seeded reason reduces runtime.
    const downtimeMinutes = (downtimeEventsWatch ?? []).reduce((sum, line) => {
        const reason = downtimeReasons?.data.find((r) => r.id === line?.downtime_reason_id);
        if (reason && !reason.reduces_runtime) return sum;
        return sum + (downtimeLineMinutes(line?.from_time, line?.to_time) ?? 0);
    }, 0);

    // Everything the pre-submit results panel shows, computed live from the
    // form + the entry's Start Batch snapshots. Frontend duplicate of the
    // contract formulas — the backend metrics block is authoritative once
    // completed. Null members mean "inputs missing, show nothing".
    const results = useMemo(() => {
        if (!completingEntry) return null;
        const ct = toNum(completingEntry.standard_cycle_time);
        const cavities = activeCavitiesWatch ?? completingEntry.active_cavities ?? completingEntry.standard_cavities ?? null;
        // Downtime typed below comes off the hours BEFORE any expected-output
        // arithmetic — the paper report nets B/D and idle time out of the day
        // the same way. Unrounded, floored at zero: mirrors the backend rule.
        const grossHours = runningHoursWatch ?? null;
        const hours = grossHours !== null ? Math.max(grossHours - downtimeMinutes / 60, 0) : null;
        // Form's corrected pack size wins over the master (mirrors backend).
        const nosPerBox = nosPerBoxWatch ?? completingEntry.item.nos_per_box ?? null;
        // Pouch standard has no per-run correction field — always the master's.
        const nosPerPouch = completingEntry.item.nos_per_pouch ?? null;
        const expected = expectedOutput(ct, cavities, hours, nosPerBox, nosPerPouch, settings?.packing_rounding);
        const goodKg = nominalWeight && quantityProduced ? (quantityProduced * nominalWeight) / 1000 : null;
        const rejProdKg = nominalWeight && quantityScrap ? (quantityScrap * nominalWeight) / 1000 : null;
        const qcKg = qcRejectionWatch ?? null;
        const rejDiffKg = rejProdKg !== null && qcKg !== null ? rejProdKg - qcKg : null;
        const lumpsKg = lumpsKgLive;
        const issuedKg =
            (resinKgWatch ?? 0) + (mbKgWatch ?? 0) + (consumptionsWatch ?? []).reduce((sum, c) => sum + (c?.quantity_issued_kg ?? 0), 0);
        const confirmedRejKg = qcKg ?? rejProdKg;
        const unaccountedKg = issuedKg > 0 && goodKg !== null ? issuedKg - goodKg - (confirmedRejKg ?? 0) - lumpsKg : null;
        const actualBoxes = goodBoxesWatch ?? null;
        const actualPouches = pouchesWatch ?? null;
        // Efficiency at the PIECES grain. Boxes-vs-boxes compounded two
        // roundings and dropped loose pieces entirely (live screenshot:
        // 14,322 pcs against 13,333 expected read "75%" because 3 good boxes
        // ÷ 4 expected boxes). Boxes stay visible above as context only.
        const actualPieces = quantityProduced ?? null;
        const efficiencyPct =
            expected && expected.pieces > 0 && actualPieces !== null
                ? Math.round((actualPieces / expected.pieces) * 1000) / 10
                : null;
        return { ct, cavities, hours, grossHours, downtimeMinutes, nosPerBox, nosPerPouch, expected, goodKg, rejProdKg, qcKg, rejDiffKg, lumpsKg, issuedKg, unaccountedKg, actualBoxes, actualPouches, actualPieces, efficiencyPct };
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
        lumpsKgLive,
        downtimeMinutes,
        consumptionsWatch,
    ]);

    // Resin auto-calculation (the factory rule, verified line-for-line
    // against the 17.7.24 paper report): resin consumed = production kg +
    // rejection kg + lumps kg, all from bottle weight. Prefills LIVE as the
    // quantities are typed; a manual edit or a weighed day-bin figure takes
    // the field over permanently for this batch (the two latches above).
    // Masterbatch is deliberately NEVER estimated — actual weighed entry
    // only (owner decision pending the factory dosing answer).
    const resinCalcKg =
        results && results.goodKg !== null
            ? Math.round((results.goodKg + (results.rejProdKg ?? 0) + results.lumpsKg) * 10000) / 10000
            : null;
    useEffect(() => {
        if (!completingEntry || resinCalcKg === null) return;
        if (resinKgTouchedRef.current || resinKgWeighedRef.current) return;
        completeForm.setValue('resin_kg', resinCalcKg);
    }, [resinCalcKg, completingEntry, completeForm]);

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
                closing_day_bin,
                packing_lines,
                downtime_events,
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
            // Only rows the supervisor actually weighed. A blank closing
            // weight is "not counted", which must stay null downstream —
            // sending 0 would assert an empty bin nobody looked in.
            const closing = (closing_day_bin ?? [])
                .filter((row) => row.quantity_kg !== null && row.quantity_kg !== undefined)
                .map((row) => ({ item_id: row.item_id, quantity_kg: row.quantity_kg as number }));

            // One line per packaging mode used. derived_pieces is sent
            // explicitly and re-derived server-side, so an unexplained
            // override cannot be disguised by claiming they matched.
            const packingLines = (packing_lines ?? []).map((line) => ({
                mode: line.mode,
                production_standard_packaging_id: line.production_standard_packaging_id ?? undefined,
                boxes: line.boxes ?? 0,
                nos_per_box: line.nos_per_box ?? 0,
                loose_inner: line.loose_inner ?? 0,
                nos_per_inner: line.nos_per_inner ?? undefined,
                derived_pieces: linePieces(line),
                actual_pieces: line.actual_pieces ?? linePieces(line),
                override_reason: (line.override_reason ?? '').trim() || undefined,
            }));

            // Downtime lines → the backend contract: reason + MINUTES
            // (production_downtime_events stores minutes, no from/to
            // columns), with the picked from–to window folded into the note
            // — the trait's own docblock wants exactly that timing text, and
            // it is what satisfies requires_note reasons like DT-POWER.
            // Incomplete lines were either refused by the schema or are the
            // abandoned-empty kind, which the filter drops.
            const downtimeEvents = (downtime_events ?? [])
                .filter((line) => line.downtime_reason_id != null && line.from_time && line.to_time)
                .map((line) => {
                    const minutes = downtimeLineMinutes(line.from_time, line.to_time) ?? 0;
                    const noteText = (line.note ?? '').trim();
                    return {
                        downtime_reason_id: line.downtime_reason_id as number,
                        minutes,
                        note: noteText ? `${line.from_time}–${line.to_time} — ${noteText}` : `${line.from_time}–${line.to_time}`,
                    };
                })
                // Backend rule: minutes gt:0 — the schema already refused
                // equal picks, this is belt-and-braces.
                .filter((line) => line.minutes > 0);

            // Built as a variable, not an inline literal: packing_lines is a
            // real part of the wire contract that the shared
            // CompleteBatchPayload type has not been widened for yet.
            const payload = {
                ...rest,
                material_consumptions: consumptions,
                closing_day_bin: closing.length > 0 ? closing : undefined,
                packing_lines: packingLines.length > 0 ? packingLines : undefined,
                downtime_events: downtimeEvents.length > 0 ? downtimeEvents : undefined,
                // Cleared InputNumbers emit null — omit rather than send null.
                running_hours: running_hours ?? undefined,
                qc_rejection_kg: qc_rejection_kg ?? undefined,
                actual_cycle_time: actual_cycle_time ?? undefined,
                active_cavities: active_cavities ?? undefined,
            };

            return completeBatch(completingEntry.id, payload);
        },
        onSuccess: () => {
            invalidate();
            setCompletingEntry(null);
            completeForm.reset({ material_consumptions: [], scraps: [], packing_lines: [], downtime_events: [] });
        },
        onError: (error: any) => {
            const body = error?.response?.data;
            // A refused submission changes nothing: the drawer stays open with
            // every entered figure intact, and the message says what to fix
            // rather than just what was wrong.
            const fieldMessages: string[] = body?.errors ? (Object.values(body.errors).flat() as string[]) : [];
            Modal.error({
                title: 'Could not complete batch',
                content:
                    fieldMessages.length > 0 ? (
                        <>
                            <Typography.Paragraph style={{ marginBottom: 8 }}>
                                Nothing was submitted and nothing you typed was lost. Fix these, then press Complete Batch again:
                            </Typography.Paragraph>
                            <ul style={{ margin: 0, paddingLeft: 18 }}>
                                {fieldMessages.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </>
                    ) : (
                        (body?.message ?? 'Someone may have already completed this batch — refresh and try again.')
                    ),
            });
        },
    });

    // Inline "add a new reason" for the Downtime section — the list is
    // GLOBAL (the same one Production Configuration manages): once saved it
    // is available on every batch, and it is auto-picked here immediately.
    const [newDowntimeReasonText, setNewDowntimeReasonText] = useState('');
    const createDowntimeReasonMutation = useMutation({
        mutationFn: (description: string) =>
            saveDowntimeReason({
                code: downtimeReasonCode(description),
                description,
                // Reasons discovered at completion are by nature unplanned;
                // the office can reclassify in Production Configuration.
                planning_type: 'unplanned',
                requires_note: false,
                selectable_at_start: false,
                is_active: true,
                confirmation_status: 'To Confirm',
            }),
        onSuccess: (reason) => {
            // Show the new option NOW (the refetch confirms it after) so the
            // auto-pick below never renders a bare id.
            queryClient.setQueryData<{ data: DowntimeReason[] }>(['production', 'downtime-reasons'], (old) =>
                old ? { ...old, data: [...old.data, reason] } : old,
            );
            queryClient.invalidateQueries({ queryKey: ['production', 'downtime-reasons'] });
            setNewDowntimeReasonText('');
            // Auto-pick: fill the first line still missing a reason, else
            // start a new line with it — the timing is still theirs to type.
            const lines = completeForm.getValues('downtime_events') ?? [];
            const emptyIndex = lines.findIndex((l) => l?.downtime_reason_id == null);
            if (emptyIndex >= 0) {
                downtimeFields.update(emptyIndex, { ...lines[emptyIndex], downtime_reason_id: reason.id });
            } else {
                downtimeFields.append({ downtime_reason_id: reason.id, from_time: '', to_time: '', note: undefined });
            }
        },
        onError: (error: any) => {
            Modal.error({
                title: 'Could not save the reason',
                content: error?.response?.data?.message ?? 'It may already exist — check the list, or try different words.',
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

    // ----- Central Load Material: scan a bag into the factory day bin -----

    const openLoadMaterial = () => {
        setLoadBagBarcode('');
        setScannedLoadBag(null);
        setLoadBagKg(null);
        setLoadBagSupervisorId(currentUser?.id ?? null);
        setLoadBagSuccess(null);
        setLoadBagError(null);
        setLoadMaterialOpen(true);
    };

    const bagLookupMutation = useMutation({
        mutationFn: findMaterialBagByBarcode,
        onSuccess: (bag, barcode) => {
            if (!bag) {
                setScannedLoadBag(null);
                setLoadBagKg(null);
                setLoadBagError({ text: `No open bag with barcode "${barcode}" in the store.`, needsWarehouse: false });
                return;
            }
            setScannedLoadBag(bag);
            // Prefill the whole bag; the field stays editable for a part bag.
            setLoadBagKg(Number(bag.remaining_kg));
            setLoadBagError(null);
        },
        onError: (error: any) => {
            setScannedLoadBag(null);
            setLoadBagKg(null);
            setLoadBagError({ text: error?.response?.data?.message ?? 'Could not look up that barcode.', needsWarehouse: false });
        },
    });

    const submitLoadBagBarcode = () => {
        const code = loadBagBarcode.trim();
        if (!code) return;
        setLoadBagBarcode('');
        setLoadBagSuccess(null);
        bagLookupMutation.mutate(code);
    };

    const loadBagMutation = useMutation({
        mutationFn: loadBagToFactoryDayBin,
        onSuccess: (result, payload) => {
            // Compose the confirmation from the response where it answers,
            // falling back to what was scanned — never a blank.
            const material = result?.day_bin?.item ?? result?.bag?.lot?.item ?? scannedLoadBag?.lot?.item ?? null;
            const balance = result?.day_bin?.quantity_kg;
            setLoadBagSuccess(
                `Loaded ${payload.quantity_kg} kg of ${material ? itemLabel(material) : 'material'}` +
                    `${balance ? ` — day bin now holds ${balance} kg` : ''}.`,
            );
            setScannedLoadBag(null);
            setLoadBagKg(null);
            setLoadBagError(null);
            // The bag lost kg and the central day bin gained it — every
            // surface quoting either must move.
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-day-bin'] });
            queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
            queryClient.invalidateQueries({ queryKey: ['production', 'material-bags', 'pick-list'] });
            // Back to the gun: the next bag scans without a tap.
            loadBagInputRef.current?.focus();
        },
        onError: (error: any) => {
            const text = error?.response?.data?.message ?? 'Could not load the bag into the day bin.';
            setLoadBagError({
                text,
                // The one setup failure a supervisor can actually fix: nobody
                // has named the day-bin warehouse yet. The backend flags it as
                // a 422 on the `day_bin` key; the message match is a fallback.
                needsWarehouse:
                    Boolean(error?.response?.data?.errors?.day_bin) || /day.?bin warehouse/i.test(text),
            });
        },
    });

    const submitLoadBag = () => {
        const supervisorId = loadBagSupervisorId ?? currentUser?.id;
        if (!scannedLoadBag || !loadBagKg || loadBagKg <= 0 || !supervisorId) return;
        loadBagMutation.mutate({
            barcode: scannedLoadBag.barcode,
            quantity_kg: loadBagKg,
            supervisor_id: supervisorId,
        });
    };

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
                {/* Server already returns active-only; this filter stays as
                    defence in depth for a cached response from before the
                    active flag existed. */}
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
                            // A fresh batch gets a fresh resin field: the
                            // auto-calculation runs again until this batch's
                            // own manual edit or weighed figure latches it.
                            resinKgTouchedRef.current = false;
                            resinKgWeighedRef.current = false;
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
                                downtime_events: [],
                                // Cleared per batch — the modes are re-read from
                                // THIS batch's standard once its preview lands.
                                packing_lines: [],
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
                            setStartProductionDateOverride(null);
                            setStartResumeNotice(null);
                            setPendingStartBatchResume(null);
                            pendingStartBatchResumeRef.current = null;
                            setSelectedStandardId(undefined);
                            setSelectedPackagingId(undefined);
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
                                {running &&
                                    (running.production_date !== clockProductionDate ||
                                        (detectedShift !== undefined && running.shift.id !== detectedShift.id)) && (
                                        // A batch left running from an earlier shift/date — flag it so
                                        // it's obvious why the machine can't start a new one and needs
                                        // completing or handing over. Compared against the clock's
                                        // current context, so switching the shift tab never mislabels
                                        // a genuinely-current batch.
                                        <Tag color="gold" style={{ marginBottom: 6 }}>
                                            Carryover · {running.production_date} {running.shift.name}
                                        </Tag>
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
                                        {/* Phase 6 traceability actions — invisible unless the
                                            backend flag is on, so with it off this card is
                                            exactly the pre-traceability UI. */}
                                        {running && traceabilityEnabled && (
                                            <Button
                                                block
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setDayBinTarget({ workCenter: wc, entry: running });
                                                }}
                                            >
                                                Materials
                                            </Button>
                                        )}
                                        {running && traceabilityEnabled && (
                                            <Button
                                                block
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setHandoverEntry(running);
                                                }}
                                            >
                                                Hand Over Shift
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
                {traceabilityEnabled && (
                    // Deliberately page-level, not on any machine card: bags
                    // feed the CENTRAL factory day bin, for all machines.
                    <Button type="primary" onClick={openLoadMaterial}>
                        Load Material
                    </Button>
                )}
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
                    { title: 'Item', render: (_, row) => itemLabel(row.item) },
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
                onCancel={() => {
                    setStartingMachine(null);
                    setStartProductionDateOverride(null);
                    setStartResumeNotice(null);
                    setPendingStartBatchResume(null);
                    pendingStartBatchResumeRef.current = null;
                }}
                onOk={startForm.handleSubmit((values) => startMutation.mutate(values))}
                confirmLoading={startMutation.isPending}
                // Fail-closed in the UI too: the button is dead until the
                // backend says the product is ready. The server refuses it
                // regardless — this only stops the supervisor wasting a tap.
                okButtonProps={{
                    disabled:
                        previewLoading
                        // During a Configure Recipe return, the base preview
                        // arrives before the saved variant/package selection
                        // is revalidated against it. Never allow the brief
                        // intermediate state to start a different standard.
                        || pendingStartBatchResume !== null
                        || (!!startItemId && !!batchPreview && !batchPreview.readiness.ready)
                        // A material shortage does not refuse the start — the
                        // backend records it and lets the batch run. It only
                        // demands that a human says, in writing, why. With no
                        // shortage read at all (flag off, no recipe, no piece
                        // count) startHasShortage is false and this term
                        // vanishes: a permanently absent read must never
                        // dead-end a start.
                        || (startHasShortage && !(startAnyway && shortageReasonOk))
                        // But a read that is merely IN FLIGHT is different from
                        // one that will never come. Without this, the moment
                        // between the preview resolving and the bin answering
                        // is a live OK button on a batch nobody has been told
                        // is short — a short start with no prompt and no
                        // recorded reason. Milliseconds, and self-clearing:
                        // on error isFetching drops with data still undefined,
                        // so a failed read leaves the button live.
                        || (binAvailabilityLoading && !binAvailability)
                        // Colour, when the masters don't fix one, is a real
                        // answer this run needs — not an optional extra. The
                        // backend accepts a start without it (and existing
                        // integrations still may), so this dialog is where
                        // the question is actually put.
                        || (startColourRequired && !startColour),
                }}
                okText="Start Batch"
                destroyOnHidden
            >
                {/* Confirmation of where this batch will be filed — the shift is
                    auto-picked from the clock, so show it rather than ask again. */}
                <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
                    Shift: <Typography.Text strong>{effectiveShift?.name ?? '—'}</Typography.Text>
                    {' · '}Date: <Typography.Text strong>{startProductionDate}</Typography.Text>
                </Typography.Paragraph>
                {startResumeNotice && (
                    <Alert
                        type={startResumeNotice === 'created' ? 'success' : 'info'}
                        showIcon
                        style={{ marginBottom: 16 }}
                        message={
                            startResumeNotice === 'created'
                                ? 'Recipe saved — readiness and material estimates were refreshed.'
                                : 'Recipe was not changed — your Start Batch details were restored.'
                        }
                    />
                )}
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
                                <Select
                                    {...field}
                                    size="large"
                                    // Grouped: products the factory has
                                    // standards for come first, legacy and
                                    // demo masters are behind a heading that
                                    // says what they are. Search still
                                    // matches the leaf labels, so typing a
                                    // product name works exactly as before.
                                    options={startItemOptions}
                                    showSearch
                                    optionFilterProp="label"
                                    placeholder="Search item…"
                                />
                            )}
                        />
                    </Form.Item>
                    {/* The unconfigured verdict. An actionable panel rather
                        than a sentence, because "this product has no
                        standards" is not information the supervisor can use
                        on its own — they need somewhere to go, and where a
                        configured product of the same name exists, a way to
                        simply take it. */}
                    {startItemUnconfigured && (
                        <Alert
                            type="warning"
                            showIcon
                            style={{ marginBottom: 16 }}
                            message="This product is not set up for production yet"
                            description={
                                <>
                                    <Typography.Paragraph style={{ marginBottom: 12 }}>
                                        No factory standard covers it, so there is no agreed weight, cycle time, cavity count or
                                        packing for this run — every expected figure below will be a dash, and nothing can check
                                        what the shift actually produced.
                                    </Typography.Paragraph>
                                    <Space wrap>
                                        <Link to="/production/configuration">
                                            <Button type="primary">Open Master Mapping</Button>
                                        </Link>
                                        {replacementSuggestion && (
                                            <Button
                                                onClick={() =>
                                                    startForm.setValue('item_id', replacementSuggestion.id, {
                                                        shouldValidate: true,
                                                    })
                                                }
                                            >
                                                Use configured replacement: {replacementSuggestion.name}
                                            </Button>
                                        )}
                                    </Space>
                                </>
                            }
                        />
                    )}
                    {/* Local fixtures are fully runnable and deliberately
                        absent from Tally. Said plainly here so the accountant
                        is never surprised by a shift that produced real
                        numbers and no voucher. */}
                    {startItemId && batchPreview?.readiness.is_local_fixture && (
                        <Alert
                            type="info"
                            showIcon
                            style={{ marginBottom: 16 }}
                            message="Local-only fixture — voucher posting disabled"
                            description="This product exists in the ERP but not in Tally. The batch will be recorded and approved normally; no Tally voucher will be queued for it."
                        />
                    )}
                    {/* The readiness verdict, straight from the backend gate that
                        will refuse the start — never a second opinion computed
                        here. Blocking findings name every missing master field so
                        the supervisor knows what to ask for, rather than seeing a
                        bare "not ready". */}
                    {startItemId && batchPreview && !batchPreview.readiness.ready && (
                        <Alert
                            type="error"
                            showIcon
                            style={{ marginBottom: 16 }}
                            message={batchPreview.readiness.summary ?? 'This product is not production-ready.'}
                            description={
                                <ul style={{ margin: '8px 0 0', paddingLeft: 18 }}>
                                    {batchPreview.readiness.blocking.map((f) => (
                                        <li key={f.code}>
                                            <Typography.Text strong>{f.label}</Typography.Text> — {f.detail}
                                        </li>
                                    ))}
                                </ul>
                            }
                        />
                    )}
                    {startItemId && batchPreview && batchPreview.readiness.warnings.length > 0 && (
                        <Alert
                            type="warning"
                            showIcon
                            style={{ marginBottom: 16 }}
                            message="Incomplete masters — the batch can still run"
                            description={
                                <ul style={{ margin: '8px 0 0', paddingLeft: 18 }}>
                                    {batchPreview.readiness.warnings.map((f) => (
                                        <li key={f.code}>
                                            <Typography.Text strong>{f.label}</Typography.Text> — {f.detail}
                                        </li>
                                    ))}
                                </ul>
                            }
                        />
                    )}
                    {/* Variant picker — shown ONLY when the product genuinely has
                        more than one standard. One variant means no question is
                        asked: configuration complexity must not reach the floor. */}
                    {(batchPreview?.variants?.length ?? 0) > 1 && (
                        <Form.Item
                            label="Which standard is this run?"
                            extra="Same product, different cavity / weight / cycle time."
                        >
                            <Radio.Group
                                value={selectedStandardId}
                                onChange={(e) => {
                                    setSelectedStandardId(e.target.value);
                                    setSelectedPackagingId(undefined);
                                }}
                                optionType="button"
                                buttonStyle="solid"
                                size="large"
                                options={(batchPreview?.variants ?? []).map((v) => ({
                                    value: v.id,
                                    label: v.status === 'unresolved' ? `${v.label} — needs confirming` : v.label,
                                }))}
                            />
                        </Form.Item>
                    )}

                    {/* Packaging choice — only when both pouch and tray exist. */}
                    {(() => {
                        const chosen = (batchPreview?.variants ?? []).find((v) => v.id === selectedStandardId)
                            ?? (batchPreview?.variants?.length === 1 ? batchPreview.variants[0] : undefined);
                        if (!chosen || chosen.packagings.length < 2) return null;
                        return (
                            <Form.Item label="How is it packed?">
                                <Radio.Group
                                    value={selectedPackagingId}
                                    onChange={(e) => setSelectedPackagingId(e.target.value)}
                                    optionType="button"
                                    buttonStyle="solid"
                                    size="large"
                                    options={chosen.packagings.map((p) => ({ value: p.id, label: p.label }))}
                                />
                            </Form.Item>
                        );
                    })()}

                    {/* Watch-mode notes. Advisory by design — the factory has 86
                        product standards and no machine mapping yet, so blocking
                        here would stop production without producing information. */}
                    {(batchPreview?.warnings?.length ?? 0) > 0 && (
                        <Alert
                            type="warning"
                            showIcon
                            style={{ marginBottom: 16 }}
                            message="Using the factory product standard"
                            description={
                                <ul style={{ margin: '4px 0 0', paddingLeft: 18 }}>
                                    {(batchPreview?.warnings ?? []).map((w) => (
                                        <li key={w.code}>{w.message}</li>
                                    ))}
                                </ul>
                            }
                        />
                    )}

                    {/* Colour, asked only when the masters cannot answer it.
                        The options are the colours the catalogue actually
                        uses — derived, not a hardcoded list, so a colour the
                        factory adds to an item shows up here by itself. No
                        default: the supervisor states what is in the machine. */}
                    {startColourRequired && (
                        <Form.Item
                            label="Colour"
                            required
                            validateStatus={!startColour ? 'warning' : ''}
                            help={
                                !startColour
                                    ? 'The masters do not record a colour for this product — say which one is running.'
                                    : undefined
                            }
                        >
                            <Controller
                                name="colour"
                                control={startForm.control}
                                render={({ field }) => (
                                    <Select
                                        {...field}
                                        value={field.value ?? undefined}
                                        size="large"
                                        options={colourOptions}
                                        placeholder="Which colour is running?"
                                        style={{ width: '100%' }}
                                    />
                                )}
                            />
                        </Form.Item>
                    )}

                    {startItem && (
                        <>
                            {/* The machine's own approved figures govern when they exist —
                                said out loud, because the supervisor comparing this card
                                to the workbook would otherwise see numbers that differ
                                from the printed standard with no explanation. */}
                            {batchPreview?.configuration && (
                                <Alert
                                    type="success"
                                    showIcon
                                    style={{ marginBottom: 12 }}
                                    message={`Using this machine's approved configuration${
                                        batchPreview.configuration.default_cycle_time
                                            ? ` — CT ${fmtNum(toNum(batchPreview.configuration.default_cycle_time))} s`
                                            : ''
                                    }${
                                        batchPreview.configuration.default_cavities
                                            ? ` · ${batchPreview.configuration.default_cavities} cavities`
                                            : ''
                                    }`}
                                />
                            )}
                            {/* Read-only card of the item master's standards — what the
                                expected-output engine will hold this run against. */}
                            <Descriptions
                                size="small"
                                column={2}
                                bordered
                                style={{ marginBottom: 16 }}
                                title={<Typography.Text strong>Product standards</Typography.Text>}
                            >
                                {/* Same precedence the estimate and Start Batch already
                                    use: the factory product standard outranks the item
                                    master. Reading the item alone made this card show
                                    dashes for a product whose estimate underneath was
                                    computing correctly from the standard — the screen
                                    calculated from the right numbers and displayed none
                                    of them. */}
                                <Descriptions.Item label="Colour">
                                    {startItem.colour ?? startColour ?? '—'}
                                </Descriptions.Item>
                                <Descriptions.Item label="Weight">
                                    {(() => {
                                        const w = batchPreview?.standard?.unit_weight_grams ?? startItem.nominal_weight_grams;
                                        return w ? `${fmtNum(toNum(w))} g` : '—';
                                    })()}
                                </Descriptions.Item>
                                <Descriptions.Item label="Std CT">
                                    {(() => {
                                        // Effective precedence, matching the backend: approved
                                        // machine configuration → standard → item master.
                                        const ct = batchPreview?.configuration?.default_cycle_time
                                            ?? batchPreview?.standard?.cycle_time
                                            ?? startItem.standard_cycle_time;
                                        return ct ? `${fmtNum(toNum(ct))} s` : '—';
                                    })()}
                                </Descriptions.Item>
                                <Descriptions.Item label="Std cavities">
                                    {batchPreview?.configuration?.default_cavities
                                        ?? batchPreview?.standard?.cavities
                                        ?? startItem.standard_cavities
                                        ?? '—'}
                                </Descriptions.Item>
                                <Descriptions.Item label="Pcs/box">
                                    {batchPreview?.estimation?.nos_per_box ?? startItem.nos_per_box ?? '—'}
                                </Descriptions.Item>
                                <Descriptions.Item label="Pcs/tray">
                                    {batchPreview?.estimation?.nos_per_tray ?? startItem.nos_per_tray ?? '—'}
                                </Descriptions.Item>
                                {/* The master's packaging-MATERIAL specs. Which carton,
                                    which tray, which pouch film to fetch — the three
                                    right-hand columns of the factory sheet. Shown only
                                    when the standard actually carries one: a row of
                                    dashes for material nobody recorded is noise on a
                                    card a supervisor reads mid-setup. Never computed
                                    from — "750*610" is millimetres, not a count. */}
                                {batchPreview?.standard?.carton_spec && (
                                    <Descriptions.Item label="Carton">
                                        {batchPreview.standard.carton_spec}
                                    </Descriptions.Item>
                                )}
                                {batchPreview?.standard?.tray_spec && (
                                    <Descriptions.Item label="Tray">
                                        {batchPreview.standard.tray_spec}
                                    </Descriptions.Item>
                                )}
                                {batchPreview?.standard?.pouch_spec && (
                                    <Descriptions.Item label="Pouch film">
                                        {batchPreview.standard.pouch_spec}
                                    </Descriptions.Item>
                                )}
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
                            {/* What this run SHOULD produce and consume, from the
                                product's standards — shown before confirming, so a
                                wrong standard is caught by the person who knows the
                                machine. A null figure stays a dash: never a guess. */}
                            {batchPreview && (
                                <Descriptions
                                    size="small"
                                    column={2}
                                    bordered
                                    style={{ marginBottom: 16 }}
                                    title={<Typography.Text strong>Estimated for this shift</Typography.Text>}
                                >
                                    <Descriptions.Item label="Planned hours">
                                        {batchPreview.estimation.planned_hours ? fmtNum(toNum(batchPreview.estimation.planned_hours)) : '—'}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="Expected cycles">
                                        {batchPreview.estimation.expected_cycles ?? '—'}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="Expected pieces">
                                        {batchPreview.estimation.expected_pieces ?? '—'}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="Expected kg">
                                        {batchPreview.estimation.expected_kg ? fmtNum(toNum(batchPreview.estimation.expected_kg)) : '—'}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="Expected boxes">
                                        {batchPreview.estimation.expected_boxes ?? '—'}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="Expected trays">
                                        {batchPreview.estimation.expected_trays ?? '—'}
                                    </Descriptions.Item>
                                    {/* Pouch row appears only for pouch-packed products
                                        — no product carries a pouch standard today, so
                                        for every current product this row is absent
                                        rather than showing a misleading dash. */}
                                    {batchPreview.estimation.nos_per_pouch !== null && (
                                        <Descriptions.Item label="Expected pouches" span={2}>
                                            {batchPreview.estimation.expected_pouches ?? '—'}
                                        </Descriptions.Item>
                                    )}
                                </Descriptions>
                            )}
                            {batchPreview && batchPreview.estimation.expected_materials.length > 0 && (
                                <Descriptions
                                    size="small"
                                    column={1}
                                    bordered
                                    style={{ marginBottom: 16 }}
                                    title={<Typography.Text strong>Expected materials</Typography.Text>}
                                >
                                    {batchPreview.estimation.expected_materials.map((m) => (
                                        <Descriptions.Item key={m.item_id} label={m.name}>
                                            {fmtNum(toNum(m.quantity))} {m.uom ?? ''}
                                        </Descriptions.Item>
                                    ))}
                                </Descriptions>
                            )}
                            {/* Resin needs NO recipe — the factory's own paper
                                report calculates consumption purely from bottle
                                weight (production kg + rejection kg + lumps,
                                verified against real sheets 11 rows out of 11),
                                and expected_kg is that same weight arithmetic.
                                This block used to be an Alert saying resin
                                "cannot be estimated", which contradicted the
                                paper in the supervisor's other hand; the owner
                                called it out, correctly. A recipe only ever
                                adds masterbatch/consumable norms — and those
                                stay unestimated on purpose until the factory
                                confirms the dosing. */}
                            {batchPreview &&
                                batchPreview.estimation.recipe_source === null &&
                                batchPreview.estimation.expected_kg !== null && (
                                    <Descriptions
                                        size="small"
                                        column={1}
                                        bordered
                                        style={{ marginBottom: 16 }}
                                        title={<Typography.Text strong>Expected materials</Typography.Text>}
                                    >
                                        <Descriptions.Item label="PET resin (from bottle weight)">
                                            ≈ {fmtNum(toNum(batchPreview.estimation.expected_kg))} kg — rejection and
                                            lumps add to this as weighed, same as the paper report
                                        </Descriptions.Item>
                                    </Descriptions>
                                )}

                            {/* What the machine's bin ACTUALLY holds, against what
                                the recipe needs. Strictly read-only: bags are
                                scanned in once, for the whole bay, on the Bin Bay
                                page. Nothing here opens a load form — a second
                                place to declare material is exactly how the bin
                                and the batch end up disagreeing.

                                Only bin-tracked (mass) components appear. Nos
                                consumables never sit in the bin, so listing them
                                here would invite a "shortage" that cannot exist. */}
                            {traceabilityEnabled && startExpectedPieces !== null && (
                                <Card
                                    size="small"
                                    style={{ marginBottom: 16 }}
                                    loading={binAvailabilityLoading && !binAvailability}
                                    title={<Typography.Text strong>Material availability — bin bay</Typography.Text>}
                                    extra={
                                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                            Read-only · load bags on the Bin Bay page
                                        </Typography.Text>
                                    }
                                >
                                    {startMassComponents.length === 0 ? (
                                        <Typography.Text type="secondary">
                                            Nothing in this product&rsquo;s recipe is bin-tracked — there is no bay
                                            balance to check this run against.
                                        </Typography.Text>
                                    ) : (
                                        <Table
                                            size="small"
                                            rowKey="item_id"
                                            pagination={false}
                                            dataSource={startMassComponents}
                                            columns={[
                                                {
                                                    title: 'Material',
                                                    render: (_, row) => (
                                                        <>
                                                            <div>{row.name}</div>
                                                            {row.sku && (
                                                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                                    {row.sku}
                                                                </Typography.Text>
                                                            )}
                                                        </>
                                                    ),
                                                },
                                                {
                                                    title: 'Needs',
                                                    align: 'right',
                                                    render: (_, row) =>
                                                        `${fmtNum(toNum(row.expected_quantity), 3)} ${row.uom ?? ''}`.trim(),
                                                },
                                                {
                                                    title: 'In bin',
                                                    align: 'right',
                                                    render: (_, row) =>
                                                        `${fmtNum(toNum(row.available_quantity), 3)} ${row.uom ?? ''}`.trim(),
                                                },
                                                {
                                                    title: 'Short by',
                                                    align: 'right',
                                                    render: (_, row) => {
                                                        const short = toNum(row.shortage_quantity) ?? 0;
                                                        return short > 0 ? (
                                                            <Tag color="error">
                                                                {fmtNum(short, 3)} {row.uom ?? ''}
                                                            </Tag>
                                                        ) : (
                                                            <Tag color="success">enough</Tag>
                                                        );
                                                    },
                                                },
                                            ]}
                                            expandable={{
                                                rowExpandable: (row) =>
                                                    (startBinByItemId.get(row.item_id)?.layers.length ?? 0) > 0,
                                                expandedRowRender: (row) => {
                                                    const bin = startBinByItemId.get(row.item_id);
                                                    if (!bin || bin.layers.length === 0) return null;
                                                    return (
                                                        <>
                                                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                                Where the {row.name} in this bin came from — oldest load
                                                                first.
                                                            </Typography.Text>
                                                            <Table
                                                                size="small"
                                                                rowKey="movement_id"
                                                                pagination={false}
                                                                style={{ marginTop: 8 }}
                                                                dataSource={bin.layers}
                                                                columns={[
                                                                    {
                                                                        title: 'Lot',
                                                                        render: (_, layer) =>
                                                                            layer.lot?.supplier_lot_no ?? '—',
                                                                    },
                                                                    {
                                                                        title: 'Bag barcode',
                                                                        render: (_, layer) => layer.barcode ?? '—',
                                                                    },
                                                                    {
                                                                        title: 'Loaded (kg)',
                                                                        align: 'right',
                                                                        render: (_, layer) =>
                                                                            fmtNum(toNum(layer.loaded_kg), 3),
                                                                    },
                                                                    {
                                                                        title: 'Still in bin (kg)',
                                                                        align: 'right',
                                                                        render: (_, layer) =>
                                                                            fmtNum(toNum(layer.in_bin_kg), 3),
                                                                    },
                                                                ]}
                                                            />
                                                        </>
                                                    );
                                                },
                                            }}
                                        />
                                    )}
                                </Card>
                            )}

                            {/* The shortfall, named. Loud on purpose — the cost of
                                finding out mid-shift is a stopped machine. It does
                                not hide any of the form below it. */}
                            {startHasShortage && (
                                <Alert
                                    type="error"
                                    showIcon
                                    style={{ marginBottom: 16 }}
                                    message="Not enough material in this machine's bin for the full run"
                                    description={
                                        <>
                                            <ul style={{ margin: '8px 0 0', paddingLeft: 18 }}>
                                                {startShortComponents.map((c) => (
                                                    <li key={c.item_id}>
                                                        <Typography.Text strong>{c.name}</Typography.Text> — short{' '}
                                                        <Typography.Text strong>
                                                            {fmtNum(toNum(c.shortage_quantity), 3)} {c.uom ?? ''}
                                                        </Typography.Text>{' '}
                                                        (needs {fmtNum(toNum(c.expected_quantity), 3)}, bin holds{' '}
                                                        {fmtNum(toNum(c.available_quantity), 3)})
                                                    </li>
                                                ))}
                                            </ul>
                                            <Typography.Paragraph
                                                type="secondary"
                                                style={{ fontSize: 12, margin: '8px 0 0' }}
                                            >
                                                Scan the bags in at the Bin Bay — or start anyway and say why.
                                            </Typography.Paragraph>
                                        </>
                                    }
                                />
                            )}
                            {/* The audited override. The server records it and
                                refuses nothing; this tick-box is the guard that
                                makes a short start a deliberate, attributed
                                decision rather than an accident. */}
                            {startHasShortage && (
                                <Form.Item style={{ marginBottom: startAnyway ? 8 : 16 }}>
                                    <Checkbox
                                        checked={startAnyway}
                                        onChange={(e) => setStartAnyway(e.target.checked)}
                                    >
                                        Start anyway — material will reach the machine before it runs out
                                    </Checkbox>
                                </Form.Item>
                            )}
                            {startHasShortage && startAnyway && (
                                <Form.Item
                                    label="Why is this run starting short?"
                                    required
                                    validateStatus={
                                        shortageReason.length > 0 && !shortageReasonOk ? 'error' : ''
                                    }
                                    help={
                                        shortageReason.length > 0 && !shortageReasonOk
                                            ? 'At least 5 characters.'
                                            : undefined
                                    }
                                    extra="Recorded against this batch and readable on the approval trail."
                                >
                                    <Input.TextArea
                                        value={shortageReason}
                                        onChange={(e) => setShortageReason(e.target.value)}
                                        rows={2}
                                        maxLength={500}
                                        showCount
                                        placeholder="e.g. bay is weighing the next lot in now"
                                    />
                                </Form.Item>
                            )}
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

                    {usePackingLines && (
                        <>
                            <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 4 }}>
                                {packingModes.length === 1
                                    ? `Packed ${MODE_LABEL[packingModes[0].mode].toLowerCase()} — the only way this product is packed.`
                                    : 'This product is packed more than one way. Add a line for each way this run actually used — every carton belongs to exactly one line.'}
                            </Typography.Text>

                            {packingFields.fields.map((field, index) => {
                                const line = ((completeForm.getValues('packing_lines') ?? []) as PackingLineValues[])[index];
                                if (!line) return null;
                                const packaging = packagingForLine(line);
                                const inner = innerNoun(line.mode);
                                const derived = linePieces(line);
                                const actual = line.actual_pieces ?? derived;
                                const lineErrors = completeForm.formState.errors.packing_lines?.[index];
                                return (
                                    <Card
                                        key={field.id}
                                        size="small"
                                        style={{ marginTop: 8 }}
                                        title={
                                            <Space>
                                                <Tag color="blue">{MODE_LABEL[line.mode]}</Tag>
                                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                    {line.nos_per_box ?? '—'} pcs/carton
                                                    {inner && line.nos_per_inner ? ` · ${line.nos_per_inner} pcs/${inner.slice(0, -1)}` : ''}
                                                </Typography.Text>
                                            </Space>
                                        }
                                        extra={
                                            packingFields.fields.length > 1 ? (
                                                <Button
                                                    size="small"
                                                    danger
                                                    onClick={() => {
                                                        packingFields.remove(index);
                                                        recomputePackingTotals();
                                                    }}
                                                >
                                                    Remove
                                                </Button>
                                            ) : undefined
                                        }
                                    >
                                        {lineErrors?.mode?.message && (
                                            <Alert type="error" showIcon style={{ marginBottom: 8 }} message={lineErrors.mode.message} />
                                        )}
                                        <Row gutter={[8, 8]}>
                                            <Col xs={12} sm={8}>
                                                <Form.Item
                                                    label="Cartons"
                                                    style={{ marginBottom: 0 }}
                                                    validateStatus={lineErrors?.boxes ? 'error' : ''}
                                                    help={lineErrors?.boxes?.message}
                                                >
                                                    <Controller
                                                        name={`packing_lines.${index}.boxes`}
                                                        control={completeForm.control}
                                                        render={({ field: boxField }) => (
                                                            <InputNumber
                                                                {...boxField}
                                                                size="large"
                                                                min={0}
                                                                style={{ width: '100%' }}
                                                                onChange={(value) => {
                                                                    boxField.onChange(value);
                                                                    recomputePackingTotals();
                                                                }}
                                                            />
                                                        )}
                                                    />
                                                </Form.Item>
                                            </Col>
                                            <Col xs={12} sm={8}>
                                                {/* Prefilled from the imported standard and still
                                                    editable: a run genuinely packed at a different
                                                    carton size must be recordable, and a standard
                                                    that never carried the figure must not dead-end
                                                    the completion. */}
                                                <Form.Item
                                                    label="Pcs/carton"
                                                    style={{ marginBottom: 0 }}
                                                    extra={packaging?.nos_per_box ? `standard: ${packaging.nos_per_box}` : 'not on the standard — enter it'}
                                                    validateStatus={lineErrors?.nos_per_box ? 'error' : ''}
                                                    help={lineErrors?.nos_per_box?.message}
                                                >
                                                    <Controller
                                                        name={`packing_lines.${index}.nos_per_box`}
                                                        control={completeForm.control}
                                                        render={({ field: perBoxField }) => (
                                                            <InputNumber
                                                                {...perBoxField}
                                                                size="large"
                                                                min={1}
                                                                style={{ width: '100%' }}
                                                                onChange={(value) => {
                                                                    perBoxField.onChange(value);
                                                                    recomputePackingTotals();
                                                                }}
                                                            />
                                                        )}
                                                    />
                                                </Form.Item>
                                            </Col>
                                            {inner !== null && (line.nos_per_inner ?? null) === null && (
                                                <Col xs={24}>
                                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                        This standard has no pieces-per-{inner.slice(0, -1)}, so loose {inner} cannot be
                                                        converted to pieces — count them into the cartons or correct the pieces below.
                                                    </Typography.Text>
                                                </Col>
                                            )}
                                            {inner !== null && (line.nos_per_inner ?? null) !== null && (
                                                <Col xs={12} sm={8}>
                                                    <Form.Item
                                                        label={`Loose ${inner}`}
                                                        style={{ marginBottom: 0 }}
                                                        extra="not yet in a carton"
                                                        validateStatus={lineErrors?.loose_inner ? 'error' : ''}
                                                        help={lineErrors?.loose_inner?.message}
                                                    >
                                                        <Controller
                                                            name={`packing_lines.${index}.loose_inner`}
                                                            control={completeForm.control}
                                                            render={({ field: innerField }) => (
                                                                <InputNumber
                                                                    {...innerField}
                                                                    size="large"
                                                                    min={0}
                                                                    style={{ width: '100%' }}
                                                                    onChange={(value) => {
                                                                        innerField.onChange(value);
                                                                        recomputePackingTotals();
                                                                    }}
                                                                />
                                                            )}
                                                        />
                                                    </Form.Item>
                                                </Col>
                                            )}
                                            <Col xs={12} sm={8}>
                                                <Form.Item
                                                    label="Pieces counted"
                                                    style={{ marginBottom: 0 }}
                                                    extra={`standard: ${derived}`}
                                                    validateStatus={lineErrors?.actual_pieces ? 'error' : ''}
                                                    help={lineErrors?.actual_pieces?.message}
                                                >
                                                    <Controller
                                                        name={`packing_lines.${index}.actual_pieces`}
                                                        control={completeForm.control}
                                                        render={({ field: pieceField }) => (
                                                            <InputNumber
                                                                {...pieceField}
                                                                size="large"
                                                                min={0}
                                                                style={{ width: '100%' }}
                                                                placeholder={String(derived)}
                                                                onChange={(value) => {
                                                                    pieceField.onChange(value);
                                                                    recomputePackingTotals();
                                                                }}
                                                            />
                                                        )}
                                                    />
                                                </Form.Item>
                                            </Col>
                                        </Row>
                                        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                                            {line.boxes ?? 0} cartons × {line.nos_per_box ?? 0}
                                            {inner && line.nos_per_inner
                                                ? ` + ${line.loose_inner ?? 0} loose ${inner} × ${line.nos_per_inner}`
                                                : ''}{' '}
                                            = <strong>{derived}</strong> pcs
                                            {actual !== derived ? ` · counted ${actual}` : ''}
                                            {packaging && innersPerBox(packaging)
                                                ? ` · ${innersPerBox(packaging)} ${inner}/carton`
                                                : ''}
                                        </Typography.Text>
                                        {/* A counted figure that differs from the pack-size
                                            arithmetic is a real event (short box, part carton)
                                            — recorded, not silently accepted. */}
                                        {actual !== derived && (
                                            <Form.Item
                                                label="Why does the count differ?"
                                                style={{ marginTop: 8, marginBottom: 0 }}
                                                validateStatus={lineErrors?.override_reason ? 'error' : ''}
                                                help={lineErrors?.override_reason?.message}
                                            >
                                                <Controller
                                                    name={`packing_lines.${index}.override_reason`}
                                                    control={completeForm.control}
                                                    render={({ field: reasonField }) => (
                                                        <Input {...reasonField} maxLength={255} placeholder="Short box, part carton, miscount…" />
                                                    )}
                                                />
                                            </Form.Item>
                                        )}
                                    </Card>
                                );
                            })}

                            {unusedPackingModes.length > 0 && (
                                <Space wrap style={{ marginTop: 8 }}>
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        Also packed some in:
                                    </Typography.Text>
                                    {unusedPackingModes.map((packaging) => (
                                        <Button
                                            key={packaging.id}
                                            size="small"
                                            onClick={() => {
                                                packingFields.append(blankPackingLine(packaging));
                                                recomputePackingTotals();
                                            }}
                                        >
                                            Add {MODE_LABEL[packaging.mode].toLowerCase()} line
                                        </Button>
                                    ))}
                                </Space>
                            )}

                            <Card size="small" style={{ marginTop: 12, marginBottom: 16 }}>
                                <ResultRow
                                    label="Total pieces"
                                    value={(quantityProduced ?? 0).toLocaleString('en-IN')}
                                    formula="every packing line + loose pieces"
                                />
                                <ResultRow
                                    label="Total cartons"
                                    value={String(goodBoxesWatch ?? 0)}
                                    formula="each carton counted once, under one mode only"
                                />
                            </Card>
                        </>
                    )}

                    {/* Box-first: boxes are what the floor physically counts.
                        Pieces derive from boxes × pcs/box + loose, and stay
                        editable for corrections.

                        These sit BELOW the packing lines on purpose. When lines
                        drive them both fields are read-only totals, and while
                        they sat above the card that feeds them the drawer
                        opened on two greyed-out zeros with no visible way in —
                        the owner reported it as "why could I not enter
                        anything". Outputs never precede the inputs they are
                        computed from. Without packing lines they are the real
                        entry fields and this is simply the old order. */}
                    <Row gutter={16}>
                        <Col span={12}>
                            <Form.Item
                                label="Good Boxes"
                                validateStatus={completeForm.formState.errors.no_of_box ? 'error' : ''}
                                help={completeForm.formState.errors.no_of_box?.message}
                                extra={usePackingLines ? 'total cartons across the packing lines' : undefined}
                            >
                                <Controller
                                    name="no_of_box"
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        // With packing lines the carton total is the sum of
                                        // the lines and is not separately typeable — that is
                                        // exactly how the same cartons would get counted
                                        // under two modes.
                                        <InputNumber {...field} size="large" min={0} disabled={usePackingLines} style={{ width: '100%' }} />
                                    )}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item label="Loose Pieces (optional)" extra={usePackingLines ? 'pieces in no container at all' : undefined}>
                                <Controller
                                    name="loose_pieces"
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        <InputNumber
                                            {...field}
                                            size="large"
                                            min={0}
                                            style={{ width: '100%' }}
                                            onChange={(value) => {
                                                field.onChange(value);
                                                if (usePackingLines) recomputePackingTotals();
                                            }}
                                        />
                                    )}
                                />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Row gutter={16}>
                        <Col span={12}>
                            <Form.Item
                                label="Quantity Produced (Nos)"
                                validateStatus={completeForm.formState.errors.quantity_produced ? 'error' : ''}
                                help={
                                    // The field is derived (and disabled) when packing lines
                                    // drive it, so "Must be greater than 0" would point at
                                    // something the supervisor cannot edit. Point at the
                                    // lines, which is where the fix actually is.
                                    completeForm.formState.errors.quantity_produced
                                        ? usePackingLines
                                            ? 'Fill in the cartons and pieces on the packing lines below — this total comes from them.'
                                            : completeForm.formState.errors.quantity_produced.message
                                        : undefined
                                }
                                extra={
                                    usePackingLines
                                        ? 'sum of the packing lines + loose pieces'
                                        : completingEntry?.item.nos_per_box
                                          ? `= boxes × ${completingEntry.item.nos_per_box} pcs/box + loose — editable`
                                          : showPouchFields
                                            ? `= pouches × ${completingItem?.nos_per_pouch} pcs/pouch + loose — editable`
                                            : undefined
                                }
                            >
                                <Controller
                                    name="quantity_produced"
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        // Derived from the lines when they exist — a
                                        // separately-typed total is the one figure that
                                        // could silently disagree with what was packed.
                                        <InputNumber {...field} size="large" min={0} disabled={usePackingLines} style={{ width: '100%' }} />
                                    )}
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
                        {/* Not a form field: reads/writes the single scraps
                            line of type 'lumps' (see setLumpsKgValue), so
                            this and the scrap list below are one entry path. */}
                        <Col span={12}>
                            <Form.Item label="Lumps (Kg)" extra="Melted waste, weighed — counts into resin consumed">
                                <InputNumber
                                    size="large"
                                    min={0}
                                    style={{ width: '100%' }}
                                    value={lumpsLineIndex >= 0 ? completeForm.watch(`scraps.${lumpsLineIndex}.quantity_kg`) ?? null : null}
                                    onChange={(value) => setLumpsKgValue(value === null || value === undefined ? null : Number(value))}
                                />
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

                    {/* display:block on the section headings: with the legacy
                        packing row hidden, two adjacent inline strong texts
                        collapsed into one line reading "PackingMaterial
                        Consumption" (owner screenshot). */}
                    <Typography.Text strong style={{ display: 'block', marginTop: 8 }}>Packing</Typography.Text>

                    {/* Multi-mode packing lines. The modes come from THIS
                        batch's standard, so a tray-only product is never
                        asked about pouches. One mode is auto-selected with no
                        picker; a standard carrying both lets the supervisor
                        add the second as its own line when the run used it. */}
                    {/* Legacy path — products with no imported standard. Byte
                        for byte the pre-packing-lines field set: tray fields
                        for tray-packed items (or items with no standards at
                        all), pouch count only for pouch-packed items, Nos/Box
                        always (boxes are the universal outer). */}
                    <Row gutter={16} style={{ marginTop: 8, marginBottom: 16, display: usePackingLines ? 'none' : undefined }}>
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

                    <Typography.Text strong style={{ display: 'block', marginTop: 16 }}>Material Consumption</Typography.Text>

                    {/* Phase 6: the day-bin computed figure that prefilled the
                        Resin/MB rows below, with its formula spelled out. The
                        rows stay fully editable — this is a suggestion, and a
                        supervisor-typed value is never overwritten. */}
                    {/* Closing day-bin weights. Without these the consumption
                        formula has no closing term and consumed kg stays null,
                        which is why automatic consumption used to be blank on
                        every batch that did not hand over. */}
                    {traceabilityEnabled && entryDayBin?.has_movements && (
                        <>
                            <Typography.Text strong style={{ display: 'block', marginTop: 16 }}>
                                Left in the day bin at end of run
                            </Typography.Text>
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                Weigh what is still in the bin. Leave blank if it was not counted — a blank
                                stays &ldquo;not counted&rdquo; rather than becoming zero.
                            </Typography.Text>
                            {entryDayBin.materials.map((material, index) => (
                                <Row key={material.item.id} gutter={[8, 8]} align="middle" style={{ marginTop: 8 }}>
                                    <Col xs={14}>
                                        <Typography.Text>{material.item.name}</Typography.Text>
                                        <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                                            loaded {fmtNum(toNum(material.loaded_kg), 4)} kg
                                        </Typography.Text>
                                    </Col>
                                    <Col xs={10}>
                                        <Controller
                                            name={`closing_day_bin.${index}.quantity_kg`}
                                            control={completeForm.control}
                                            render={({ field }) => (
                                                <InputNumber
                                                    {...field}
                                                    size="large"
                                                    min={0}
                                                    style={{ width: '100%' }}
                                                    placeholder="Closing kg"
                                                    suffix="kg"
                                                    onChange={(value) => {
                                                        field.onChange(value);
                                                        // The consumed-kg prefill fires HERE — see
                                                        // applyDayBinConsumption for why an effect on
                                                        // the watched array cannot.
                                                        applyDayBinConsumption(
                                                            material,
                                                            value === null || value === undefined ? null : Number(value),
                                                        );
                                                    }}
                                                />
                                            )}
                                        />
                                    </Col>
                                </Row>
                            ))}
                        </>
                    )}

                    {traceabilityEnabled && entryDayBin?.has_movements && (
                        <Alert
                            type="info"
                            showIcon
                            style={{ marginTop: 8 }}
                            message="Prefilled from day-bin weighments — correct if wrong"
                            description={entryDayBin.materials
                                .filter((m) => m.consumption_kg !== null)
                                .map((m) => (
                                    <div key={m.item.id} style={{ fontSize: 12 }}>
                                        {m.item.sku}: <strong>{fmtNum(toNum(m.consumption_kg), 4)} kg</strong>
                                        {' '}= opening {fmtNum(toNum(m.opening_kg), 4)} + loaded {fmtNum(toNum(m.loaded_kg), 4)} − closing{' '}
                                        {m.closing_kg === null ? '—' : fmtNum(toNum(m.closing_kg), 4)} − returned {fmtNum(toNum(m.returned_kg), 4)}
                                    </div>
                                ))}
                        />
                    )}

                    {/* Fixed rows for the two materials every molding batch
                        consumes — pickers scoped to resins / masterbatches so
                        the right item is one tap, not a 642-item search. Rows
                        without a quantity are simply not sent. */}
                    {/* Where consumption comes OUT of. With a factory day bin
                        configured the rows below default to it, so completing
                        the batch reduces the bin automatically; without one,
                        one plain line says so and nothing changes. */}
                    {dayBinWarehouseId === null ? (
                        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                            No factory day bin chosen — pick the "From" warehouse yourself, as today.{' '}
                            <Link to="/production/day-bin">Choose one in Day Bin (factory)</Link>.
                        </Typography.Text>
                    ) : (
                        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                            "From" is where the material came out of. It is already set to the day bin for you —
                            change it only if this material came straight from the store.{' '}
                            <Link to="/production/day-bin">Open Day Bin (factory)</Link>
                        </Typography.Text>
                    )}

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
                                render={({ field }) => (
                                    <InputNumber
                                        {...field}
                                        size="large"
                                        min={0}
                                        placeholder="Kg"
                                        suffix="Kg"
                                        style={{ width: '100%' }}
                                        onChange={(value) => {
                                            // A manual edit wins permanently for this
                                            // batch — the auto-calculation backs off.
                                            resinKgTouchedRef.current = true;
                                            field.onChange(value);
                                        }}
                                    />
                                )}
                            />
                        </Col>
                        {resinCalcKg !== null && results && (
                            <Col xs={24}>
                                <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                    {`= ${fmtNum(results.goodKg)} production + ${fmtNum(results.rejProdKg ?? 0)} rejection + ${fmtNum(results.lumpsKg)} lumps — edit if the weighed figure differs`}
                                </Typography.Text>
                            </Col>
                        )}
                        <Col xs={24}>{dayBinHint(resinItemIdWatch, resinKgWatch, completeForm.watch('resin_warehouse_id'))}</Col>
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
                        <Col xs={24}>{dayBinHint(mbItemIdWatch, mbKgWatch, completeForm.watch('mb_warehouse_id'))}</Col>
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
                            <Col xs={24}>
                                {dayBinHint(
                                    selectedItemId,
                                    completeForm.watch(`material_consumptions.${index}.quantity_issued_kg`),
                                    completeForm.watch(`material_consumptions.${index}.warehouse_id`),
                                )}
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

                    {/* Downtime this run — power outage, mold change,
                        breakdown — each with its timing. The minutes come off
                        Running Hours before the expected output is computed,
                        so efficiency judges only the time the machine could
                        actually run ("i want to do this for efficiency"). */}
                    <Space style={{ justifyContent: 'space-between', width: '100%', marginTop: 16 }}>
                        <Typography.Text strong>Downtime</Typography.Text>
                        <Button
                            size="small"
                            onClick={() =>
                                downtimeFields.append({
                                    downtime_reason_id: undefined,
                                    from_time: '',
                                    to_time: '',
                                    note: undefined,
                                })
                            }
                        >
                            Add Downtime
                        </Button>
                    </Space>
                    <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                        Power outage, mold change, breakdown — with from/to times. These minutes come off the
                        running hours before efficiency is judged.
                    </Typography.Text>
                    {downtimeFields.fields.map((field, index) => {
                        const lineErrors = completeForm.formState.errors.downtime_events?.[index];
                        const minutes = downtimeLineMinutes(
                            completeForm.watch(`downtime_events.${index}.from_time`),
                            completeForm.watch(`downtime_events.${index}.to_time`),
                        );
                        return (
                            <Row key={field.id} gutter={[8, 8]} align="top" style={{ marginTop: 8 }}>
                                <Col xs={24} sm={9}>
                                    <Form.Item
                                        style={{ marginBottom: 0 }}
                                        validateStatus={lineErrors?.downtime_reason_id ? 'error' : ''}
                                        help={lineErrors?.downtime_reason_id?.message}
                                    >
                                        <Controller
                                            name={`downtime_events.${index}.downtime_reason_id`}
                                            control={completeForm.control}
                                            render={({ field }) => (
                                                <Select
                                                    {...field}
                                                    size="large"
                                                    options={downtimeReasonOptions}
                                                    showSearch
                                                    optionFilterProp="label"
                                                    allowClear
                                                    style={{ width: '100%' }}
                                                    placeholder="Reason…"
                                                />
                                            )}
                                        />
                                    </Form.Item>
                                </Col>
                                <Col xs={8} sm={4}>
                                    <Form.Item
                                        style={{ marginBottom: 0 }}
                                        validateStatus={lineErrors?.from_time ? 'error' : ''}
                                        help={lineErrors?.from_time?.message}
                                    >
                                        <Controller
                                            name={`downtime_events.${index}.from_time`}
                                            control={completeForm.control}
                                            render={({ field }) => (
                                                <TimePicker
                                                    size="large"
                                                    format="HH:mm"
                                                    placeholder="From"
                                                    style={{ width: '100%' }}
                                                    value={field.value ? dayjs(field.value, 'HH:mm') : null}
                                                    onChange={(_, timeString) =>
                                                        field.onChange((Array.isArray(timeString) ? timeString[0] : timeString) || '')
                                                    }
                                                />
                                            )}
                                        />
                                    </Form.Item>
                                </Col>
                                <Col xs={8} sm={4}>
                                    <Form.Item
                                        style={{ marginBottom: 0 }}
                                        validateStatus={lineErrors?.to_time ? 'error' : ''}
                                        help={lineErrors?.to_time?.message}
                                    >
                                        <Controller
                                            name={`downtime_events.${index}.to_time`}
                                            control={completeForm.control}
                                            render={({ field }) => (
                                                <TimePicker
                                                    size="large"
                                                    format="HH:mm"
                                                    placeholder="To"
                                                    style={{ width: '100%' }}
                                                    value={field.value ? dayjs(field.value, 'HH:mm') : null}
                                                    onChange={(_, timeString) =>
                                                        field.onChange((Array.isArray(timeString) ? timeString[0] : timeString) || '')
                                                    }
                                                />
                                            )}
                                        />
                                    </Form.Item>
                                </Col>
                                <Col xs={8} sm={4} style={{ alignSelf: 'center', textAlign: 'center' }}>
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        {minutes !== null ? `${minutes} min` : ''}
                                    </Typography.Text>
                                </Col>
                                <Col xs={24} sm={3}>
                                    <Button danger block onClick={() => downtimeFields.remove(index)}>Remove</Button>
                                </Col>
                                <Col xs={24}>
                                    <Controller
                                        name={`downtime_events.${index}.note`}
                                        control={completeForm.control}
                                        render={({ field }) => <Input {...field} maxLength={255} placeholder="Note (optional)" />}
                                    />
                                </Col>
                            </Row>
                        );
                    })}
                    {/* A reason missing from the list is typed once, saved to
                        the GLOBAL list, and auto-picked — "once saved
                        globally we can take it here". */}
                    <Space.Compact style={{ width: '100%', marginTop: 8 }}>
                        <Input
                            value={newDowntimeReasonText}
                            onChange={(e) => setNewDowntimeReasonText(e.target.value)}
                            placeholder="Reason not in the list? Type it here…"
                            maxLength={120}
                        />
                        <Button
                            loading={createDowntimeReasonMutation.isPending}
                            disabled={newDowntimeReasonText.trim() === ''}
                            onClick={() => createDowntimeReasonMutation.mutate(newDowntimeReasonText.trim())}
                        >
                            Save reason
                        </Button>
                    </Space.Compact>

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
                                        results.downtimeMinutes > 0
                                            ? ` (${fmtNum(results.grossHours)} h − ${results.downtimeMinutes} min downtime)`
                                            : ''
                                    }${
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
                                    // Pieces, not boxes: boxes-vs-boxes compounds two
                                    // roundings and drops the loose pieces entirely.
                                    formula={`${(results.actualPieces ?? 0).toLocaleString('en-IN')} pcs ÷ ${fmtNum(
                                        results.expected?.pieces ?? null,
                                        0,
                                    )} expected × 100${
                                        results.downtimeMinutes > 0
                                            ? ` — ${fmtNum(results.hours)} h net of ${results.downtimeMinutes} min downtime`
                                            : ''
                                    }`}
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

            {/* Phase 6 traceability surfaces — mounted only when the backend
                flag is on; with it off the tree is identical to today's. */}
            {traceabilityEnabled && (
                <>
                    <DayBinDrawer
                        workCenter={dayBinTarget?.workCenter ?? null}
                        entry={dayBinTarget?.entry ?? null}
                        open={dayBinTarget !== null}
                        onClose={() => setDayBinTarget(null)}
                    />
                    <HandoverModal
                        entry={handoverEntry}
                        incomingShift={effectiveShift}
                        productionDate={today}
                        onClose={() => setHandoverEntry(null)}
                        onDone={() => {
                            // The new segment appears as the running batch via the
                            // shared entry list (highest id per machine wins).
                            invalidate();
                            queryClient.invalidateQueries({ queryKey: ['production', 'day-bin'] });
                            setHandoverEntry(null);
                        }}
                    />
                    {/* Central Load Material — stays open between bags so a
                        stack can be scanned one after another; footer is our
                        own Load button because OK-that-closes would end the
                        scanning session after every bag. */}
                    <Modal
                        maskClosable={false}
                        title="Load Material"
                        open={loadMaterialOpen}
                        onCancel={() => setLoadMaterialOpen(false)}
                        afterOpenChange={(open) => {
                            if (open) loadBagInputRef.current?.focus();
                        }}
                        footer={null}
                        destroyOnHidden
                    >
                        <Typography.Paragraph type="secondary">
                            Central load for every machine: scan a bag and its kg move from the store into the
                            factory day bin. The scanner types the code and presses Enter by itself.
                        </Typography.Paragraph>
                        {loadBagSuccess && (
                            <Alert type="success" showIcon message={loadBagSuccess} style={{ marginBottom: 12 }} />
                        )}
                        {loadBagError && (
                            <Alert
                                type={loadBagError.needsWarehouse ? 'warning' : 'error'}
                                showIcon
                                style={{ marginBottom: 12 }}
                                message={loadBagError.text}
                                description={
                                    loadBagError.needsWarehouse ? (
                                        <Link to="/production/day-bin">Open the Day Bin page to choose the warehouse</Link>
                                    ) : undefined
                                }
                            />
                        )}
                        <Form layout="vertical">
                            <Form.Item label="Bag barcode">
                                <Input
                                    ref={loadBagInputRef}
                                    autoFocus
                                    value={loadBagBarcode}
                                    onChange={(e) => setLoadBagBarcode(e.target.value)}
                                    onPressEnter={submitLoadBagBarcode}
                                    placeholder="Scan or type the bag barcode, then Enter"
                                />
                            </Form.Item>
                            {bagLookupMutation.isPending && (
                                <Typography.Paragraph type="secondary">Looking up the bag…</Typography.Paragraph>
                            )}
                            {scannedLoadBag && (
                                <>
                                    <Descriptions size="small" column={1} bordered style={{ marginBottom: 12 }}>
                                        <Descriptions.Item label="Material">
                                            {scannedLoadBag.lot?.item ? itemLabel(scannedLoadBag.lot.item) : '—'}
                                        </Descriptions.Item>
                                        <Descriptions.Item label="Bag">{scannedLoadBag.barcode}</Descriptions.Item>
                                        <Descriptions.Item label="Remaining in bag (kg)">
                                            {scannedLoadBag.remaining_kg}
                                        </Descriptions.Item>
                                    </Descriptions>
                                    <Form.Item label="Kg to load" extra="The whole bag unless you lower it for a part bag.">
                                        <InputNumber
                                            min={0.001}
                                            max={Number(scannedLoadBag.remaining_kg)}
                                            value={loadBagKg}
                                            onChange={(value) => setLoadBagKg(value)}
                                            style={{ width: '100%' }}
                                        />
                                    </Form.Item>
                                </>
                            )}
                            <Form.Item
                                label="Supervisor"
                                extra={loadBagUsersUnavailable ? 'User list unavailable for this login — recorded as you.' : undefined}
                            >
                                <Select
                                    value={loadBagSupervisorId ?? currentUser?.id}
                                    onChange={(value) => setLoadBagSupervisorId(value)}
                                    options={loadBagSupervisorOptions}
                                    showSearch
                                    optionFilterProp="label"
                                />
                            </Form.Item>
                            <Button
                                type="primary"
                                block
                                onClick={submitLoadBag}
                                loading={loadBagMutation.isPending}
                                disabled={!scannedLoadBag || !loadBagKg || loadBagKg <= 0}
                            >
                                Load into Day Bin
                            </Button>
                        </Form>
                    </Modal>
                </>
            )}
        </>
    );
}
