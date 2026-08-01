import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, Checkbox, Col, Descriptions, Drawer, Form, Input, InputNumber, type InputRef, message, Modal, Radio, Row, Select, Space, Table, Tag, TimePicker, Tooltip, Typography } from 'antd';
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
import HandoverModal from '@/features/production/components/HandoverModal';
import {
    amendBatch,
    listPendingEntries,
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
    listMasterbatchDosings,
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
    machineLabel,
    getFactoryWarehouseSettings,
    openDowntimeLog,
    openMoldChangeLog,
    getBatchPreview,
    factoryStoreLabel,
    resolveFactoryStore,
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
    SuggestedMaterial,
    SuggestedPackingMaterial,
    WorkCenter,
} from '@/features/production/types';
import {
    canAmendCompletion,
    isAwaitingCorrection,
    readReturnReason,
    readStockShortfalls,
} from '@/features/production/types';
import { currentShift, justEndedShift, productionDateFor } from '@/features/production/shiftClock';
import { roundPer, useProductionSettings } from '@/features/production/packing';
import { itemLabel } from '@/lib/itemLabel';
import {
    buildStartBatchStandardUrl,
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

/** Whole pieces with Indian grouping — "12,500". "—" when there is no figure. */
function fmtPieces(v: string | number | null | undefined): string {
    const n = typeof v === 'number' ? v : toNum(v as string | null | undefined);
    return n === null || Number.isNaN(n) ? '—' : n.toLocaleString('en-IN');
}

/**
 * The one breakpoint this screen changes shape at.
 *
 * The rest of the page is laid out with antd's own responsive Col spans, which
 * need no JS. The Completed Today list is the exception: below this width it
 * stops being a table at all and becomes cards, and a component cannot render
 * two different DOM shapes off a CSS media query alone. 768px is antd's own
 * `md` boundary, so the switch happens on the same line as every Col around it.
 *
 * Subscribes rather than reading once — a tablet rotated mid-shift is a real
 * event on this floor, and a list that keeps yesterday's shape until reload is
 * exactly the horizontal-scroll problem this exists to end.
 */
const NARROW_QUERY = '(max-width: 767px)';

function useIsNarrowScreen(): boolean {
    const [narrow, setNarrow] = useState<boolean>(
        () => typeof window !== 'undefined' && typeof window.matchMedia === 'function'
            ? window.matchMedia(NARROW_QUERY).matches
            : false,
    );

    useEffect(() => {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
        const mql = window.matchMedia(NARROW_QUERY);
        const onChange = (event: MediaQueryListEvent) => setNarrow(event.matches);
        setNarrow(mql.matches);
        mql.addEventListener('change', onChange);
        return () => mql.removeEventListener('change', onChange);
    }, []);

    return narrow;
}

/**
 * A completion-time downtime note, read back into the from/to boxes it was
 * typed in.
 *
 * The events table has no clock columns — the drawer folds the picked window
 * into the note ("14:30–15:00 — power cut") and the minutes are what is
 * stored. An amendment re-books the completion from the form, so a line that
 * cannot be read back would be silently dropped from the corrected batch.
 * Anything that does not match the shape it was written in keeps its whole
 * text as the note, and the amender re-picks the window rather than losing
 * the line.
 */
function parseDowntimeNote(note: string | null): { from?: string; to?: string; text: string } {
    const raw = (note ?? '').trim();
    const match = /^(\d{1,2}:\d{2})[–-](\d{1,2}:\d{2})(?:\s+—\s+(.*))?$/.exec(raw);
    if (!match) return { text: raw };
    return { from: match[1], to: match[2], text: (match[3] ?? '').trim() };
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

/**
 * The inner container's singular. Spelled out rather than sliced off the
 * plural — "pouches" minus its "s" is "pouche", which is not a word anyone
 * on the floor would recognise on a label that says "Pcs per pouch".
 */
function innerNounOne(mode: PackingMode): string | null {
    return mode === 'pouch' ? 'pouch' : mode === 'tray' ? 'tray' : null;
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

/**
 * Trays (or pouches) that make one carton — the single figure that lets the
 * floor count CARTONS and have the trays follow. "5 tray = 1 carton boxes,
 * then 600 units based on 120 PER TRAY, SO FIVE TRAY SO 600" is still the
 * arithmetic, read from the carton end: this is the 5 in it, and it is also
 * the step that says 7 loose trays are really one more carton plus 2.
 *
 * Returned ONLY when the imported standard reconciles with itself:
 * inners × pcs/inner must come to exactly pcs/carton. Deriving it by
 * dividing the standard's own figures is arithmetic, NOT the assumed
 * 5-per-box this file has always refused — 600 pcs/carton at 120 pcs/tray
 * IS five trays, whatever the sheet's trays_per_box column forgot to say.
 * But when the sheet states a different figure, or the carton is not a
 * whole number of trays, this returns null and the line carries no tray
 * arithmetic at all: a tray figure the standard itself contradicts would
 * show trays the factory never packed.
 */
function innersPerCarton(packaging: StandardPackaging): number | null {
    const perBox = packaging.nos_per_box;
    const perInner = innerPackSize(packaging);
    if (!perBox || !perInner || perBox % perInner !== 0) return null;
    const derived = perBox / perInner;
    const stated = innersPerBox(packaging);
    if (stated !== null && stated > 0 && stated !== derived) return null;
    return derived;
}

interface PackingLineValues {
    mode: PackingMode;
    production_standard_packaging_id?: number | null;
    boxes?: number | null;
    loose_inner?: number | null;
    nos_per_box?: number | null;
    nos_per_inner?: number | null;
    /**
     * Trays/pouches per carton, fixed for the line from the standard (see
     * innersPerCarton). Set = the line carries the tray arithmetic: trays are
     * derived from the cartons typed, and loose trays that reach this figure
     * fold into another carton. Null = the line is cartons and pieces only.
     * Never sent to the server — boxes and loose_inner already carry the
     * split, which is exactly what the API contract asks for.
     */
    inners_per_box?: number | null;
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
        inners_per_box: innersPerCarton(packaging),
        actual_pieces: null,
        override_reason: undefined,
    };
}

/**
 * How many trays/pouches make a carton on this line, or null when the line
 * has no tray arithmetic to do (direct-to-box, or a standard whose carton is
 * not a whole number of inner containers).
 *
 * This is the ONE gate for the tray side of a box-first line: with it set the
 * supervisor types CARTONS, the trays are derived from them, and loose trays
 * that reach it fold into another carton; without it, nothing about the line
 * changes from how it has always worked.
 */
function boxFirstStep(line: PackingLineValues | undefined): number | null {
    if (!line || innerNoun(line.mode) === null) return null;
    const step = line.inners_per_box ?? null;
    return step !== null && step >= 1 ? step : null;
}

/**
 * The trays/pouches a line holds, for the box the floor types into. Null
 * until something is entered, so a fresh line shows an empty field rather
 * than a zero that looks like a counted nothing.
 */
function lineInnerCount(line: PackingLineValues, step: number): number | null {
    if ((line.boxes ?? null) === null && (line.loose_inner ?? null) === null) return null;
    return (line.boxes ?? 0) * step + (line.loose_inner ?? 0);
}

/** "1 carton", "2 cartons", "1 carton + 2 trays over" — how the floor says it. */
function cartonSummary(boxes: number, over: number, one: string, many: string): string {
    const cartons = `${boxes} ${boxes === 1 ? 'carton' : 'cartons'}`;
    if (over <= 0) return cartons;
    return `${cartons} + ${over} ${over === 1 ? one : many} over`;
}

// Structural (sku+name) so both full Items and the day-bin aggregates'
// item-lite slices ({id, name, sku}) classify the same way.
const isMasterbatchItem = (item: Pick<Item, 'sku' | 'name'>): boolean => /master ?batch/i.test(`${item.sku} ${item.name}`);
// The whole raw-material family, not just the word "resin" — this factory's
// own Tally books (Transactions.xml, July 2026) name the PET raw material
// "PET Polyster Chips" and "Relpet", neither of which contains "resin",
// "polymer" or an adjacent "pet chip". Both spellings are matched literally:
// a generic pattern already missed them once, and the cost of that miss was
// an empty Resin picker on every completion of go-live morning.
const isResinItem = (item: Pick<Item, 'sku' | 'name'>): boolean =>
    /resin|granul|polym|poly\s*e?ster|relpet|pet\s*(chip|raw)|\bchips\b/i.test(`${item.sku} ${item.name}`);
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
 * What a fixed consumption row (Resin, Masterbatch) arrives holding: the
 * material, already chosen, and the one-line reason it was chosen. `itemId`
 * null with a `reason` set is a real answer too — "two masterbatches match
 * Amber" is worth saying, and is far better than pre-selecting the wrong one.
 */
type FixedRowPick = { itemId: number | null; reason: string | null };

const NO_PICK: FixedRowPick = { itemId: null, reason: null };

/**
 * The preview's pre-chosen material, read in ONE place.
 *
 * Tolerant by design: the material may arrive as `item: {id}` or as a bare
 * `item_id`, and grams as a decimal string or a number. The cost of guessing
 * the wrong key is an empty picker on the floor — the very defect this reads
 * away — so both spellings are accepted rather than assumed.
 */
function readSuggestion(raw: SuggestedMaterial | null | undefined): FixedRowPick & { grams: number | null } {
    if (!raw) return { ...NO_PICK, grams: null };
    const itemId = raw.item?.id ?? raw.item_id ?? null;
    const rawGrams = raw.grams_per_bottle;
    const grams = typeof rawGrams === 'number' ? rawGrams : toNum(rawGrams ?? null);
    return {
        itemId: itemId ?? null,
        // A non-positive or unreadable figure is not a dosing — same rule the
        // backend applies before it will compute a kg from one.
        grams: grams !== null && Number.isFinite(grams) && grams > 0 ? grams : null,
        reason: (raw.reason ?? '').trim() || null,
    };
}

// ---- Packing consumption: reading the mapping off the wire ----------------
// Which of the drawer's own counts a packing line is multiplied by. `null` is
// a real value: a material whose mapping states no basis is shown, named, and
// left for the supervisor to fill — never multiplied by a count nobody chose.
type PackingBasis = 'carton' | 'tray' | 'pouch' | 'bottle';
type PackingKind = 'carton' | 'tray' | 'film' | 'tape' | 'other';

/** One packing material, normalised — the shape the drawer computes from. */
type PackingSuggestion = {
    /** Stable across a preview refetch: what a supervisor's edit is keyed to. */
    key: string;
    kind: PackingKind;
    label: string;
    itemId: number | null;
    itemName: string | null;
    /** The unit the quantity is counted in, as printed beside the box. */
    unit: string;
    basis: PackingBasis | null;
    /** The word for the count in the arithmetic line — "cartons", "trays". */
    basisWord: string | null;
    /** How much one carton (or tray) takes. Null = the mapping does not say. */
    perUnit: number | null;
    /** Film only — grams a piece weighs, which turns pieces into kg. */
    gramsPerPiece: number | null;
    spec: string | null;
    reason: string | null;
    /**
     * May this row's quantity be FILED — sent as a consumption line, issued
     * against a store, carried on a Tally voucher — or is it shown for the
     * record only? The backend's answer, obeyed rather than re-derived here.
     * See readPackingSuggestions() for why it is never worked out on this side.
     */
    submitAsStock: boolean;
};

/** A wire figure that may arrive as a decimal string, as a number, or not at all. */
function wireNum(value: string | number | null | undefined): number | null {
    const n = typeof value === 'number' ? value : toNum(value ?? null);
    return n !== null && Number.isFinite(n) ? n : null;
}

/** A wire string, trimmed, with the empty string read as "not sent". */
function wireText(value: string | null | undefined): string | null {
    const text = (value ?? '').trim();
    return text === '' ? null : text;
}

/**
 * WHICH MATERIAL a mapping row is, from the backend's own `kind` and from
 * nothing else.
 *
 * The four values PackingMaterialMapping::KIND_* can take are `carton`,
 * `tray`, `pouch_film` and `tape` — POUCH_FILM being the one that does not
 * spell the way the drawer names it. They are matched as whole words rather
 * than guessed at from the item's name: the kind decides which of the drawer's
 * counts multiplies the line, so a row misread as a tray books a tray count
 * against a carton, and an item name is not evidence of what the mapping meant.
 * An unrecognised kind is "other" — shown, named, and multiplied by nothing.
 */
function packingKindOf(raw: SuggestedPackingMaterial): PackingKind {
    switch ((wireText(raw.kind) ?? '').toLowerCase()) {
        case 'carton':
            return 'carton';
        case 'tray':
            return 'tray';
        case 'pouch_film':
            return 'film';
        case 'tape':
            return 'tape';
        default:
            return 'other';
    }
}

/**
 * The unit as the floor writes it. The mapping states units lowercase ("nos",
 * "kg", "m"); the drawer's other quantity boxes are suffixed "Kg" and "g", and
 * one row reading "24 nos" beside another reading "3.33 Kg" looks like two
 * different screens. Anything unrecognised is printed exactly as sent.
 */
function packingUnitLabel(unit: string): string {
    const key = unit.trim().toLowerCase();
    if (key === 'nos' || key === 'no' || key === 'nos.') return 'Nos';
    if (key === 'kg' || key === 'kgs' || key === 'kgs.') return 'Kg';
    return unit.trim();
}

/**
 * Does this material's own UOM put it in the KILOGRAM family — i.e. may its
 * quantity be added to a figure printed in kg?
 *
 * Mirrors the backend's isMassUom() exactly, and it has to: the pre-submit
 * memo is a preview of numbers the server is about to compute, and a preview
 * that answers a different question is worse than no preview. Lowercased,
 * trailing dot stripped, because Tally's masters spell it "Kgs." on 90+ live
 * items and an un-normalised compare drops every one of them out of the kg
 * sums.
 *
 * Blank/unknown counts as kg, deliberately — the same fail-safe direction the
 * server takes (ConsumptionVarianceTest: "a consumption line whose master has
 * no uom counts as kg"). Silently dropping a real resin line from the
 * reconciliation is the worse of the two wrong answers.
 */
function isKgFamilyUom(uom: string | null | undefined): boolean {
    const raw = (uom ?? '').trim();
    if (raw === '') return true;

    return ['kg', 'kgs', 'kilogram', 'kilograms'].includes(raw.toLowerCase().replace(/\.$/, ''));
}

/**
 * The stated basis, or the one this kind is counted against by settled fact.
 *
 * The backend states `per_carton` / `per_tray`; the fallbacks below cover only
 * the case of a row whose kind is recognised and whose basis is absent, which
 * is why they name the settled facts rather than repeat the wire's vocabulary.
 */
function packingBasisOf(raw: SuggestedPackingMaterial, kind: PackingKind): PackingBasis | null {
    const stated = (wireText(raw.basis) ?? '').toLowerCase();
    if (/carton|box/.test(stated)) return 'carton';
    if (/tray/.test(stated)) return 'tray';
    if (/pouch/.test(stated)) return 'pouch';
    if (/bottle|piece|\bnos?\b/.test(stated)) return 'bottle';
    if (kind === 'tray') return 'tray';
    // Cartons, film and tape all count CARTONS: a carton is one per carton, and
    // the owner settled 31 Jul that one film wraps a carton's contents and that
    // tape is dosed in metres PER BOX.
    if (kind === 'carton' || kind === 'film' || kind === 'tape') return 'carton';
    return null;
}

/**
 * The preview's packing mapping, read in ONE place — the same contract as
 * `readSuggestion` above and for the same reason: an absent block is silence
 * rather than an error, and every field the wire carries is read here and
 * nowhere else, so a rename on the backend costs one line in one function.
 *
 * WHAT THE WIRE DOES NOT CARRY is worth naming, because an earlier draft of
 * this function read for all of it: there is no per-row warehouse (the mapping
 * has no such column — the supervisor names the packing store), no `label` (the
 * kind supplies the heading), and no provenance object (an inferred spec is
 * appended to `reason` by the backend's own inferredNote()).
 *
 * TAPE IS QUOTED IN METRES, deliberately, even though this factory's Tally
 * books count "Packing Tape - Transparent" in Nos. Metres per box is the figure
 * the factory actually gave (the 13-row table), and whether a Tally "No" is one
 * metre or one strip is STILL OPEN with the owner.
 *
 * SHOWN WITH ITS UNIT STATED, AND — until that question is answered — NOT
 * SUBMITTED. Stating the unit on screen was never enough on its own: the
 * completion filed the metres as an ordinary consumption line anyway, so 100
 * cartons of 170ML issued 229 "Nos" of tape against an item Tally counts in
 * pieces and posted it to the live books. `submit_as_stock` is the backend's
 * ruling on that, per row, and it is READ HERE AND OBEYED — never re-derived.
 * The rule (which kinds, which unit families, what the factory has answered)
 * belongs in one place, and that place is PackingMaterialSuggestionService;
 * a second copy of it on this side is a second thing to get wrong.
 *
 * An ABSENT flag reads as `true`, which is deliberate and not a hedge. This
 * frontend builds into `backend/public/build/` and ships with the API that
 * serves it, so a preview without the field is one that predates the packing
 * rows entirely — and defaulting tape to false there would be exactly the
 * re-derivation the flag exists to prevent.
 */
function readPackingSuggestions(raw: SuggestedPackingMaterial[] | null | undefined): PackingSuggestion[] {
    if (!Array.isArray(raw)) return [];
    return raw.map((row, index): PackingSuggestion => {
        const kind = packingKindOf(row);
        const basis = packingBasisOf(row, kind);
        const itemId = row.item?.id ?? null;
        const spec = wireText(row.spec);
        const statedUnit = wireText(row.unit);
        const factor = wireNum(row.factor);
        // A FACTOR QUOTED IN GRAMS IS A WEIGHT, NOT A COUNT. The mapping states
        // the film as grams per piece with the quantity in kg (factor 120,
        // factor_unit "g", unit "kg" = 0.12 kg a carton), and reading that 120
        // as pieces per carton would book a thousand times the film that went
        // in. `factor_unit` is the only thing that tells the three factors
        // apart, so it is read rather than assumed from the kind.
        const factorIsGrams = /^g(ram)?s?$/i.test(wireText(row.factor_unit) ?? '');
        const gramsPerPiece = factorIsGrams ? factor : null;
        const perUnitStated = factorIsGrams ? null : factor;
        // Widened locally rather than in the shared `SuggestedPackingMaterial`
        // type: this is the only reader of the field, and the wire type is
        // shared with screens that have no business knowing about it.
        const submitAsStock = (row as SuggestedPackingMaterial & { submit_as_stock?: boolean | null })
            .submit_as_stock;
        return {
            // The wire carries no row id — a suggestion is computed, not stored
            // — so identity is the kind, the item and the spec. Stable across a
            // refetch, and distinct between the carton row and the tape row
            // that share a carton spec because the kind leads.
            key: `${kind}:${itemId ?? 'unmapped'}:${spec ?? index}`,
            kind,
            label: { carton: 'Carton', tray: 'Tray', film: 'Film', tape: 'Tape', other: 'Packing material' }[kind],
            itemId,
            itemName: wireText(row.item?.name),
            // The wire's unit, always — it is the unit the wire's own FACTOR is
            // denominated in, and the two cannot be allowed to part company.
            //
            // Tape used to be pinned to metres here unless the wire said
            // "metres" in so many words. That was right while metres were the
            // only thing a tape row could carry, and wrong the moment the
            // backend could convert one: a converted row arrives as a per-No
            // factor with unit "nos", and forcing "m" over it would print
            // rolls under a metre sign. The backend states one unit per row and
            // this reads it; 'm' remains the fallback for a tape row whose
            // preview said nothing at all.
            unit: packingUnitLabel(
                statedUnit ?? (kind === 'tape' ? 'm' : gramsPerPiece !== null ? 'kg' : 'nos'),
            ),
            basis,
            basisWord: wireText(row.quantity_basis),
            // One per container is what a carton and a tray are.
            //
            // A film is one per carton TOO — but only once its per-piece weight
            // is known, and that is not pedantry. The mapping quotes film in
            // KILOGRAMS off a grams figure, and its grams column is nullable:
            // an unweighed film arrives as factor null with unit still "kg".
            // Defaulting the count to 1 there would put 24 CARTONS in a
            // kilogram box, read "24 Kg" on screen, and — being mapped and
            // nonzero — issue 24 kg of film against a real warehouse. So a
            // mass-quoted row with no weight behind it computes nothing and
            // says so, exactly as an unmapped tape does.
            //
            // Tape has no default for the same reason: its metres come from the
            // factory's own table through the mapping, and inventing one would
            // invent a consumption figure nobody stated.
            perUnit:
                perUnitStated ??
                (kind === 'carton' || kind === 'tray' || (kind === 'film' && gramsPerPiece !== null) ? 1 : null),
            gramsPerPiece,
            spec,
            // The backend's sentence, which already carries the "spec inferred
            // from row N" note when the workbook cell was filled rather than
            // stated — there is no separate provenance object on this wire.
            reason: wireText(row.reason),
            // Only an explicit `false` withholds a line. Anything else — true,
            // absent, null on an older payload — files it, which is how carton,
            // tray and film keep behaving exactly as they did.
            submitAsStock: submitAsStock !== false,
        };
    });
}

/**
 * Masterbatch chosen from the RUN'S COLOUR, matched against the item's own
 * derived `colour` COLUMN — and against nothing else.
 *
 * A MASTERBATCH NAME IS NEVER READ HERE, deliberately, and this is the whole
 * point of the function. This factory's catalogue defeats a colour-word scan
 * outright: "Masterbatch -Red(Brown)" names two colours, and "ARIHANT PET
 * WHITE 1020 Master Batch" buries its colour between a brand and a grade
 * number. Falling through to `name.includes(colour)` and taking the first hit
 * is exactly what put a WHITE masterbatch on a non-white run in the owner's
 * screenshot; the rung has been removed rather than made cleverer, because a
 * wrong pre-selection is worse than an empty box — it books the wrong material
 * to Tally and looks like it was checked. The backend's own resolver draws the
 * same line (RunMaterialSuggestionService), and the factory's answer for a
 * colour the column cannot express belongs in the `masterbatch_colour_map`
 * factory setting, which is data a person can fix.
 *
 * Only ever pre-selects when the colour column gives ONE answer. Two matches
 * pre-select nothing and say so.
 *
 * The backend's own `suggested_masterbatch` outranks this — this is the
 * fallback for a backend that does not send one yet.
 */
function suggestMasterbatchByColour(items: Item[] | undefined, colour: string | null | undefined): FixedRowPick {
    if (!items || !colour || isClearColour(colour)) return NO_PICK;
    const c = colour.trim().toLowerCase();
    if (c === '') return NO_PICK;
    // isMasterbatchItem is a FAMILY test ("master batch"), not a colour test —
    // it narrows the pool to colourants and never chooses between them.
    const mbs = items.filter((i) => i.is_active && isMasterbatchItem(i));
    const byColour = mbs.filter((i) => (i.colour ?? '').trim().toLowerCase() === c);
    if (byColour.length === 1) return { itemId: byColour[0].id, reason: `matched to the bottle's colour (${colour})` };
    if (byColour.length > 1) {
        return { itemId: null, reason: `${byColour.length} masterbatches match ${colour} — pick the one that went in` };
    }
    return NO_PICK;
}

/**
 * The ceiling on efficiency — where the screen stops reporting a grade and
 * starts asking a question.
 *
 * Efficiency is actual pieces ÷ the pieces the STANDARD cycle time says the
 * machine could have made in those hours. A machine cannot beat its own
 * standard, so anything above 100 means one of the inputs is wrong — the
 * produced count, the running hours, the cavities — or the standard cycle
 * time is set slower than the machine really runs.
 *
 * Compared with `>`, never `>=`, mirroring the backend: both sides of the
 * ratio are rounded to 1dp, so a dead-on run showing 100.0 is the standard
 * being met, not beaten, and must not raise the alarm.
 *
 * FALLBACK VALUE ONLY. The live threshold is backend
 * `production.tolerances.efficiency_over`, served by /production/settings and
 * read below — the same reason packing_rounding is read rather than assumed:
 * this panel and the backend must never disagree about the same batch, and a
 * deployment that later allows a small measurement margin must not leave this
 * screen shouting at runs the backend calls fine. 100 is what that config
 * defaults to, and what is used while settings load or against a backend too
 * old to send it.
 */
const EFFICIENCY_CEILING_PCT = 100;

/** True when a percentage has crossed the ceiling and needs querying. */
const isOverStandard = (pct: number | null | undefined, ceiling = EFFICIENCY_CEILING_PCT) =>
    pct !== null && pct !== undefined && pct > ceiling;

const efficiencyTag = (pct: number | null, ceiling = EFFICIENCY_CEILING_PCT) => {
    if (pct === null) return null;
    // Checked BEFORE the bands: 107% is >= 95 and would otherwise be painted
    // green "OK", which is how an impossible figure got signed off unnoticed.
    if (isOverStandard(pct, ceiling)) return <Tag color="red">Over 100%</Tag>;
    if (pct >= 95) return <Tag color="green">OK</Tag>;
    if (pct >= 85) return <Tag color="orange">Watch</Tag>;
    return <Tag color="red">Investigate</Tag>;
};

/**
 * "12.2 s · cavities: 5" — the frozen Start Batch standard that every expected
 * figure in the completion drawer is computed from, so the supervisor can see
 * what the machine is being measured against instead of inferring it.
 *
 * Null (renders nothing) when the batch carries no standard cycle time: a
 * dash here would read as "the standard is zero" rather than "never set".
 * `activeCavities` is the live form value, so the line never contradicts the
 * Active Cavities box a few rows below it.
 */
function standardBasisText(entry: ShiftProductionEntry, activeCavities: number | null): string | null {
    const ct = toNum(entry.standard_cycle_time);
    if (ct === null) return null;
    const standard = entry.standard_cavities;
    const parts = [`${fmtNum(ct)} s`];
    if (standard !== null) {
        parts.push(
            activeCavities !== null && activeCavities !== standard
                ? `cavities: ${standard} standard, ${activeCavities} running`
                : `cavities: ${standard}`,
        );
    } else if (activeCavities !== null) {
        parts.push(`cavities: ${activeCavities} running`);
    }
    return parts.join(' · ');
}

/**
 * The completion drawer's header line: what this batch is being measured
 * against, stated before anything is typed. The owner's question — "how can
 * efficiency be more than 100%" — is unanswerable on a screen that never shows
 * the cycle time the expectation came from. Renders nothing for a batch with
 * no standard, where the expected figures are dashes anyway.
 */
function StandardBasisLine({ entry, activeCavities }: { entry: ShiftProductionEntry; activeCavities: number | null }) {
    const text = standardBasisText(entry, activeCavities);
    if (text === null) return null;
    return (
        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginBottom: 12 }}>
            Standard cycle time:{' '}
            <Typography.Text strong style={{ fontSize: 12 }}>
                {text}
            </Typography.Text>{' '}
            — every expected figure below is computed from it.
        </Typography.Text>
    );
}

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
    // Filled by the screen from the resolved factory store, never typed and
    // never picked — so it is optional HERE, where a required rule could only
    // ever fail on a field the supervisor cannot see. The dialog states which
    // store it resolved, or says plainly that it could not; the server still
    // validates whatever is sent.
    warehouse_id: z.number().nullish(),
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
    //
    // THREE boxes each: the material, its grams per bottle, and the total kg.
    // `*_grams_per_bottle` is an ENTRY AID and is never submitted — it is what
    // the total kg is computed from, and the total kg is the figure that is
    // stored and that Tally receives. Both numeric boxes are editable and a
    // supervisor's edit to either one wins.
    // No `resin_warehouse_id` / `mb_warehouse_id`: these two rows are issued
    // from wherever the material actually is, which the server decides. Keeping
    // the fields would have left two form values nothing fills and nothing
    // reads, waiting to be wired back into a payload by someone reading this
    // schema as the contract.
    resin_item_id: z.number().nullish(),
    resin_grams_per_bottle: z.number().min(0).nullish(),
    resin_kg: z.number().min(0).nullish(),
    mb_item_id: z.number().nullish(),
    mb_grams_per_bottle: z.number().min(0).nullish(),
    mb_kg: z.number().min(0).nullish(),
    helper_name: z.string().max(120, 'Max 120 characters').optional(),
    notes: z.string().optional(),
    // Only ever filled on an amendment, and optional there too — the backend's
    // own rule (AmendBatchRequest): a supervisor fixing their own typo on
    // their own batch, before anyone else has seen it, is not asked to justify
    // it. It is destructured out of an ordinary completion's payload.
    amendment_reason: z.string().max(500, 'Max 500 characters').optional(),
    // No warehouse on an exception line. It was required here, and with the
    // picker gone that rule would have failed every added row on a field the
    // supervisor cannot see or fill — a drawer that refuses to submit and
    // cannot say why. The server resolves the source per line instead.
    material_consumptions: z
        .array(
            z.object({
                item_id: z.number({ error: 'Item is required' }),
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
                // Declared so it survives parsing — zod strips unknown keys
                // before the refinements below run, and this is what tells
                // them the line's trays derive from its carton count.
                inners_per_box: z.number().int().min(1, 'At least 1').nullish(),
                actual_pieces: z.number().int().min(0, 'Cannot be negative').nullish(),
                override_reason: z.string().max(255, 'Max 255 characters').optional(),
            }),
        )
        .optional(),
}).superRefine((data, ctx) => {
    // A fixed row with kg entered needs its ITEM — otherwise the kilograms
    // would be silently dropped from the payload.
    //
    // It no longer needs a source. Where the material came from is the
    // server's answer now (FactoryWarehouseResolver::consumptionSource), so
    // there is no field to fill and a "Pick the source" issue could only ever
    // block the drawer on something the supervisor cannot see.
    const requireRow = (
        kg: number | null | undefined,
        itemId: number | null | undefined,
        itemPath: string,
    ) => {
        if (!kg || kg <= 0) return;
        if (!itemId) ctx.addIssue({ code: 'custom', path: [itemPath], message: 'Pick the item' });
    };
    requireRow(data.resin_kg, data.resin_item_id, 'resin_item_id');
    requireRow(data.mb_kg, data.mb_item_id, 'mb_item_id');

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

        // Without a pack size no line total is computable — surfaced on the
        // field the supervisor can actually see. A line with a tray step has
        // no pcs/carton box on screen (it derives from pcs/tray × trays per
        // carton), so its complaint has to land on pcs/tray instead —
        // otherwise Complete Batch would refuse with nothing showing why.
        const step = boxFirstStep(line);
        if (step !== null) {
            if ((line.nos_per_inner ?? 0) < 1 || (line.nos_per_box ?? 0) < 1) {
                ctx.addIssue({
                    code: 'custom',
                    path: ['packing_lines', index, 'nos_per_inner'],
                    message: `Enter how many pieces go in one ${innerNounOne(line.mode) ?? 'tray'} — the carton count comes from it.`,
                });
            }
        } else if ((line.nos_per_box ?? 0) < 1) {
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

/**
 * Where each readiness gap is actually closed, said in one line beside the
 * gap itself.
 *
 * The backend's `detail` explains what the missing field COSTS ("expected
 * output and efficiency cannot be calculated") — which is the right thing for
 * it to say, because the consequence is a fact about the figure and not about
 * this ERP's screens. What it cannot know is which page writes it, and a
 * supervisor holding a blocked Start Batch needs precisely that. So the two
 * sentences sit together: what is missing, and who fixes it.
 *
 * The four run figures all live on the product standard, which is why
 * "Configure this item" goes there and nowhere else — see
 * buildStartBatchStandardUrl. A code with no entry here simply shows the
 * backend's own detail, so a check added server-side never renders a blank.
 */
const READINESS_FIX: Record<string, string> = {
    item_active: 'Reactivate the product on the item master.',
    uom: 'Set the unit of measure on the item master.',
    weight: 'Recorded on the product standard — Configure this item.',
    cycle_time: 'Recorded on the product standard — Configure this item.',
    cavities: 'Recorded on the product standard — Configure this item.',
    packing: 'Pieces per carton is recorded on the product standard — Configure this item.',
    colour: 'Set on the item master; you can also state it for this run below.',
    tally_item: 'Attach this product to its Tally stock item — Configure this item.',
    tally_godown: 'The warehouse needs a Tally godown — an administrator maps it in warehouse settings.',
    machine_active: 'The machine is switched off — reactivate it under Work Centres.',
};

function ReadinessFindings({ findings }: { findings: { code: string; label: string; detail: string }[] }) {
    return (
        <ul style={{ margin: '8px 0 0', paddingLeft: 18 }}>
            {findings.map((f) => (
                <li key={f.code} style={{ marginBottom: 4 }}>
                    <Typography.Text strong>{f.label}</Typography.Text> — {f.detail}
                    {READINESS_FIX[f.code] ? (
                        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                            {READINESS_FIX[f.code]}
                        </Typography.Text>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}

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
    // The SAME drawer, re-opened on a batch that was already completed. Held as
    // an id rather than a second entry object so there is exactly one source of
    // truth for what is on screen (`completingEntry`) and this only answers
    // "is what is on screen a correction?".
    const [amendEntryId, setAmendEntryId] = useState<number | null>(null);
    // Compared against the open entry, not merely "is it set": a stale id left
    // behind by a closed drawer must never turn the next ordinary completion
    // into an amendment of a different batch.
    const amending = completingEntry !== null && amendEntryId === completingEntry.id;
    /**
     * THE ANSWER TO THE STALE-MATERIAL REFUSAL, and it only exists once that
     * refusal has actually happened.
     *
     * The server refuses a correction whose piece counts moved while its
     * material kilograms did not (refuseStaleMaterialLines), because the drawer
     * latches the previously issued kg and the screen would otherwise show one
     * arithmetic while the batch got another. Its 422 ends by telling the
     * supervisor to "send it again confirming the kilograms are right as typed
     * if that is genuinely what the store issued" — a weighed 130 kg beside a
     * piece miscount is a real and legitimate case.
     *
     * Until this existed there was no way to send it again: the flag the server
     * reads (`material_kg_confirmed`) was accepted by AmendBatchRequest and
     * emitted by nothing, so that supervisor was permanently blocked by a
     * message telling them to do something the screen could not do.
     *
     * OFFERED ONLY AFTER THE REFUSAL, never up front. A checkbox sitting there
     * before anything went wrong is a checkbox that gets ticked out of habit,
     * which would put back exactly the silent wrong figure the guard exists to
     * stop. `staleMaterialRefused` is set by the mutation's own error handler
     * and both are cleared whenever a drawer opens or closes.
     */
    const [staleMaterialRefused, setStaleMaterialRefused] = useState(false);
    const [materialKgConfirmed, setMaterialKgConfirmed] = useState(false);
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
    /**
     * WHICH MACHINE THE BAG WENT INTO — required, by the owner's ruling
     * (31-Jul): "Scanning a bag means material was loaded into the selected
     * machine." Defaulted when the floor is unambiguous (exactly one machine
     * running, or the card that opened the modal), otherwise left empty: a
     * guess here credits the wrong machine's estimate, and nothing on any
     * screen would say so. Deliberately NOT cleared between bags — a pallet
     * goes into one machine — only when the modal is opened.
     */
    const [loadBagMachineId, setLoadBagMachineId] = useState<number | null>(null);
    const [loadBagSupervisorId, setLoadBagSupervisorId] = useState<number | null>(null);
    const [loadBagSuccess, setLoadBagSuccess] = useState<string | null>(null);
    const [loadBagError, setLoadBagError] = useState<{ text: string; needsWarehouse: boolean } | null>(null);
    const loadBagInputRef = useRef<InputRef>(null);
    const currentUser = useAuthStore((s) => s.user);
    // There is no per-machine materials view here any more. One bin feeds the
    // whole factory, so it has ONE page — /production/day-bin — plus Load
    // Material at the top of this screen. A button on each of ten machine
    // cards opening the identical factory-wide drawer was ten doors to one
    // room, and it implied the machine had material of its own.
    // Phase 6 traceability targets — only ever set from UI that itself only
    // renders when settings.traceability_enabled is true.
    const [handoverEntry, setHandoverEntry] = useState<ShiftProductionEntry | null>(null);
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    // Phone or tablet-in-portrait. The Completed Today list is the only thing
    // on this page that changes shape rather than merely reflowing.
    const isNarrow = useIsNarrowScreen();
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
    /**
     * A REASON ADDED MOMENTS AGO MUST BE IN THE LIST WHEN THE DRAWER OPENS.
     *
     * This screen is opened once and left open for a whole shift, so the
     * reason list it fetched at 06:00 is the one a supervisor is still picking
     * from at 13:00 — while somebody in the office has just added the reason
     * they were told to use, on another screen, and phoned to say so. Refetched
     * on OPEN rather than polled: the list changes when a person decides
     * something, not on a clock, and the moment it matters is the moment the
     * drawer appears.
     *
     * Keyed on the entry's ID, not the object: the entry is re-read every 20
     * seconds and a new object identity each time would refetch on a loop.
     */
    const completingEntryId = completingEntry?.id ?? null;
    useEffect(() => {
        if (completingEntryId === null) return;
        queryClient.invalidateQueries({ queryKey: ['production', 'scrap-reasons', 'all'] });
    }, [completingEntryId, queryClient]);
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
    /**
     * The WHOLE pending list, on its own query and deliberately not filtered
     * to today.
     *
     * `entries` above is page 1 of 20 of a newest-first list of everything —
     * roughly one day's work on a ten-machine three-shift floor. Both of the
     * things this page now has to say about a completed batch outlive that
     * window: quality can send back a batch produced two nights ago, and the
     * night shift's own batches file under yesterday's production date and are
     * still theirs to correct at 06:45 when the clock has already rolled to
     * Day. Reading either off page 1 of today would hide exactly the batches
     * somebody is standing there holding.
     *
     * Polled more slowly than the floor state: these change when a person at a
     * desk decides something, not when a machine does.
     */
    const { data: pendingEntries } = useQuery({
        queryKey: ['production', 'shift-production-entries', 'pending-all'],
        queryFn: listPendingEntries,
        refetchInterval: 60000,
        // A login without production.view 403s — a normal answer here, and one
        // that must leave the rest of the floor screen working.
        retry: false,
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
    // Exactly the shifts that have a tab above the machine grid — derived from
    // the tab list itself so the two can never disagree. A batch filed under a
    // shift nobody can switch to must keep behaving as it does today: there
    // would be no tab to send its completion to.
    const shiftTabIds = new Set(shiftOptions.map((option) => option.value));
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
    // THE FACTORY STORE — resolved once for this whole screen, and never
    // offered as a choice. Finished goods land in it, packing materials come
    // out of it, and an exception line the day bin cannot supply is issued
    // from it. One factory, one place: the supervisor is told where, not
    // asked. Undefined means the books do not say (no Tally-linked warehouse,
    // or more than one) — every use below then states that in one line
    // instead of guessing.
    const factoryStore = useMemo(() => resolveFactoryStore(warehouses?.data), [warehouses]);
    const factoryStoreId = factoryStore?.id ?? null;
    const factoryStoreName = factoryStoreLabel(factoryStore);
    // Where the factory godown is linked to Tally — the one place a wrong or
    // missing answer is actually fixed, so every "we could not resolve it"
    // line below points at the same page.
    const warehouseSettingsLink = <Link to="/inventory/warehouses">Warehouses</Link>;

    /**
     * WHERE FINISHED GOODS ACTUALLY LAND, as the office set it.
     *
     * The same settings read the Production Configuration card writes — and
     * read BEFORE the sole-Tally-linked heuristic, which is the fallback the
     * SERVER only reaches when nothing is stored. Quoting the heuristic first
     * is what made the Start drawer announce "No single Tally-linked store is
     * set up yet" on a factory where somebody had already chosen the store:
     * a confident wrong answer about a question that was already settled.
     *
     * A 403 (a login without the production module) is a normal answer here
     * and leaves the heuristic in charge, exactly as before.
     */
    const { data: factoryWarehouseSettings } = useQuery({
        queryKey: ['production', 'factory-warehouse-settings'],
        queryFn: getFactoryWarehouseSettings,
        retry: false,
        staleTime: 60 * 1000,
    });
    /**
     * The stored setting first, then what the server says it RESOLVES to
     * today (setting, else the single Tally-linked warehouse). Both come off
     * the same read, so the line can never disagree with the resolver the
     * completion actually uses.
     */
    const finishedGoodsWarehouseId =
        factoryWarehouseSettings?.finished_goods_warehouse_id ??
        factoryWarehouseSettings?.finished_goods_resolved_warehouse_id ??
        null;
    const finishedGoodsWarehouseName = useMemo(() => {
        if (finishedGoodsWarehouseId === null) return null;
        const named = (warehouses?.data ?? []).find((w) => w.id === finishedGoodsWarehouseId);
        return named ? `${named.code} — ${named.name}` : null;
    }, [warehouses, finishedGoodsWarehouseId]);
    /**
     * The Start drawer's one line about where the bottles go.
     *
     * THREE STATES, NOT TWO. "A store is set but this login cannot read the
     * warehouse list" (a floor login 403s on Inventory) is NOT the same as
     * "no store is set" — printing the setup warning for a naming failure is
     * the exact false alarm this line was fixed to stop. So a configured store
     * we cannot name still says a store is configured.
     */
    const finishedGoodsLine: ReactNode =
        finishedGoodsWarehouseName !== null ? (
            `Finished goods go to ${finishedGoodsWarehouseName}.`
        ) : finishedGoodsWarehouseId !== null ? (
            'Finished goods go to the store chosen in Production settings.'
        ) : factoryStoreName ? (
            `Finished goods go to ${factoryStoreName}.`
        ) : (
            <>
                Finished goods go to the factory store. No store is chosen in Production settings and no single
                Tally-linked store could be worked out — check {warehouseSettingsLink}.
            </>
        );
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

    /**
     * The machine to default a bag load to: the one that is running, when
     * EXACTLY one is. Null with none or several — a load credited to the wrong
     * machine silently moves two estimates the wrong way, and no screen would
     * ever say so, which is worth one tap to avoid.
     */
    const soleRunningMachineId = useMemo(
        () => (runningByMachine.size === 1 ? [...runningByMachine.keys()][0] : null),
        [runningByMachine],
    );
    /** Active machines, for the Load Material picker. */
    const machineOptions = useMemo(
        () => (workCenters?.data ?? []).map((machine) => ({ value: machine.id, label: machineLabel(machine) })),
        [workCenters],
    );

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

    const awaitingCorrection = (pendingEntries ?? []).filter(isAwaitingCorrection);

    /**
     * Completed batches the floor may still correct that Completed Today does
     * not show — an earlier production date, or simply past the fifteenth row.
     *
     * The predicate is the entry's own state, so this list is exactly "what the
     * backend would still accept an amendment for" minus the ones already
     * standing in the amber panel above. Without it the Edit door existed only
     * for today's first fifteen batches while the server went on allowing the
     * rest — an offer that disappears at 06:45 for the shift that is still
     * writing its paperwork.
     */
    const correctableEarlier = (pendingEntries ?? []).filter(
        (entry) =>
            canAmendCompletion(entry)
            && !isAwaitingCorrection(entry)
            && !completedToday.some((shown) => shown.id === entry.id),
    );

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
    // The over-100% ceiling as the BACKEND rules it, so the pre-submit panel
    // and the approvers' screen never disagree about the same batch.
    const efficiencyCeiling = settings?.tolerances?.efficiency_over ?? EFFICIENCY_CEILING_PCT;
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
                warehouse_id: startWarehouseId ?? undefined,
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
        if (!startingMachine || !effectiveShiftId || !startItemId) return null;
        return {
            machine_id: startingMachine.id,
            shift_id: effectiveShiftId,
            production_date: startProductionDate,
            item_id: startItemId,
            warehouse_id: startWarehouseId ?? undefined,
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
        // The store travels only when the screen had resolved one; absent, the
        // reopened drawer resolves it afresh like any other open.
        const warehouseOk =
            draft.warehouse_id === undefined
            || warehouses.data.some((candidate) => candidate.id === draft.warehouse_id && candidate.is_active);
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
                            : !warehouseOk
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
            // warehouse_id is destructured out and NEVER sent. The field
            // survives on the form only to carry the configure-recipe
            // round-trip (startBatchResume's draft still requires an id); it is
            // not part of the payload, because the server owns this answer.
            //
            // Deliberately not "send it when we resolved one, omit otherwise":
            // an id sent from here WINS over the server's own precedence, so
            // the moment the factory names a finished-goods warehouse in
            // settings this screen would quietly override it with whichever
            // store happens to be the only Tally-linked one. Always omitting
            // means there is one answer, computed in one place.
            const { active_cavities, colour, warehouse_id: _warehouseId, ...rest } = values;
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
            // are scanned into the ONE factory day bin from the Load Material
            // button on this screen — a per-batch material form here asked the
            // same question a second time and let the two disagree.
            //
            // Nor is there a per-machine materials view any more: the bin's
            // balance, its loads and its returns are all on /production/day-bin,
            // the one page for the one bin.
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
    // Watched for the packing-consumption rows below. These two totals — not
    // the packing_lines array — are what the drawer multiplies, because they
    // are top-level setValue targets that recomputePackingTotals writes AND
    // that a product with no packing lines still fills in by hand; anything
    // keyed on packing_lines identity is stale by construction.
    const traysWatch = completeForm.watch('no_of_trays');
    const pouchesWatch = completeForm.watch('no_of_pouches');
    const loosePiecesWatch = completeForm.watch('loose_pieces');
    const runningHoursWatch = completeForm.watch('running_hours');
    const activeCavitiesWatch = completeForm.watch('active_cavities');
    const qcRejectionWatch = completeForm.watch('qc_rejection_kg');
    const resinKgWatch = completeForm.watch('resin_kg');
    const mbKgWatch = completeForm.watch('mb_kg');
    const resinGramsWatch = completeForm.watch('resin_grams_per_bottle');
    const mbGramsWatch = completeForm.watch('mb_grams_per_bottle');
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

    // The Run Details row (Running Hours · Actual Cycle Time · Active
    // Cavities). The over-100% warning sits in the results panel at the far
    // bottom of a long drawer, and the fields it asks about are a screen and a
    // half above it — so the warning carries a link that brings them into view
    // instead of naming fields the supervisor then has to hunt for.
    const runDetailsRef = useRef<HTMLDivElement | null>(null);
    const scrollToRunDetails = () => {
        runDetailsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    // Manual-edit latches for the resin auto-calculation: a supervisor-typed
    // figure, or a real day-bin weighment, takes the field over permanently
    // for the batch (both reset when the drawer opens for the next one).
    const resinKgTouchedRef = useRef(false);
    const resinKgWeighedRef = useRef(false);
    // The same PAIR for masterbatch, now that it has a dosing suggestion.
    // Both are needed: applyDayBinConsumption also writes mb_kg from a real
    // weighment, and without the weighed latch the next keystroke on the
    // bottle count would overwrite a figure that came off the scale.
    const mbKgTouchedRef = useRef(false);
    const mbKgWeighedRef = useRef(false);
    // And for the grams-per-bottle boxes: a supervisor who corrects the bottle
    // weight owns that box for the batch, so a later prefill cannot walk their
    // figure back.
    const resinGramsTouchedRef = useRef(false);
    const mbGramsTouchedRef = useRef(false);

    /**
     * THE SAME "a supervisor's edit wins permanently" CONTRACT, for a list.
     *
     * The packing rows are not a fixed pair, so the latch cannot be a ref per
     * box: it is a map from the MAPPING's identity to the figure the supervisor
     * typed. Keyed on identity and never on array position, deliberately — the
     * preview refetches on every keystroke that changes the run, and an
     * index-keyed latch would re-attach an edit to whichever material happened
     * to land in that slot next. A key PRESENT in the map is a touched row,
     * even when its value is null (a supervisor who clears a box means "none",
     * and the calculation must not creep back in).
     *
     * Cleared when the drawer opens on a new batch, exactly like the refs above.
     */
    const [packingEdits, setPackingEdits] = useState<Record<string, number | null>>({});
    // `packingSourceId` — the store the supervisor named for the packing rows —
    // is gone with the picker that set it. Nothing reads a packing-line
    // warehouse any more, so holding the state would only be a value to reset.

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

    // `binCanSupply` used to live here, answering "does the bin hold this?" so
    // an exception line could be defaulted to the bin. The server asks itself
    // that question now, against the same stock balance, so the helper had no
    // caller left. `dayBinKgFor` above stays — the grey note still reports the
    // balance, which is the part the supervisor actually needs to see.

    /**
     * NO ROW IN THIS DRAWER ASKS WHERE THE MATERIAL CAME FROM — not resin, not
     * masterbatch, not packing film, not an exception line.
     *
     * There is one factory and one physical place inside it, so the question
     * has one answer and the supervisor is standing in it. The server works it
     * out per line (FactoryWarehouseResolver::consumptionSource): the day bin
     * when the bin actually holds that material, the factory store otherwise —
     * decided from the stock balance, which is a fact in the database rather
     * than an item name or a person's guess. That is why the warehouse is
     * simply OMITTED from every consumption line now instead of being defaulted
     * client-side: a value sent from here would override the server's answer,
     * and the two would disagree the moment the bin ran dry.
     *
     * What used to live here: an `askMaterialSource` fallback that put the
     * source picker back whenever no day bin was configured, and an effect that
     * wrote the bin id into each form field. Both are gone. A setup gap is now
     * answered by the server too, and when it genuinely cannot be answered the
     * completion is refused with a plain 422 naming the Settings fix — never by
     * handing a dropdown to the floor.
     */


    /**
     * "Day bin: 1250.5 Kg" beside a consumption row, so the supervisor watches
     * the balance fall as batches complete — plus a plain warning when they
     * are about to issue more than the bin holds (the backend would refuse
     * it). Never changes the typed figure.
     *
     * When the bin holds NONE of the material, this line is a STATEMENT, not a
     * question: the material is still issued from the bin, and the bin has to
     * be loaded for the figures to reconcile. It names that and where to fix
     * it, because there is no longer a picker to hand the problem to.
     */
    const dayBinHint = (itemId: number | null | undefined, typedKg: number | null | undefined): ReactNode => {
        if (dayBinWarehouseId === null || itemId === null || itemId === undefined) return null;
        const held = dayBinKgFor(itemId);

        if (held === null || held <= 0) {
            const material = items?.data.find((candidate) => candidate.id === itemId);
            return (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    The day bin has no {material ? material.name : 'stock of this material'} recorded — load it in{' '}
                    <Link to="/production/day-bin">Day Bin (factory)</Link> before completing.
                </Typography.Text>
            );
        }

        // The bin holds this material, so the server issues this line FROM the
        // bin — that is the whole of consumptionSource's rule. The shortfall is
        // therefore a straight comparison against what the bin holds. It used
        // to be gated on the row's warehouse field matching the bin; with that
        // field gone, keeping the gate would have silently switched this
        // warning off for every row, which is the opposite of what removing a
        // question should cost.
        const short = typedKg != null && typedKg > held;

        return (
            <Typography.Text type={short ? 'danger' : 'secondary'} style={{ fontSize: 12 }}>
                Day bin: {fmtNum(held, 4)}
                {short && ' — more than the day bin holds; load it in Day Bin (factory) before completing'}
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
        // AN AMENDMENT IS NOT A SUGGESTION. Every box in that drawer was loaded
        // from what the batch actually recorded, and a suggestion computed from
        // the master would overwrite a real counted figure with a derived one —
        // the reset() that loads them leaves no field dirty, so the usual
        // "the user touched this" guard below cannot tell them apart.
        if (amending) return;
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
    }, [quantityProduced, completingEntry, completeForm, amending]);

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
        // Same reason as the effect above: on an amendment the quantity on
        // screen is the quantity the batch was completed with, and re-deriving
        // it from a loaded box count would rewrite the very figure the
        // supervisor opened this drawer to look at.
        if (amending) return;
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
    }, [goodBoxesWatch, pouchesWatch, loosePiecesWatch, nosPerBoxWatch, completingEntry, completeForm, amending]);

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

    /**
     * The colour THIS RUN is recorded as making — the answer frozen into the
     * batch's config snapshot at Start, which the entry Resource sends back as
     * `colour`. The item master's own colour is the fallback and nothing more:
     * most bottle items carry none (which is exactly why Start Batch asks),
     * and a mislabelled one names a different colour's masterbatch.
     *
     * Used for every colour decision in this drawer — which masterbatch is
     * offered, and whether the masterbatch row is shown at all.
     */
    const completingColour = completingEntry?.colour ?? completingEntry?.item.colour ?? null;

    // Reuses the Start Batch preview endpoint (read-only GET) — the standard's
    // packaging rows are the only place the real modes and pack sizes live.
    //
    // The run's colour rides along: the endpoint ranks a stated colour above
    // the configuration's and the item master's, so sending it is what makes
    // the pre-selected masterbatch the one for the colour this batch is
    // RECORDED as running. Keyed on it too, so a re-read after the colour is
    // known is not served the colourless answer from cache.
    const { data: completePreview } = useQuery({
        queryKey: ['production', 'batch-preview', 'complete', completingEntry?.id, completingStandardId, completingColour],
        queryFn: () =>
            getBatchPreview({
                item_id: completingEntry!.item.id,
                work_center_id: completingEntry!.work_center.id,
                production_standard_id: completingStandardId ?? undefined,
                colour: completingColour ?? undefined,
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
            // A line with a tray step holds the split the supervisor typed —
            // whole cartons plus any loose trays — so that step is the honest
            // multiplier and the tray total is exactly the figure its card
            // showed. Lines without one keep reading the standard's stated
            // trays-per-carton, as before.
            const perBox = boxFirstStep(line) ?? (packaging ? innersPerBox(packaging) : null);
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
        // NEVER ON AN AMENDMENT. Packing lines are a completion-time
        // cross-check (the backend validates that they add up to the piece
        // count and to no_of_box) and are not stored on the entry — nothing
        // reads them back. Seeding a blank line here would send zero cartons
        // against a loaded piece count and the server would refuse the
        // correction with an arithmetic complaint about a line the supervisor
        // never filled in. An amendment therefore edits the batch totals
        // directly, which are the figures that were actually recorded.
        if (amending) return;
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

    // ---- Which material each fixed row arrives holding ---------------------
    // Box 1 arrives ANSWERED. The backend's own suggestion is the authority: it
    // matches the masterbatch on the product's derived COLOUR, which is the only
    // thing that can tell Amber from White. A masterbatch NAME cannot, and
    // reading one is how "ARIHANT PET WHITE 1020 Master Batch" came up on an
    // amber run on the owner's screen.
    //
    // Under it, a ladder of facts rather than guesses, for a backend that does
    // not send a suggestion yet: the product's own recipe, then the material
    // physically in the day bin, then a catalogue that offers only one
    // candidate. Each rung says WHY under the row, and an ambiguous colour
    // pre-selects nothing at all rather than the first name that matches.
    //
    // Declared HERE, above applyDayBinConsumption, deliberately: that function
    // must be able to see whether a colour match exists before it writes a
    // material of its own, and the two queries behind them resolve in whatever
    // order the network decides.
    const resinSuggestion = useMemo(() => readSuggestion(completePreview?.suggested_resin), [completePreview]);
    const mbSuggestion = useMemo(() => readSuggestion(completePreview?.suggested_masterbatch), [completePreview]);

    const itemById = useCallback(
        (itemId: number | null | undefined): Item | null =>
            itemId === null || itemId === undefined ? null : items?.data.find((i) => i.id === itemId) ?? null,
        [items],
    );

    /** Materials of one family the factory day bin is holding right now. */
    const binItemIdsMatching = useCallback(
        (matches: (item: Pick<Item, 'sku' | 'name'>) => boolean): number[] =>
            (factoryDayBin?.materials ?? [])
                .filter((row) => (toNum(row.quantity_kg) ?? 0) > 0)
                .map((row) => row.item ?? itemById(row.item_id))
                .filter((material): material is Item => material !== null && matches(material))
                .map((material) => material.id),
        [factoryDayBin, itemById],
    );

    /** Materials of one family named by the product's own recipe (BOM). */
    const recipeItemIdsMatching = useCallback(
        (matches: (item: Pick<Item, 'sku' | 'name'>) => boolean): number[] =>
            (completePreview?.estimation.expected_materials ?? [])
                .filter((line) => {
                    const material = itemById(line.item_id);
                    return material !== null && matches(material);
                })
                .map((line) => line.item_id),
        [completePreview, itemById],
    );

    const resinPick = useMemo<FixedRowPick>(() => {
        if (resinSuggestion.itemId !== null) {
            return { itemId: resinSuggestion.itemId, reason: resinSuggestion.reason ?? 'the resin for this product' };
        }
        const fromRecipe = recipeItemIdsMatching(isResinItem);
        if (fromRecipe.length === 1) return { itemId: fromRecipe[0], reason: "from the product's recipe" };
        const inBin = binItemIdsMatching(isResinItem);
        if (inBin.length === 1) return { itemId: inBin[0], reason: 'the only resin in the day bin' };
        if (resinOptions.length === 1) return { itemId: resinOptions[0].value, reason: 'the only resin in the catalogue' };
        return NO_PICK;
    }, [resinSuggestion, recipeItemIdsMatching, binItemIdsMatching, resinOptions]);

    const mbPick = useMemo<FixedRowPick>(() => {
        if (!completingEntry) return NO_PICK;
        // No masterbatch goes into Clear — nothing is pre-selected, and the row
        // is not even shown (see hideMbRow).
        if (isClearColour(completingColour)) return NO_PICK;
        if (mbSuggestion.itemId !== null) {
            return { itemId: mbSuggestion.itemId, reason: mbSuggestion.reason ?? "matched to the bottle's colour" };
        }
        const byColour = suggestMasterbatchByColour(items?.data, completingColour);
        if (byColour.itemId !== null || byColour.reason !== null) return byColour;
        const inBin = binItemIdsMatching(isMasterbatchItem);
        if (inBin.length === 1) return { itemId: inBin[0], reason: 'the only masterbatch in the day bin' };
        if (mbOptions.length === 1) return { itemId: mbOptions[0].value, reason: 'the only masterbatch in the catalogue' };
        return NO_PICK;
    }, [completingEntry, completingColour, mbSuggestion, items, binItemIdsMatching, mbOptions]);

    // Pre-select both materials — only while the box is untouched and empty, the
    // same contract as every other prefill in this drawer, so a supervisor's own
    // pick is never walked back.
    useEffect(() => {
        if (!completingEntry) return;
        const apply = (field: 'resin_item_id' | 'mb_item_id', itemId: number | null) => {
            if (itemId === null) return;
            if (completeForm.getFieldState(field).isDirty) return;
            if (completeForm.getValues(field) != null) return;
            completeForm.setValue(field, itemId);
        };
        apply('resin_item_id', resinPick.itemId);
        apply('mb_item_id', mbPick.itemId);
    }, [completingEntry, resinPick, mbPick, completeForm]);

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
            const pick = target === 'resin' ? resinPick : mbPick;

            // WHAT MOVED THROUGH THE BIN OWNS THE KG, NOT THE MATERIAL.
            //
            // This used to name the material too, on the grounds that a scanned
            // bag beats a suggestion. It does for a weight; it does not for a
            // colour. Leftover white masterbatch in the bin during an amber run
            // is exactly how the wrong colour appeared pre-selected on the
            // owner's screen — and the row it lands in was chosen by the
            // material's FAMILY (any masterbatch), which cannot tell the bag was
            // the wrong one. So a colour/recipe match keeps box 1, the bin's own
            // reading is reported in the grey line beneath, and this fills the
            // box only when nothing better has answered.
            if (pick.itemId === null && !completeForm.getFieldState(itemField).isDirty && completeForm.getValues(itemField) == null) {
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
            // And a weighment for a DIFFERENT material than the row names is not
            // this row's kg. Reported under the row instead (see rowBinMismatch),
            // never quietly written against the wrong colour.
            const selectedItemId = completeForm.getValues(itemField);
            if (selectedItemId != null && selectedItemId !== material.item.id) return;
            if (completeForm.getFieldState(kgField).isDirty) return;
            // AND NOT OVER A KILOGRAM THAT IS ALREADY A RECORDED FACT. The
            // latch is set by the kg box's own onChange — which also marks the
            // field dirty, so for an ordinary completion this test never
            // decides anything the line above did not. It decides the
            // amendment case: openAmendDrawer loads the kilograms the store
            // actually issued and latches them, and reset() leaves no field
            // dirty, so without this the bin's opening−closing arithmetic would
            // quietly replace an issued figure with a derived one on a batch
            // being corrected.
            if (target === 'resin' ? resinKgTouchedRef.current : mbKgTouchedRef.current) return;
            completeForm.setValue(kgField, Math.round(consumed * 10000) / 10000);
            // A weighed day-bin figure outranks the calculated estimate —
            // latch so the live auto-calculation stops overwriting it. Both
            // rows have an estimate now, so both need the latch.
            if (target === 'resin') resinKgWeighedRef.current = true;
            else mbKgWeighedRef.current = true;
        },
        [completeForm, resinPick, mbPick],
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
    // Off during an amendment for the reason the seeding effect states: the
    // per-mode lines are not stored and cannot be read back, so a correction is
    // entered against the batch totals. Turning it off is what un-disables
    // Quantity Produced and the carton/tray boxes, which is exactly where those
    // recorded totals are loaded.
    const usePackingLines = packingModes.length > 0 && !amending;

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
        // ONLY KG-FAMILY LINES JOIN A KILOGRAM SUM. The "Other materials
        // (exceptions)" repeater accepts any item and files the figure in that
        // item's own unit — so a shift issuing 25 kg of resin and 500 cartons
        // previewed "525.5 kg issued": a fabricated half-tonne, on the screen a
        // supervisor reads before submitting, and contradicting the 25.0 the
        // batch actually got (the server had already filtered the cartons out
        // in consumedMassKg). Counting a carton as a kilogram is wrong wherever
        // the sum is shown, so the filter stays.
        //
        // Resin and masterbatch are added unconditionally: both pickers are
        // kg-uom items by construction (the raw-material picker filters to the
        // kg family server-side), and their fields are typed in kg.
        //
        // A line whose item isn't in `items` yet resolves to undefined and is
        // COUNTED — same fail-safe direction as a blank UOM, see isKgFamilyUom.
        // The non-kg lines are not hidden: each keeps its own row above with
        // its own unit suffix. They are shown, just not summed into kilograms.
        const issuedKg =
            (resinKgWatch ?? 0) +
            (mbKgWatch ?? 0) +
            (consumptionsWatch ?? []).reduce(
                (sum, c) =>
                    isKgFamilyUom(items?.data.find((i) => i.id === c?.item_id)?.uom)
                        ? sum + (c?.quantity_issued_kg ?? 0)
                        : sum,
                0,
            );
        // No per-batch "unaccounted" figure is computed here any more, and the
        // panel shows none. Nothing weighs a fixed quantity of resin out to a
        // machine: consumption is DERIVED from output (production + rejection +
        // lumps), so "issued − consumed" was an arithmetic identity sitting at
        // ~0 and dressed up as a check. Missing material is asked PER MACHINE
        // instead — the Day Bin page holds bags scanned into a machine against
        // what its batches calculated out, and a negative estimated remaining
        // is the answer. Nothing is weighed anywhere in that comparison.
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
        return { ct, cavities, hours, grossHours, downtimeMinutes, nosPerBox, nosPerPouch, expected, goodKg, rejProdKg, qcKg, rejDiffKg, lumpsKg, issuedKg, actualBoxes, actualPouches, actualPieces, efficiencyPct };
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
        // The kg/non-kg split of the exception lines is read off the item
        // master, so the memo must recompute when that list arrives.
        items,
    ]);

    // Box 2, grams per bottle. For resin that is the bottle's own unit weight —
    // the same figure the kg arithmetic below has always used. Blank when the
    // product master has no weight: an invented weight would silently invent
    // every kg computed from it.
    const resinGramsSuggested = resinSuggestion.grams ?? nominalWeight;
    useEffect(() => {
        if (!completingEntry || resinGramsSuggested === null) return;
        if (resinGramsTouchedRef.current) return;
        completeForm.setValue('resin_grams_per_bottle', resinGramsSuggested);
    }, [resinGramsSuggested, completingEntry, completeForm]);

    /**
     * Resin auto-calculation (the factory rule, verified line-for-line against
     * the 17.7.24 paper report): resin consumed = production kg + rejection kg
     * + lumps kg, all from bottle weight. Prefills LIVE as the quantities are
     * typed; a manual edit to the kg box or a weighed day-bin figure takes the
     * field over permanently for this batch (the two latches above).
     *
     * THE FORMULA IS UNCHANGED. The only thing box 2 can do is correct the
     * gram figure the formula reads: while it holds the master's unit weight
     * the arithmetic is identical to what shipped, and a supervisor who
     * corrects 5.0 g to 5.2 g gets the same formula at their weight — not a
     * different formula that quietly drops rejection and lumps.
     */
    const effectiveResinGrams = resinGramsWatch ?? resinGramsSuggested ?? null;
    const resinCalcKg =
        effectiveResinGrams !== null && effectiveResinGrams > 0 && quantityProduced
            ? Math.round(
                  ((quantityProduced * effectiveResinGrams) / 1000 +
                      (quantityScrap ? (quantityScrap * effectiveResinGrams) / 1000 : 0) +
                      lumpsKgLive) *
                      10000,
              ) / 10000
            : null;
    useEffect(() => {
        if (!completingEntry || resinCalcKg === null) return;
        if (resinKgTouchedRef.current || resinKgWeighedRef.current) return;
        completeForm.setValue('resin_kg', resinCalcKg);
    }, [resinCalcKg, completingEntry, completeForm]);

    /**
     * THE ONE MATERIAL FIGURE THE RESULTS PANEL STATES PER BATCH: the resin
     * this batch consumed, in kg. It replaced the "Unaccounted" row, which was
     * ~0 by construction and only ever confused the floor.
     *
     * It is the VALUE OF THE KG BOX — the figure that actually posts as the
     * consumption line (see the resin row assembled for submission below) —
     * not the raw formula, so the panel can never preview a number different
     * from the one the batch gets.
     *
     * "(calculated)" is claimed only while the box still holds the formula's
     * own answer. That is decided by comparing NUMERICALLY against
     * resinCalcKg, never by reading resinKgTouchedRef/resinKgWeighedRef: a ref
     * does not trigger a render, so a label keyed on one would keep saying
     * "calculated" over a figure the supervisor had just typed over. Epsilon
     * rather than ===, because both sides have been through 4dp rounding.
     */
    const resinShownKg = resinKgWatch ?? null;
    const resinIsCalculated =
        resinShownKg !== null && resinCalcKg !== null && Math.abs(resinShownKg - resinCalcKg) < 0.0001;

    // ---- Masterbatch dosing ------------------------------------------------
    // The factory's own figure, in GRAMS PER BOTTLE ("for master amber 0.25 is
    // the value per bottle"), read from the dosing master for THIS masterbatch
    // and THIS product. Asked only once the masterbatch row names a material —
    // a dosing is a property of the colour that gets weighed, so without one
    // there is no question to ask.
    //
    // An empty answer is a real answer: no dosing set → no prefill and no
    // caption. Silence, not a zero: a zero would tell the floor the factory
    // has said this colour needs no masterbatch, and nobody has said that.
    const { data: mbDosings } = useQuery({
        queryKey: ['production', 'masterbatch-dosings', completingEntry?.item.id ?? null, mbItemIdWatch ?? null],
        queryFn: () =>
            listMasterbatchDosings({ item_id: completingEntry!.item.id, masterbatch_item_id: mbItemIdWatch! }),
        enabled: completingEntry !== null && mbItemIdWatch != null,
        // A login without production.view 403s — a normal answer, not an error
        // worth retrying.
        retry: false,
        // Deliberately SHORT for master data (same 60s as the day-bin read):
        // with a long window, one failed or forbidden read would leave the row
        // with no prefill and no caption for that whole window, and the
        // supervisor would have no way to tell that from "no dosing is set".
        staleTime: 60 * 1000,
    });
    /** Grams per bottle in force here, or null for "no dosing set". */
    const mbDosingGrams = useMemo(() => {
        const row = mbDosings?.[0];
        if (!row) return null;
        const grams = Number(row.grams_per_bottle);
        // A non-positive or unreadable master figure is not a dosing — the
        // backend refuses to compute a kg from one, and neither does this.
        return Number.isFinite(grams) && grams > 0 ? grams : null;
    }, [mbDosings]);

    // Box 2 of the masterbatch row: the dosing, prefilled and editable. The
    // preview's suggestion carries the same figure and covers the beat before
    // the dosing read answers, so the box is not blank for a moment on a
    // masterbatch the factory HAS given a figure for.
    //
    // But it backs up ONLY ITS OWN masterbatch. Once the row names a different
    // material the suggestion is not a stand-in for that material's dosing — a
    // suggestion's grams under someone else's colour is a borrowed figure, and
    // a borrowed figure is what books the wrong kg to Tally.
    const mbGramsSuggested =
        mbDosingGrams ?? (mbItemIdWatch != null && mbItemIdWatch === mbSuggestion.itemId ? mbSuggestion.grams : null);
    useEffect(() => {
        if (!completingEntry || mbGramsSuggested === null) return;
        if (mbGramsTouchedRef.current) return;
        completeForm.setValue('mb_grams_per_bottle', mbGramsSuggested);
    }, [mbGramsSuggested, completingEntry, completeForm]);
    /**
     * A CHANGED masterbatch re-reads its own dosing — it never inherits the
     * previous one's.
     *
     * Without this, switching a row from Amber (0.25 g/bottle) to a colour
     * whose dosing is deliberately unset left 0.25 and its computed kg sitting
     * in the boxes, and the grey line went on printing that arithmetic as if
     * the factory had stated it for the new colour. Nobody stated it: that is a
     * borrowed dosing booked against the wrong material, the same class of
     * error as the wrong pre-selection, one field over.
     *
     * So both boxes are blanked and both latches released the instant the
     * material changes, and the dosing effects above refill them if — and only
     * if — the NEW masterbatch has a figure of its own. "No dosing set"
     * therefore reads BLANK, never inherited and never zero.
     *
     * The weighed latch goes too: a day-bin weighment belonged to the material
     * that just left the row, and its own guard already refuses to write a
     * figure against a material the row no longer names.
     */
    const mbLastItemIdRef = useRef<number | null | undefined>(undefined);
    useEffect(() => {
        const previous = mbLastItemIdRef.current;
        const current = mbItemIdWatch ?? null;
        mbLastItemIdRef.current = current;
        // Only a CHANGE of material clears anything. An EMPTY row being filled
        // — the pre-selection landing, or the supervisor's first pick — is not
        // a change, and must not clear: the day-bin pass writes a weighed kg
        // and latches it while the row is still empty (its own guard lets a
        // null row through), so treating null→material as a change would blank
        // a figure somebody actually weighed and let the estimate overwrite it.
        // Clearing the row back to empty IS a change, and does blank.
        if (previous === undefined || previous === null || previous === current) return;
        mbGramsTouchedRef.current = false;
        mbKgTouchedRef.current = false;
        mbKgWeighedRef.current = false;
        completeForm.setValue('mb_grams_per_bottle', null);
        completeForm.setValue('mb_kg', null);
    }, [mbItemIdWatch, completeForm]);

    const effectiveMbGrams = mbGramsWatch ?? mbGramsSuggested ?? null;

    // kg = bottles × grams ÷ 1000 — the factory's arithmetic, live off the
    // bottle count exactly as the resin figure is. ONE term: good bottles.
    // Rejected bottles are deliberately NOT added, unlike resin, because the
    // factory stated the per-bottle dosing and nothing else; adding a term
    // would be inventing arithmetic nobody gave us.
    //
    // Rounded HALF-UP at 4dp to agree with ProductionCalculationEngine::
    // masterbatchKg, which is the authority (13,333 × 0.25 g = 3.33325 kg →
    // 3.3333, not the 3.3332 a truncation would give). Computed here rather
    // than read from the endpoint's own `suggested_kg` for one reason: this
    // field has to move with every keystroke on the bottle count, and a
    // server round trip per digit would lag the figure behind the typing —
    // the same reason every other live figure in this drawer is a frontend
    // duplicate of a backend formula.
    const mbDosingKg =
        effectiveMbGrams !== null && effectiveMbGrams > 0 && quantityProduced !== null && quantityProduced !== undefined && quantityProduced > 0
            ? Math.round(((quantityProduced * effectiveMbGrams) / 1000) * 10000) / 10000
            : null;
    useEffect(() => {
        if (!completingEntry || mbDosingKg === null) return;
        // A supervisor-typed figure or a weighed day-bin figure owns the field
        // for the rest of this batch — same contract as resin.
        if (mbKgTouchedRef.current || mbKgWeighedRef.current) return;
        completeForm.setValue('mb_kg', mbDosingKg);
    }, [mbDosingKg, completingEntry, completeForm]);

    /**
     * A Clear bottle takes no masterbatch, so the row is not shown at all —
     * an empty row on screen is an invitation to put something in it, and a
     * masterbatch booked against a clear run is a wrong Tally voucher.
     *
     * Gated on the box ALSO being empty, not on the colour alone: if a
     * masterbatch genuinely moved through the day bin on a clear run, the
     * weighment has already named it here and that is a fact worth keeping —
     * the row reappears and reports it rather than the figure vanishing from a
     * hidden field.
     *
     * Read off the RUN's colour, not the item master's: an item master that
     * says Clear while the supervisor started the batch on Amber would
     * otherwise hide the row on an amber run, and no masterbatch at all would
     * reach the voucher.
     */
    const hideMbRow = completingEntry !== null && isClearColour(completingColour) && mbItemIdWatch == null;

    /**
     * The one grey line under a fixed row: the arithmetic that produced the kg,
     * then which material and why it was chosen.
     *
     * The reason is dropped the moment the supervisor picks a different material
     * than the one suggested — a stale "matched to the bottle's colour" under
     * their own pick would be a lie about where the figure came from. A pick of
     * NOTHING keeps its reason ("two masterbatches match Amber"), because that
     * is the whole explanation for an empty box.
     */
    const pickReason = (pick: FixedRowPick, selectedItemId: number | null | undefined): string | null =>
        pick.itemId === null ? pick.reason : pick.itemId === selectedItemId ? pick.reason : null;

    /**
     * A day-bin weighment on this batch that belongs to this row's family but
     * names a DIFFERENT material than the row does — leftover white masterbatch
     * weighed out during an amber run, say. Stated, because the alternative is
     * either a silent switch of the material or a silent kg against the wrong
     * colour, and both have already happened once.
     */
    const rowBinMismatch = (
        family: (item: Pick<Item, 'sku' | 'name'>) => boolean,
        selectedItemId: number | null | undefined,
    ): string | null => {
        if (!traceabilityEnabled || !entryDayBin?.has_movements || selectedItemId == null) return null;
        const other = entryDayBin.materials.find((m) => family(m.item) && m.item.id !== selectedItemId);
        return other ? `the day bin also recorded ${other.item.name} on this batch — check what actually went in` : null;
    };

    const rowNote = (arithmetic: string | null, materialName: string | null, reason: string | null, mismatch: string | null): string | null => {
        const who = [materialName, reason].filter((part): part is string => !!part).join(', ');
        const parts = [arithmetic, who || null, mismatch].filter((part): part is string => !!part);
        return parts.length > 0 ? parts.join(' — ') : null;
    };

    const resinRowNote = rowNote(
        resinCalcKg !== null && effectiveResinGrams !== null && quantityProduced
            ? `${quantityProduced.toLocaleString('en-IN')} bottles × ${fmtNum(effectiveResinGrams, 4)} g = ` +
              `${fmtNum((quantityProduced * effectiveResinGrams) / 1000)} kg + ` +
              `${fmtNum(quantityScrap ? (quantityScrap * effectiveResinGrams) / 1000 : 0)} rejection + ` +
              // "kg lumps", not a bare "3.5 lumps": every other term in this
              // line is derived from a bottle COUNT, and an unqualified number
              // beside them reads as a count of lumps. Lumps are only ever
              // weighed.
              `${fmtNum(lumpsKgLive)} kg lumps = ${fmtNum(resinCalcKg)} kg total`
            : null,
        itemById(resinItemIdWatch)?.name ?? null,
        pickReason(resinPick, resinItemIdWatch),
        rowBinMismatch(isResinItem, resinItemIdWatch),
    );

    /**
     * Why the two masterbatch boxes are empty, in the arithmetic slot — because
     * "no dosing set" is the whole explanation for them being empty, and two
     * blank boxes with no sentence are indistinguishable from a screen still
     * loading. The factory has deliberately left White and Red unset, so this
     * is a normal state, not an error: it names the gap and hands the row back
     * to the scale, which is the only other place the figure can come from.
     *
     * Still never a zero — a zero would assert the factory had said this colour
     * needs no masterbatch, and nobody has said that.
     */
    const mbNoDosingNote =
        !hideMbRow && mbItemIdWatch != null && effectiveMbGrams === null
            ? 'no dosing set for this masterbatch — enter the kg the floor weighed'
            : null;

    const mbRowNote = rowNote(
        mbDosingKg !== null && effectiveMbGrams !== null && quantityProduced
            ? `${quantityProduced.toLocaleString('en-IN')} bottles × ${fmtNum(effectiveMbGrams, 4)} g = ${fmtNum(mbDosingKg)} kg total`
            : mbNoDosingNote,
        itemById(mbItemIdWatch)?.name ?? null,
        pickReason(mbPick, mbItemIdWatch),
        rowBinMismatch(isMasterbatchItem, mbItemIdWatch),
    );

    // ---- Packing consumption ----------------------------------------------
    // The cartons, trays, film and tape this run ate, counted off the packing
    // entry the supervisor has already made rather than asked for a second
    // time. The mapping (which Tally item, how much per carton) is the
    // backend's; the multiplying is done here so every figure moves with the
    // keystroke, the same reason the resin and masterbatch kg are computed on
    // this side of the wire.
    // `suggested_packing` is the key the preview serves — the only one
    // (BatchPreviewController, alongside suggested_resin/suggested_masterbatch,
    // from PackingMaterialSuggestionService::forStandard).
    const packingSuggestions = useMemo(
        () => readPackingSuggestions(completePreview?.suggested_packing),
        [completePreview],
    );

    /**
     * One row per suggested packing material: the item (fixed, from the
     * mapping), the quantity, its unit, and the sentence of arithmetic behind
     * it. The DISPLAYED quantity and the SUBMITTED quantity are this one
     * number — the screen and the voucher cannot disagree about what went in.
     *
     * A material with no mapping keeps its row and states its spec; it carries
     * no quantity at all. Never a zero, which would assert the factory packs
     * this product in nothing, and never anything that blocks completion.
     */
    const packingRows = useMemo(() => {
        // THE ONE PLACE AN AMENDMENT MUST NOT RE-ESTIMATE. These rows compute
        // carton/tray/film/tape quantities from the counts on screen, and the
        // mutation appends them to the payload as consumption lines. On an
        // amendment the counts on screen are loaded from a batch whose packing
        // material was ALREADY issued and is already in the consumption rows
        // below — recomputing it here would send the same cartons twice, and
        // the correction would book double the packaging the run used. The
        // recorded lines stay editable as ordinary material rows, which is
        // where the real issued quantities are.
        if (amending) return [];
        const round4 = (n: number) => Math.round(n * 10000) / 10000;
        const counts: Record<PackingBasis, { count: number | null; word: string }> = {
            carton: { count: goodBoxesWatch ?? null, word: 'cartons' },
            tray: { count: traysWatch ?? null, word: 'trays' },
            pouch: { count: pouchesWatch ?? null, word: 'pouches' },
            bottle: { count: quantityProduced ?? null, word: 'bottles' },
        };

        return packingSuggestions.map((row) => {
            const basis = row.basis === null ? null : counts[row.basis];
            const count = basis?.count ?? null;
            // The mapping's own word for the count when it sends one, so the
            // sentence on screen reads in the factory's vocabulary.
            const basisWord = row.basisWord ?? basis?.word ?? null;
            // Pieces of the material first (cartons × per carton), then kg if
            // the mapping states a per-piece weight — this factory's film is
            // counted per carton but weighed into Tally in Kgs, and its item
            // name carries the grams.
            const pieces = count !== null && row.perUnit !== null ? count * row.perUnit : null;
            // Nothing packed yet reads BLANK, not "0 cartons × 1 = 0 Nos". A
            // drawer that opens on a seeded packing line has no_of_box = 0 for
            // the first keystroke, and a screen full of zeros is this file's
            // own anti-zero rule broken four rows at a time — no_of_trays
            // already goes null rather than 0 for exactly this reason.
            const calculated =
                pieces === null || pieces === 0
                    ? null
                    : round4(row.gramsPerPiece !== null ? (pieces * row.gramsPerPiece) / 1000 : pieces);

            const touched = Object.prototype.hasOwnProperty.call(packingEdits, row.key);
            const quantity = touched ? packingEdits[row.key] : calculated;

            // "3 cartons × 120 g = 0.36 Kg" for film, "24 cartons × 2.296 m =
            // 55.104 m" for tape, "24 cartons × 1 = 24 Nos" for the carton
            // itself. The per-unit figure carries its own unit wherever it is
            // not a plain one-per-container count — a bare "2.296" beside a
            // carton count is the sort of number a floor reads as pieces.
            const perUnitPart = row.perUnit !== null && row.perUnit !== 1 ? ` × ${fmtNum(row.perUnit, 4)}` : '';
            const perUnitText =
                row.perUnit === 1 ? fmtNum(row.perUnit, 4) : `${fmtNum(row.perUnit, 4)} ${row.unit}`;
            const arithmetic =
                calculated === null || count === null || basisWord === null
                    ? null
                    : row.gramsPerPiece !== null
                      ? `${count.toLocaleString('en-IN')} ${basisWord}${perUnitPart} × ${fmtNum(row.gramsPerPiece, 4)} g = ${fmtNum(calculated, 4)} ${row.unit}`
                      : `${count.toLocaleString('en-IN')} ${basisWord} × ${perUnitText} = ${fmtNum(calculated, 4)} ${row.unit}`;

            return {
                ...row,
                // The mapping names the item; the catalogue is only a fallback
                // for a backend that sends the id without the name.
                itemName: row.itemName ?? itemById(row.itemId)?.name ?? null,
                calculated,
                quantity,
                touched,
                arithmetic,
            };
        });
    }, [
        amending,
        packingSuggestions,
        packingEdits,
        goodBoxesWatch,
        traysWatch,
        pouchesWatch,
        quantityProduced,
        itemById,
    ]);

    /**
     * The "which packing store" question used to live here, asked once for the
     * whole section because the mapping carries no store of its own.
     *
     * It is gone. The owner's answer to it was the ruling itself — "what is
     * packing store — everything happening inside the factory" — so cartons,
     * tape and film come out of the same one place as everything else, and the
     * server names it (FactoryWarehouseResolver::consumptionSource falls
     * through to the factory store for anything the kg day bin does not hold,
     * which is exactly what packing material is).
     *
     * The old comment here was right that a packing line issues real stock and
     * a short store refuses the completion. That is still true and still the
     * correct behaviour — it is just no longer a reason to ask, because a
     * refusal now names a store the factory configured once rather than one a
     * supervisor picked mid-shift.
     */
    /**
     * Re-open a completed batch in the completion drawer, loaded with what it
     * actually recorded.
     *
     * WHY THE SAME DRAWER. The correction the owner asked for is not a new
     * form — it is the completion, retyped, and the server treats it exactly
     * that way (amendCompletion reverses the first completion and re-runs the
     * ordinary completeBatch on the payload). A second "edit figures" screen
     * would be a second set of rules about the same numbers, and the first
     * time the two disagreed the books would follow whichever one the
     * supervisor happened to use.
     *
     * WHAT IS LOADED, AND WHY IT MATTERS THAT IT IS ALL OF IT. Nothing left
     * out of this payload is "kept" — it is not re-booked. So every figure the
     * entry carries is loaded back: the counts, the hours, the cycle time, the
     * cavities, the recorded scrap, the material lines at the kilograms they
     * were issued at, and the downtime logged with the completion (planned
     * downtime from Start Batch belongs to the run, not to the completion, and
     * the server keeps it — so it is deliberately not loaded here, or it would
     * be filed a second time).
     *
     * The material lines are split back into the two fixed rows the drawer
     * expects wherever they can be recognised, and everything else — including
     * the cartons, trays and film — lands in the ordinary material rows at the
     * quantity actually issued. That is the honest place for them: the packing
     * card recomputes from pack sizes, and a recomputed carton figure is not
     * the one that went out of the store.
     */
    const openAmendDrawer = useCallback(
        (entry: ShiftProductionEntry) => {
            const lines = entry.material_consumptions ?? [];
            const resinLine = lines.find((line) => line.item && isResinItem(line.item));
            const mbLine = lines.find(
                (line) => line.id !== resinLine?.id && line.item && isMasterbatchItem(line.item),
            );
            const otherLines = lines.filter((line) => line.id !== resinLine?.id && line.id !== mbLine?.id);

            setCompletingEntry(entry);
            setAmendEntryId(entry.id);
            // A fresh correction has not been refused yet, and last
            // correction's confirmation is not an answer about this one's
            // kilograms.
            setStaleMaterialRefused(false);
            setMaterialKgConfirmed(false);
            // No packing edit survives into a correction — the packing rows are
            // switched off for it entirely (see the packingRows memo).
            setPackingEdits({});

            // A loaded kilogram figure is a WEIGHED one: it is what the store
            // actually issued. Latching the rows that were loaded stops the
            // estimator from overwriting them with a figure derived from the
            // bottle weight. Rows that were NOT loaded stay unlatched, so a
            // masterbatch added during the correction still gets its dosing
            // suggestion exactly as it would on a first completion.
            resinKgTouchedRef.current = resinLine !== undefined;
            resinKgWeighedRef.current = false;
            mbKgTouchedRef.current = mbLine !== undefined;
            mbKgWeighedRef.current = false;
            resinGramsTouchedRef.current = false;
            mbGramsTouchedRef.current = false;
            mbLastItemIdRef.current = undefined;

            completeForm.reset({
                batch_number: entry.batch_number ?? undefined,
                quantity_produced: toNum(entry.quantity_produced) ?? undefined,
                quantity_scrap: toNum(entry.quantity_scrap) ?? undefined,
                scrap_reason_id: entry.scrap_reason?.id ?? undefined,
                nos_per_tray: entry.nos_per_tray ?? undefined,
                no_of_trays: entry.no_of_trays ?? undefined,
                nos_per_box: entry.nos_per_box ?? undefined,
                no_of_box: entry.no_of_box ?? undefined,
                no_of_pouches: entry.no_of_pouches ?? undefined,
                loose_pieces: entry.loose_pieces ?? undefined,
                running_hours: toNum(entry.running_hours) ?? undefined,
                actual_cycle_time: toNum(entry.actual_cycle_time) ?? undefined,
                active_cavities: entry.active_cavities ?? entry.standard_cavities ?? undefined,
                qc_rejection_kg: toNum(entry.qc_rejection_kg) ?? undefined,
                helper_name: entry.helper_name ?? undefined,
                notes: entry.notes ?? undefined,
                resin_item_id: resinLine?.item?.id ?? undefined,
                resin_kg: toNum(resinLine?.quantity_issued_kg) ?? undefined,
                mb_item_id: mbLine?.item?.id ?? undefined,
                mb_kg: toNum(mbLine?.quantity_issued_kg) ?? undefined,
                material_consumptions: otherLines
                    .filter((line) => line.item?.id != null && toNum(line.quantity_issued_kg) !== null)
                    .map((line) => ({
                        item_id: line.item!.id,
                        quantity_issued_kg: toNum(line.quantity_issued_kg) as number,
                    })),
                scraps: (entry.scraps ?? []).map((scrap) => ({
                    type: scrap.type,
                    quantity_nos: toNum(scrap.quantity_nos) ?? undefined,
                    quantity_kg: toNum(scrap.quantity_kg) ?? undefined,
                    scrap_reason_id: scrap.scrap_reason?.id ?? undefined,
                })),
                downtime_events: (entry.downtime_events ?? [])
                    // Completion downtime only — see the docblock above.
                    .filter((event) => event.known_before_start === false)
                    .map((event) => {
                        const parsed = parseDowntimeNote(event.note);
                        return {
                            downtime_reason_id: event.downtime_reason_id,
                            from_time: parsed.from,
                            to_time: parsed.to,
                            note: parsed.text || undefined,
                        };
                    }),
                // Never seeded on a correction — the server's own validation
                // would refuse a blank line against a loaded piece count.
                packing_lines: [],
            });
        },
        [completeForm],
    );

    /**
     * The correction door on one completed batch, or the sentence that says
     * why there isn't one.
     *
     * DRIVEN OFF THE ROW, NOT OFF THE USER. Whether the floor may still change
     * its own figures is a fact about the batch — completed, still pending, not
     * yet checked — and `canAmendCompletion` reads exactly the fields the
     * server's own guard reads. The server is still the gate (it refuses cases
     * a list row cannot show, such as a batch already on a Tally voucher, and
     * says so in words the drawer shows); this only decides whether offering
     * the door is honest.
     *
     * ONCE QUALITY HAS THE BATCH THERE IS NO CONTROL AT ALL — a disabled
     * button invites a tap and explains nothing. What replaces it is the one
     * sentence that tells the supervisor what to do instead, which is not
     * "wait" but "ask quality".
     */
    const correctionControlFor = (row: ShiftProductionEntry): ReactNode => {
        if (canAmendCompletion(row)) {
            const sentBack = isAwaitingCorrection(row);
            return (
                <Button
                    size="small"
                    type={sentBack ? 'primary' : 'default'}
                    danger={sentBack}
                    onClick={() => openAmendDrawer(row)}
                >
                    {sentBack ? 'Correct — sent back' : 'Edit figures'}
                </Button>
            );
        }

        if (row.batch_status === 'completed' && row.status === 'pending' && row.quality?.checked === true) {
            return (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    QC has this batch — ask QC to return it if a correction is needed.
                </Typography.Text>
            );
        }

        return null;
    };

    /**
     * The Configure Item door out of Start Batch, and the way back in.
     *
     * It carries the whole setup — machine, shift, production date, product,
     * finished-goods store, operator, cavities, the chosen standard and
     * packaging, the stated colour — as allowlisted scalars, and the
     * configuration page hands the same set back so this dialog reopens
     * exactly as it was left. Never an arbitrary return URL: see
     * startBatchResume.
     *
     * Dead until the draft resolves, with the reason on the button rather than
     * in a toast after the tap. The draft needs a product and a store, which
     * are the two things a supervisor can still be mid-way through choosing
     * when the readiness verdict lands.
     */
    const configureItemAction = (
        <Button
            type="primary"
            disabled={!startBatchRecipeDraft}
            title={
                startBatchRecipeDraft
                    ? undefined
                    : 'Choose the product first — it travels with you.'
            }
            onClick={() => {
                if (startBatchRecipeDraft) navigate(buildStartBatchStandardUrl(startBatchRecipeDraft));
            }}
        >
            Configure this item
        </Button>
    );

    const completeMutation = useMutation({
        mutationFn: (values: CompleteBatchFormValues) => {
            if (!completingEntry) throw new Error('No batch selected');
            // loose_pieces and no_of_pouches ride through in ...rest — both are
            // real persisted fields since Wave A packaging. The two
            // grams-per-bottle boxes are destructured out and NEVER sent: they
            // are how the kg was arrived at, and the kg is what is stored and
            // what Tally receives.
            const {
                resin_item_id,
                resin_grams_per_bottle: _resinGramsPerBottle,
                resin_kg,
                mb_item_id,
                mb_grams_per_bottle: _mbGramsPerBottle,
                mb_kg,
                running_hours,
                qc_rejection_kg,
                actual_cycle_time,
                active_cavities,
                material_consumptions,
                closing_day_bin,
                packing_lines,
                downtime_events,
                // Belongs to the amend endpoint alone — never part of an
                // ordinary completion's payload, so it is lifted out of ...rest
                // rather than riding through it.
                amendment_reason,
                ...rest
            } = values;
            // The fixed resin/MB rows are ordinary consumption lines on the
            // wire, and they now carry NO warehouse at all.
            //
            // Omitting the field is what hands the answer to the server, which
            // resolves it per line from where the material actually is. The
            // field used to be defaulted here to the day bin; sending that
            // value back would override the server's per-item answer with a
            // client guess that is wrong for anything the bin does not hold.
            //
            // A row with no material or no kg is still omitted entirely —
            // never a zero-kg line, which would assert a material was issued
            // and weighed at nothing. What is NOT a reason to omit a row any
            // more is a missing warehouse: gating on that once the field
            // stopped being filled would have dropped the resin line from
            // every completion, silently.
            // The packing materials, on the SAME wire as every other
            // consumption line — the carton, the tray, the film, the tape.
            // Sent exactly as the row shows them: the number the supervisor
            // read on screen, in the unit stated beside it.
            //
            // Two kinds of row are deliberately left out rather than sent as
            // zero: one with no mapped item (nobody has decided which Tally
            // item that spec is), and one whose quantity worked out to nothing
            // or was cleared. A zero line would assert a material was issued
            // and came to nothing.
            //
            // "No source to issue from" used to be a third, and its removal is
            // the point of that change: with the store no longer asked, that
            // test was true for EVERY packing row, so every carton, tray, film
            // and tape line would have vanished from the payload without a
            // word — the precise "shown but not recorded" failure the old
            // picker existed to prevent.
            //
            // There IS a third now, and it is the opposite case — a row the
            // backend has ruled may not be filed at all. Today that is the tape
            // line and only the tape line: its factor is METRES (100 cartons ×
            // 2.29 = 229 m) while Tally counts "Packing Tape - Transparent" in
            // Nos, and until the factory says whether a No is a metre or a
            // roll, sending that 229 would post 229 PIECES of tape to the live
            // books — a different number about a different thing, arrived at
            // silently. So the row stays on screen with its metres, its
            // arithmetic and a note saying it is not posted, and it does not
            // ride the wire.
            //
            // WHY THE FLAG AND NOT A `kind === 'tape'` TEST HERE: the answer
            // that ends this is data on the mapping (metres per Tally unit, or
            // a corrected item unit), and the day it arrives the same tape rows
            // must start posting — converted — with nothing on this side
            // touched. The backend rules; this obeys. `submitAsStock` is only
            // ever false when the units genuinely disagree, so carton, tray and
            // film are unaffected in every case.
            const packingConsumptions = packingRows
                .filter(
                    (row) =>
                        row.itemId !== null &&
                        row.quantity !== null &&
                        row.quantity > 0 &&
                        row.submitAsStock,
                )
                .map((row) => ({
                    item_id: row.itemId as number,
                    quantity_issued_kg: row.quantity as number,
                }));
            const consumptions = [
                ...(resin_item_id && resin_kg && resin_kg > 0
                    ? [{ item_id: resin_item_id, quantity_issued_kg: resin_kg }]
                    : []),
                ...(mb_item_id && mb_kg && mb_kg > 0
                    ? [{ item_id: mb_item_id, quantity_issued_kg: mb_kg }]
                    : []),
                ...packingConsumptions,
                // Exception lines: the item and the quantity the supervisor
                // typed, never a warehouse — the row no longer has that field.
                ...(material_consumptions ?? []).map((line) => ({
                    item_id: line.item_id,
                    quantity_issued_kg: line.quantity_issued_kg,
                })),
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

            // A correction goes through the amend door, which reverses what the
            // first completion booked and then re-books this payload through
            // the very same completeBatch() on the server — one path, one set
            // of rules, no second way for figures to reach the books.
            if (amending) {
                return amendBatch(completingEntry.id, {
                    ...payload,
                    amendment_reason: (amendment_reason ?? '').trim() || undefined,
                    // Sent ONLY when the supervisor ticked it after the
                    // server's refusal — omitted, never `false`, so an
                    // ordinary correction's payload is byte-for-byte what it
                    // always was.
                    material_kg_confirmed: materialKgConfirmed ? true : undefined,
                });
            }

            return completeBatch(completingEntry.id, payload);
        },
        onSuccess: (entry) => {
            const wasAmendment = amending;
            invalidate();
            setCompletingEntry(null);
            setAmendEntryId(null);
            setStaleMaterialRefused(false);
            setMaterialKgConfirmed(false);
            completeForm.reset({ material_consumptions: [], scraps: [], packing_lines: [], downtime_events: [] });
            // The packing edits belong to the batch that just went in — the
            // next one recalculates from its own cartons and trays.
            setPackingEdits({});

            // The batch WENT IN. A material whose recorded stock could not
            // cover it is not the supervisor's problem to solve at 6am, and
            // this deliberately is not a modal and not a gate: one line, said
            // once, so the floor knows the office has been told rather than
            // wondering whether the entry took.
            //
            // Nothing at all on the ordinary path — a toast on every single
            // completion would train everyone to dismiss the one that matters.
            const shortfalls = readStockShortfalls(entry);
            if (shortfalls.length > 0) {
                const named = [...new Set(shortfalls.map((line) => line.item))].join(', ');
                message.warning(
                    `${wasAmendment ? 'Correction saved' : 'Batch completed'}. Recorded stock went negative for ${named} — accounts will see it at approval and correct the stock.`,
                    10,
                );
            }

            // A correction DOES get its own line, unlike an ordinary
            // completion. It goes back into the same queue it came from and
            // looks, on every other screen, exactly like the batch that was
            // already there — so without this the supervisor has no way to
            // tell that the second submission is the one that stood.
            if (wasAmendment) {
                message.success('Corrected figures saved — the batch is back with quality for its check.', 6);
            }
        },
        onError: (error: any) => {
            const body = error?.response?.data;
            // A refused submission changes nothing: the drawer stays open with
            // every entered figure intact, and the message says what to fix
            // rather than just what was wrong.
            //
            // A business-rule refusal (a short store on a deployment with
            // negative stock switched off, say) arrives as a 422 carrying ONLY
            // `message` — no `errors` — so it falls to the branch below and is
            // printed exactly as the server wrote it. Keep it that way: the
            // server is the one place that knows which item, which store and
            // which figures, and a sentence reworded here is a sentence that
            // stops matching what the backend can actually explain.
            const fieldMessages: string[] = body?.errors ? (Object.values(body.errors).flat() as string[]) : [];

            // THE ONE REFUSAL THAT HAS AN ANSWER ON THIS SCREEN. The server
            // keys it on `material_consumptions` and only ever raises it from
            // refuseStaleMaterialLines (the amend path's stale-kg guard), so
            // seeing that key on a correction is what unlocks the confirmation
            // checkbox in the drawer. Ticking it and saving again is the "send
            // it again confirming the kilograms are right as typed" the
            // server's own message asks for — before this, the sentence
            // described a control that did not exist.
            if (amending && body?.errors?.material_consumptions) {
                setStaleMaterialRefused(true);
            }

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

    // ----- Load Material: scan a bag into a machine -----

    /**
     * `machineId` is the CONTEXT the door was opened with — the machine whose
     * card started the flow. Absent (the page-level button), the modal
     * defaults to the machine that is running when exactly one is: on a floor
     * with a single machine turning, that is not a guess. With none or several
     * running it stays empty and the load is blocked until somebody says
     * which.
     */
    const openLoadMaterial = (machineId?: number | null) => {
        setLoadBagBarcode('');
        setScannedLoadBag(null);
        setLoadBagKg(null);
        setLoadBagMachineId(machineId ?? soleRunningMachineId);
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
            // falling back to what was scanned — never a blank. The MACHINE is
            // named back, because which machine was credited is the whole
            // point of the scan and a confirmation without it cannot be
            // checked against what the supervisor meant.
            const material = result?.day_bin?.item ?? result?.bag?.lot?.item ?? scannedLoadBag?.lot?.item ?? null;
            const machine = (workCenters?.data ?? []).find((wc) => wc.id === payload.work_center_id);
            setLoadBagSuccess(
                `Loaded ${payload.quantity_kg} kg of ${material ? itemLabel(material) : 'material'}` +
                    `${machine ? ` into ${machineLabel(machine)}` : ''}.`,
            );
            setScannedLoadBag(null);
            setLoadBagKg(null);
            setLoadBagError(null);
            // The machine stays selected: the next bag off the same pallet
            // goes into the same machine, and re-picking it every time is how
            // bag four gets credited to the wrong one.
            // The bag lost kg, the floor gained it, and one machine's estimate
            // moved — every surface quoting any of those must refetch.
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-day-bin'] });
            queryClient.invalidateQueries({ queryKey: ['production', 'machine-resin'] });
            queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
            queryClient.invalidateQueries({ queryKey: ['production', 'material-bags', 'pick-list'] });
            // Back to the gun: the next bag scans without a tap.
            loadBagInputRef.current?.focus();
        },
        onError: (error: any) => {
            const text = error?.response?.data?.message ?? 'Could not load the bag.';
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
        if (loadBagMachineId === null) {
            // The button is disabled for this, but the bag panel is reachable
            // by keyboard — say which field is missing rather than doing
            // nothing at all.
            setLoadBagError({ text: 'Pick the machine this bag was loaded into.', needsWarehouse: false });
            return;
        }
        loadBagMutation.mutate({
            barcode: scannedLoadBag.barcode,
            work_center_id: loadBagMachineId,
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

            {/* SENT BACK BY QUALITY — above the machine grid, because it is the
                only list on this screen that is somebody's job right now and
                ten machine cards of scrolling on a phone is the same as hiding
                it. Rendered only when there is one: an empty amber panel
                standing on the page every day is how a real one stops being
                read. */}
            {awaitingCorrection.length > 0 && (
                <div style={{ marginBottom: 24 }}>
                    <Typography.Title level={5} style={{ color: '#ad6800', marginBottom: 8 }}>
                        Sent back by quality — correct and re-submit ({awaitingCorrection.length})
                    </Typography.Title>
                    <Space direction="vertical" size={8} style={{ width: '100%' }}>
                        {awaitingCorrection.map((entry) => (
                            <Card
                                key={entry.id}
                                size="small"
                                style={{ borderColor: '#faad14', background: '#fffbe6' }}
                            >
                                <Space direction="vertical" size={6} style={{ width: '100%' }}>
                                    <div>
                                        <Typography.Text strong>
                                            {entry.batch_number ?? `Batch #${entry.id}`}
                                        </Typography.Text>{' '}
                                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                            {entry.work_center.name} · {itemLabel(entry.item)} ·{' '}
                                            {entry.production_date} {entry.shift.name}
                                        </Typography.Text>
                                    </div>
                                    {/* The reason IS the instruction. It is the only
                                        thing quality tells the floor, so it is set in
                                        the panel's own colour and never truncated. */}
                                    <Typography.Text style={{ color: '#ad6800' }}>
                                        {readReturnReason(entry) ?? 'No reason was recorded with this return.'}
                                    </Typography.Text>
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        Recorded: {fmtPieces(entry.quantity_produced)} pcs ·{' '}
                                        {fmtNum(toNum(entry.quantity_produced_kg))} kg
                                    </Typography.Text>
                                    <Button type="primary" onClick={() => openAmendDrawer(entry)}>
                                        Correct this batch
                                    </Button>
                                </Space>
                            </Card>
                        ))}
                    </Space>
                </div>
            )}

            <Row gutter={[12, 12]} style={{ marginBottom: 16 }}>
                {/* Server already returns active-only; this filter stays as
                    defence in depth for a cached response from before the
                    active flag existed. */}
                {(workCenters?.data ?? []).filter((w) => w.is_active).map((wc) => {
                    const running = runningByMachine.get(wc.id);
                    const down = openDowntimeByMachine.get(wc.id);
                    const moldChange = openMoldChangeByMachine.get(wc.id);
                    // WHOSE MACHINE IS THIS — the question the shift tabs exist
                    // to answer once one shift's run keeps going into the next.
                    //
                    // A running batch belongs to the shift it is FILED under:
                    // the shift that started it, or — after a handover, which
                    // completes the outgoing segment and opens a new one under
                    // the incoming shift — the shift that took it over. So
                    // viewing shift S: a batch filed under S is ours and reads
                    // normally; a batch still filed under another shift has not
                    // been handed over yet and must not read as ours.
                    //
                    // Deliberately shift-only, NOT shift-and-date: a batch left
                    // running since yesterday's Morning is still Morning's to
                    // complete, and gating on the date as well would leave it
                    // completable from no tab at all. The date is stated
                    // instead — by the Carryover tag, and inside the header
                    // line below.
                    //
                    // Every unknown (no shift on the payload, no shift picked
                    // yet, a batch filed under a shift that has no tab here)
                    // falls through to "ours", which is exactly today's
                    // behaviour: a machine must never become uncompletable
                    // because this page could not work out whose it is.
                    const runningShiftId = running?.shift?.id;
                    const runningForOtherShift =
                        running !== undefined &&
                        typeof runningShiftId === 'number' &&
                        effectiveShiftId !== undefined &&
                        runningShiftId !== effectiveShiftId &&
                        shiftTabIds.has(runningShiftId);
                    // Named off the tab list, not off the entry — "complete it
                    // from the Morning tab" must name the word actually printed
                    // on the tab the supervisor has to press. The lookup always
                    // answers when runningForOtherShift is true (it required a
                    // matching tab); the fallbacks only keep the sentence
                    // grammatical if it is ever read outside that guard.
                    const owningShiftName =
                        shiftOptions.find((option) => option.value === runningShiftId)?.label ??
                        running?.shift?.name ??
                        'the owning';
                    // Said in full once, then used by both the hover and the
                    // tap — completion is not blocked, it just does not happen
                    // from here.
                    const completeElsewhere =
                        `${wc.name} is running the ${owningShiftName} shift's batch. ` +
                        `Complete it from the ${owningShiftName} tab, or hand it over to ` +
                        `${effectiveShift?.name ?? 'this shift'} first.`;
                    // The date only when it is not this shift's own production
                    // date — an overnight run says so, a same-day one stays
                    // short.
                    const otherShiftDateSuffix =
                        running && running.production_date !== today ? ` (${running.production_date})` : '';
                    // Priority order matches how urgent each state is to
                    // surface — a breakdown or an in-progress mold change
                    // takes precedence over "Running", since those are the
                    // states that need someone's attention next. A run that
                    // belongs to another shift takes its own muted amber,
                    // deliberately a different tone from the mold-change amber
                    // above it.
                    const cardColor = down
                        ? '#ff4d4f'
                        : moldChange
                          ? '#faad14'
                          : running
                            ? runningForOtherShift
                                ? '#d48806'
                                : '#52c41a'
                            : undefined;
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
                        } else if (runningForOtherShift) {
                            // The exact mistake this view exists to prevent:
                            // the completion figures belong to the shift that
                            // ran the batch. Answered before the running branch
                            // below, so not one of its form resets fires either
                            // — a tap here changes nothing at all, it only says
                            // where the work is done.
                            message.info(completeElsewhere);
                        } else if (running) {
                            setCompletingEntry(running);
                            // An ordinary completion is never gated by the
                            // stale-material guard (it is amend-only), so this
                            // clears any answer a previous correction left.
                            setStaleMaterialRefused(false);
                            setMaterialKgConfirmed(false);
                            // A fresh batch gets fresh resin and masterbatch
                            // fields: the auto-calculations run again until this
                            // batch's own manual edit or weighed figure latches
                            // them.
                            resinKgTouchedRef.current = false;
                            resinKgWeighedRef.current = false;
                            mbKgTouchedRef.current = false;
                            mbKgWeighedRef.current = false;
                            resinGramsTouchedRef.current = false;
                            mbGramsTouchedRef.current = false;
                            // And the packing rows: a fresh batch recalculates
                            // every carton, tray, film and tape figure from its
                            // own packing entry, with no edit carried over from
                            // the batch before it.
                            setPackingEdits({});
                            // Forget which masterbatch the LAST batch ended on,
                            // so this batch's pre-selection counts as a first
                            // sighting and blanks nothing.
                            mbLastItemIdRef.current = undefined;
                            // Prefill Nos/Tray and Nos/Box from the item's packing
                            // master when set — for items without standards both are
                            // undefined and this reset is identical to before.
                            // Expected-output prefills: Running Hours defaults to
                            // the shift's full length, Active Cavities to what Start
                            // Batch recorded (itself defaulted from the standard).
                            //
                            // The two material rows are deliberately NOT seeded
                            // here. They are filled by the pre-selection effect
                            // above, which prefers the preview's colour-matched
                            // suggestion over anything this click can compute —
                            // seeding a name-matched guess here would win the
                            // "already has a value" race and keep the wrong
                            // masterbatch on screen.
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
                            });
                        } else {
                            setStartProductionDateOverride(null);
                            setStartResumeNotice(null);
                            setPendingStartBatchResume(null);
                            pendingStartBatchResumeRef.current = null;
                            setSelectedStandardId(undefined);
                            setSelectedPackagingId(undefined);
                            setStartingMachine(wc);
                            // Where the finished bottles land: the factory's one
                            // Tally-known store, resolved silently. This used to
                            // sniff the warehouse NAME for "FG"/"finished" and
                            // then still show a dropdown — two ways to get the
                            // same answer wrong. There is one place inside this
                            // factory, so there is nothing to pick.
                            startForm.reset(factoryStoreId ? { warehouse_id: factoryStoreId } : undefined);
                        }
                    };

                    return (
                        <Col key={wc.id} xs={12} sm={8} md={6} lg={4}>
                            {/* Hover says where completion happens; the tap says
                                the same thing, because on the floor it is a
                                thumb. Title is undefined on every other card,
                                so nothing but the not-ours card gains one. */}
                            <Tooltip title={runningForOtherShift ? completeElsewhere : undefined}>
                                <Card
                                    // Not hoverable when it is not ours: the
                                    // card must stop advertising the primary
                                    // action it no longer performs.
                                    hoverable={!runningForOtherShift}
                                    size="small"
                                    onClick={primaryClick}
                                    style={
                                        runningForOtherShift
                                            ? { borderColor: cardColor, background: '#fffbe6' }
                                            : cardColor
                                              ? { borderColor: cardColor }
                                              : undefined
                                    }
                                >
                                    <Typography.Text strong>{wc.name}</Typography.Text>
                                    <div style={{ marginTop: 4, marginBottom: 6 }}>
                                        {down && <Tag color="error">Down — {down.nature_of_problem}</Tag>}
                                        {!down && moldChange && <Tag color="warning">Mold Change</Tag>}
                                        {!down && !moldChange && running && (
                                            // Same words, another shift's colour — gold, not the
                                            // mold-change amber it sits beside in the grid.
                                            <Tag color={runningForOtherShift ? 'gold' : 'success'}>
                                                Running — {running.item.sku}
                                            </Tag>
                                        )}
                                        {!down && !moldChange && !running && <Tag>Idle</Tag>}
                                    </div>
                                    {running?.batch_number && (
                                        <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>
                                            Batch {running.batch_number}
                                        </Typography.Text>
                                    )}
                                    {runningForOtherShift ? (
                                        // The header line the whole card turns on: whose shift
                                        // this run is filed under, and that nobody has handed it
                                        // over yet. It replaces the Carryover tag rather than
                                        // joining it — on this card both would be true, gold,
                                        // and saying overlapping things.
                                        <Typography.Text
                                            style={{ color: '#ad6800', fontSize: 12, display: 'block', marginBottom: 6 }}
                                        >
                                            {`Running for ${owningShiftName} shift${otherShiftDateSuffix} — not handed over`}
                                        </Typography.Text>
                                    ) : (
                                        running &&
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
                                        )
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
                                            {/* A breakdown is a fact about the MACHINE, not
                                                about whose batch is on it — the supervisor
                                                standing in front of it reports it whichever
                                                shift the run is filed under. */}
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
                                            {/* No "Materials" button: the bin is factory-wide, so
                                                it lives on its own page (/production/day-bin) and
                                                is loaded from the one Load Material button below
                                                the machine grid. */}
                                            {running && traceabilityEnabled && (
                                                <Button
                                                    block
                                                    // On a not-ours card this is the one
                                                    // action left worth taking — the way the
                                                    // machine becomes this shift's.
                                                    type={runningForOtherShift ? 'primary' : undefined}
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
                            </Tooltip>
                        </Col>
                    );
                })}
            </Row>

            <Space style={{ marginBottom: 32 }}>
                {traceabilityEnabled && (
                    // Page-level: one door for the whole floor, with the
                    // machine chosen inside it (defaulted when exactly one is
                    // running). Ten identical buttons on ten cards would be
                    // ten doors to one room.
                    <Button type="primary" onClick={() => openLoadMaterial()}>
                        Load Material
                    </Button>
                )}
                <Button onClick={() => setPowerInterruptionOpen(true)}>
                    Log Power Interruption{powerInterruptionsToday.length > 0 ? ` (${powerInterruptionsToday.length} today)` : ''}
                </Button>
                <Button onClick={() => setStockCountOpen(true)}>Log Stock Count</Button>
            </Space>

            <Typography.Title level={5}>Completed Today</Typography.Title>
            {/* TWO SHAPES, ONE SET OF FACTS. The desktop table carries the six
                columns a supervisor actually reads across (batch, product,
                machine, pieces, kg, approval) with the figures right-aligned
                and tabular so they line up down the column; shift and rejected
                ride as secondary text under the columns they belong to rather
                than being dropped. Below `md` it becomes cards, because a
                seven-column grid on a 390px phone is either a horizontal
                scroll nobody discovers or a column nobody can read — and this
                list is read standing at a machine. No `scroll={{x}}` on either:
                the point is that nothing lives off the side of the screen. */}
            {isNarrow ? (
                <Space direction="vertical" size={8} style={{ width: '100%' }}>
                    {entriesLoading && <Card size="small" loading />}
                    {!entriesLoading && completedToday.length === 0 && (
                        <Typography.Text type="secondary">Nothing completed yet today.</Typography.Text>
                    )}
                    {completedToday.map((row) => (
                        <Card key={row.id} size="small">
                            <div
                                style={{
                                    display: 'flex',
                                    justifyContent: 'space-between',
                                    alignItems: 'flex-start',
                                    gap: 8,
                                }}
                            >
                                <div style={{ minWidth: 0 }}>
                                    <Typography.Text strong style={{ wordBreak: 'break-word' }}>
                                        {row.batch_number ?? `Batch #${row.id}`}
                                    </Typography.Text>
                                    <Typography.Text
                                        type="secondary"
                                        style={{ display: 'block', fontSize: 12, wordBreak: 'break-word' }}
                                    >
                                        {itemLabel(row.item)}
                                    </Typography.Text>
                                    <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                        {row.work_center.name} · {row.shift.name}
                                    </Typography.Text>
                                </div>
                                <Tag color={approvalColor[row.status]} style={{ marginInlineEnd: 0 }}>
                                    {row.status}
                                </Tag>
                            </div>
                            <div
                                style={{
                                    display: 'flex',
                                    flexWrap: 'wrap',
                                    gap: '4px 16px',
                                    marginTop: 8,
                                    fontVariantNumeric: 'tabular-nums',
                                }}
                            >
                                <Typography.Text>
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        Produced{' '}
                                    </Typography.Text>
                                    <strong>{fmtPieces(row.quantity_produced)}</strong> pcs
                                </Typography.Text>
                                <Typography.Text>
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        Kg{' '}
                                    </Typography.Text>
                                    <strong>{fmtNum(toNum(row.quantity_produced_kg))}</strong>
                                </Typography.Text>
                                <Typography.Text>
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        Rejected{' '}
                                    </Typography.Text>
                                    <strong>{fmtPieces(row.quantity_scrap)}</strong>
                                </Typography.Text>
                            </div>
                            <div style={{ marginTop: 8 }}>{correctionControlFor(row)}</div>
                        </Card>
                    ))}
                </Space>
            ) : (
                <Table<ShiftProductionEntry>
                    rowKey="id"
                    size="small"
                    loading={entriesLoading}
                    pagination={false}
                    dataSource={completedToday}
                    locale={{ emptyText: 'Nothing completed yet today.' }}
                    columns={[
                        {
                            title: 'Batch',
                            render: (_, row) => (
                                <>
                                    <Typography.Text strong>{row.batch_number ?? `#${row.id}`}</Typography.Text>
                                    <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                        {row.shift.name}
                                    </Typography.Text>
                                </>
                            ),
                        },
                        { title: 'Product', render: (_, row) => itemLabel(row.item) },
                        { title: 'Machine', render: (_, row) => row.work_center.name },
                        {
                            title: 'Produced (pcs)',
                            align: 'right',
                            render: (_, row) => (
                                <>
                                    <span style={{ fontVariantNumeric: 'tabular-nums' }}>
                                        {fmtPieces(row.quantity_produced)}
                                    </span>
                                    <Typography.Text
                                        type="secondary"
                                        style={{ display: 'block', fontSize: 12, fontVariantNumeric: 'tabular-nums' }}
                                    >
                                        {fmtPieces(row.quantity_scrap)} rejected
                                    </Typography.Text>
                                </>
                            ),
                        },
                        {
                            title: 'Produced (kg)',
                            align: 'right',
                            render: (_, row) => (
                                <span style={{ fontVariantNumeric: 'tabular-nums' }}>
                                    {fmtNum(toNum(row.quantity_produced_kg))}
                                </span>
                            ),
                        },
                        {
                            title: 'Approval',
                            render: (_, row) => (
                                <>
                                    <Tag color={approvalColor[row.status]} style={{ marginInlineEnd: 0 }}>
                                        {row.status}
                                    </Tag>
                                    <div style={{ marginTop: 4 }}>{correctionControlFor(row)}</div>
                                </>
                            ),
                        },
                    ]}
                />
            )}

            {/* STILL THEIRS TO CORRECT, just not on today's list. The night
                shift's batches file under yesterday's date and the clock rolls
                to Day at 06:00 — without this the Edit door closed on the very
                people still doing the paperwork, while the server went on
                accepting their correction. Same control, same rule, said in one
                quiet line rather than a second table. */}
            {correctableEarlier.length > 0 && (
                <div style={{ marginTop: 16 }}>
                    <Typography.Text type="secondary">
                        Completed earlier and still correctable — quality has not checked these yet.
                    </Typography.Text>
                    <Space direction="vertical" size={6} style={{ width: '100%', marginTop: 8 }}>
                        {correctableEarlier.map((entry) => (
                            <Card key={entry.id} size="small">
                                <div
                                    style={{
                                        display: 'flex',
                                        flexWrap: 'wrap',
                                        justifyContent: 'space-between',
                                        alignItems: 'center',
                                        gap: 8,
                                    }}
                                >
                                    <div style={{ minWidth: 0 }}>
                                        <Typography.Text strong>
                                            {entry.batch_number ?? `Batch #${entry.id}`}
                                        </Typography.Text>
                                        <Typography.Text
                                            type="secondary"
                                            style={{ display: 'block', fontSize: 12, wordBreak: 'break-word' }}
                                        >
                                            {entry.work_center.name} · {itemLabel(entry.item)} ·{' '}
                                            {entry.production_date} {entry.shift.name} ·{' '}
                                            <span style={{ fontVariantNumeric: 'tabular-nums' }}>
                                                {fmtPieces(entry.quantity_produced)} pcs
                                            </span>
                                        </Typography.Text>
                                    </div>
                                    {correctionControlFor(entry)}
                                </div>
                            </Card>
                        ))}
                    </Space>
                </div>
            )}

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
                        // Configuration-neutral wording on purpose: this notice
                        // now serves two side trips (the product standard and
                        // the consumption recipe), and the 'created' branch
                        // claims only what it actually did — re-read this
                        // product's readiness — rather than asserting that
                        // something was saved on a page it cannot see.
                        message={
                            startResumeNotice === 'created'
                                ? 'Back on Start Batch — this product was re-checked against the latest configuration.'
                                : 'Nothing was changed — your Start Batch details were restored.'
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
                                <>
                                    <ReadinessFindings findings={batchPreview.readiness.blocking} />
                                    {/* ONE door, carrying this machine, this
                                        product, this shift and this date with it,
                                        and it comes back here with all four still
                                        chosen. A supervisor who has to retype the
                                        setup after configuring is a supervisor who
                                        starts the wrong batch. */}
                                    <div style={{ marginTop: 12 }}>{configureItemAction}</div>
                                </>
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
                                <>
                                    <ReadinessFindings findings={batchPreview.readiness.warnings} />
                                    {/* Offered here only when nothing is blocking:
                                        under a blocking alert it would be the same
                                        button twice, one line apart. */}
                                    {batchPreview.readiness.ready && (
                                        <div style={{ marginTop: 12 }}>{configureItemAction}</div>
                                    )}
                                </>
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
                    {/* WHERE THE FINISHED BOTTLES LAND — stated in one line, not
                        asked. This was never a decision the supervisor could
                        get right or wrong; the server resolves it and this line
                        only reports what it will pick. It reads the STORED
                        setting first and the Tally-linked heuristic only after,
                        so a store the office has chosen is named rather than
                        denied. When nothing answers, the line says so and
                        points at the page where it is fixed — the start is not
                        blocked here, because the server arbitrates and refuses
                        with a message of its own if it truly cannot answer. */}
                    <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 4 }}>
                        {finishedGoodsLine}
                    </Typography.Text>
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
                title={`${amending ? 'Correct Batch' : 'Complete Batch'} — ${completingEntry?.work_center.name} · ${completingEntry?.item.sku}`}
                open={completingEntry !== null}
                onClose={() => {
                    setCompletingEntry(null);
                    setAmendEntryId(null);
                    setStaleMaterialRefused(false);
                    setMaterialKgConfirmed(false);
                }}
                width="min(100vw, 560px)"
                destroyOnHidden
                extra={
                    <Button
                        type="primary"
                        loading={completeMutation.isPending}
                        onClick={completeForm.handleSubmit((values) => completeMutation.mutate(values))}
                    >
                        {amending ? 'Save Correction' : 'Complete Batch'}
                    </Button>
                }
            >
                <Form layout="vertical">
                    {/* WHAT A CORRECTION ACTUALLY DOES, before anything is
                        retyped. Said here rather than in a tooltip because the
                        consequence is not obvious from the form: this is not an
                        edit of a few fields, it is the whole completion booked
                        again, and anything cleared out of it is unbooked. */}
                    {amending && completingEntry && (
                        <>
                            <Alert
                                type="warning"
                                showIcon
                                style={{ marginBottom: 16 }}
                                message="Correcting a batch that has already been completed"
                                description={
                                    <>
                                        {readReturnReason(completingEntry) ? (
                                            <div style={{ marginBottom: 8 }}>
                                                <Typography.Text strong>Quality sent this back:</Typography.Text>{' '}
                                                <Typography.Text>{readReturnReason(completingEntry)}</Typography.Text>
                                            </div>
                                        ) : null}
                                        Everything this batch recorded is loaded below. Saving reverses what the first
                                        completion booked — the finished goods, the material issues, the scrap — and
                                        books this form in its place, so a figure removed here is removed from the
                                        books too. Closing bin weights are not stored on the batch and are asked for
                                        again. Saving this records you as the person who counted the batch, so you can
                                        no longer be the one who passes its quality check.
                                    </>
                                }
                            />
                            <Form.Item
                                label="Why is it being corrected? (optional)"
                                extra="Who corrected it and when are recorded either way — this is for the approver reading the batch later."
                            >
                                <Controller
                                    name="amendment_reason"
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        <Input.TextArea
                                            {...field}
                                            value={field.value ?? ''}
                                            rows={2}
                                            maxLength={500}
                                            placeholder="e.g. Recounted the pallet — 38 cartons, not 40."
                                        />
                                    )}
                                />
                            </Form.Item>
                            {/* Only after the server has actually refused this
                                correction for keeping the old kilograms. Shown
                                up front it would be ticked out of habit, which
                                is the silent wrong figure the guard exists to
                                stop; shown here it is a direct answer to a
                                sentence the supervisor has just read. */}
                            {staleMaterialRefused && (
                                <Alert
                                    type="error"
                                    showIcon
                                    style={{ marginBottom: 16 }}
                                    message="The counts moved but the material kilograms did not"
                                    description={
                                        <>
                                            <Typography.Paragraph style={{ marginBottom: 8 }}>
                                                Check the resin and masterbatch rows against the corrected counts. If
                                                the kilograms below are genuinely what the store issued — a weighed
                                                figure that did not change while a piece miscount did — say so and save
                                                again.
                                            </Typography.Paragraph>
                                            <Checkbox
                                                checked={materialKgConfirmed}
                                                onChange={(event) => setMaterialKgConfirmed(event.target.checked)}
                                            >
                                                The kilograms are right as typed — this is what the store issued.
                                            </Checkbox>
                                        </>
                                    }
                                />
                            )}
                        </>
                    )}
                    {/* Frozen at Start Batch, so it is the same standard the
                        approvers will be shown against this batch. */}
                    {completingEntry && (
                        <StandardBasisLine
                            entry={completingEntry}
                            activeCavities={activeCavitiesWatch ?? completingEntry.active_cavities ?? null}
                        />
                    )}
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
                                // Box-first: the floor counts cartons and the
                                // trays (or pouches) follow from this step.
                                // Null means the line has no tray arithmetic
                                // — cartons and pieces, exactly as it always
                                // was.
                                const step = boxFirstStep(line);
                                const innerName = inner ?? 'trays';
                                const innerOne = innerNounOne(line.mode) ?? 'tray';
                                const innerCount = step !== null ? lineInnerCount(line, step) : null;
                                // Pieces per tray can be cleared mid-edit. Until it comes back
                                // nothing about this line is computable, so nothing is asserted:
                                // a card reading "1 carton" beside "0 pcs" is worse than a dash.
                                const trayPieceSize = step !== null ? (line.nos_per_inner ?? null) : null;
                                const cartonText =
                                    step !== null && trayPieceSize !== null
                                        ? cartonSummary(line.boxes ?? 0, line.loose_inner ?? 0, innerOne, innerName)
                                        : '';
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
                                                    {inner && line.nos_per_inner ? ` · ${line.nos_per_inner} pcs/${innerNounOne(line.mode)}` : ''}
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
                                            {step !== null ? (
                                                <>
                                                    {/* BOX-FIRST, reverted on the factory's own request
                                                        after a day of tray-first: "we need to calculate
                                                        by boxes not the tray — if they produced this
                                                        much box, how much product and how much per
                                                        tray." The floor counts CARTONS; trays and
                                                        pieces are arithmetic, shown never asked. A
                                                        part-filled carton is entered as loose trays,
                                                        which is the same loose_inner the API always
                                                        took — the contract does not move for either
                                                        direction of this argument, which is what made
                                                        both flips one-file changes. */}
                                                    <Col xs={12} sm={8}>
                                                        <Form.Item
                                                            label="Cartons"
                                                            style={{ marginBottom: 0 }}
                                                            extra={`full cartons · ${step} ${innerName}/carton`}
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
                                                                        precision={0}
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
                                                        <Form.Item
                                                            label={`Loose ${innerName}`}
                                                            style={{ marginBottom: 0 }}
                                                            extra={`not a full carton — ${step} makes one`}
                                                            validateStatus={lineErrors?.loose_inner ? 'error' : ''}
                                                            help={lineErrors?.loose_inner?.message}
                                                        >
                                                            <Controller
                                                                name={`packing_lines.${index}.loose_inner`}
                                                                control={completeForm.control}
                                                                render={({ field: looseField }) => (
                                                                    <InputNumber
                                                                        {...looseField}
                                                                        size="large"
                                                                        min={0}
                                                                        precision={0}
                                                                        style={{ width: '100%' }}
                                                                        onChange={(value) => {
                                                                            looseField.onChange(value);
                                                                            recomputePackingTotals();
                                                                        }}
                                                                        onBlur={() => {
                                                                            looseField.onBlur();
                                                                            // Loose trays are the ones NOT in a
                                                                            // carton, so a carton's worth of them
                                                                            // is a carton nobody counted — the
                                                                            // pieces would still add up while the
                                                                            // carton, and the master box it eats,
                                                                            // went missing. 7 at 5/carton folds to
                                                                            // 1 more carton + 2, said out loud so
                                                                            // the supervisor sees his own count
                                                                            // change. (The server refuses the
                                                                            // unfolded pair either way.)
                                                                            //
                                                                            // On BLUR, never per keystroke: folding
                                                                            // mid-type would turn a supervisor
                                                                            // typing "50" into one carton the
                                                                            // instant the 5 landed.
                                                                            const current = ((completeForm.getValues('packing_lines') ??
                                                                                []) as PackingLineValues[])[index];
                                                                            const typed = Math.round(Number(current?.loose_inner ?? 0));
                                                                            if (!Number.isFinite(typed) || typed < step) return;
                                                                            const added = Math.floor(typed / step);
                                                                            const over = typed % step;
                                                                            completeForm.setValue(
                                                                                `packing_lines.${index}.boxes`,
                                                                                (current?.boxes ?? 0) + added,
                                                                            );
                                                                            completeForm.setValue(`packing_lines.${index}.loose_inner`, over);
                                                                            recomputePackingTotals();
                                                                            // "+ 0" is the commonest case — a
                                                                            // supervisor who filled exactly one
                                                                            // carton's worth — and it reads like a
                                                                            // glitch. Say the remainder only when
                                                                            // there is one.
                                                                            message.info(
                                                                                `${typed} loose ${innerName} = ${added} more ${
                                                                                    added === 1 ? 'carton' : 'cartons'
                                                                                }${over > 0 ? ` + ${over}` : ''} — folded for you`,
                                                                            );
                                                                        }}
                                                                    />
                                                                )}
                                                            />
                                                        </Form.Item>
                                                    </Col>
                                                    <Col xs={12} sm={8}>
                                                        {/* Prefilled from the imported standard and
                                                            still editable: a run genuinely packed at a
                                                            different tray size must be recordable. The
                                                            carton size follows it, so a carton stays
                                                            the same NUMBER of trays. */}
                                                        <Form.Item
                                                            label={`Pcs per ${innerOne}`}
                                                            style={{ marginBottom: 0 }}
                                                            extra={
                                                                packaging && innerPackSize(packaging)
                                                                    ? `standard: ${innerPackSize(packaging)}`
                                                                    : 'not on the standard — enter it'
                                                            }
                                                            validateStatus={lineErrors?.nos_per_inner ? 'error' : ''}
                                                            help={lineErrors?.nos_per_inner?.message}
                                                        >
                                                            <Controller
                                                                name={`packing_lines.${index}.nos_per_inner`}
                                                                control={completeForm.control}
                                                                render={({ field: perInnerField }) => (
                                                                    <InputNumber
                                                                        {...perInnerField}
                                                                        size="large"
                                                                        min={1}
                                                                        precision={0}
                                                                        style={{ width: '100%' }}
                                                                        onChange={(value) => {
                                                                            perInnerField.onChange(value);
                                                                            // The carton follows the tray:
                                                                            // it stays the same NUMBER of
                                                                            // trays, so pcs/carton is
                                                                            // step × pcs/tray. Rounded —
                                                                            // a fractional pcs/carton
                                                                            // would be refused on a field
                                                                            // this line does not show.
                                                                            completeForm.setValue(
                                                                                `packing_lines.${index}.nos_per_box`,
                                                                                value === null || value === undefined
                                                                                    ? null
                                                                                    : step * Math.round(Number(value)),
                                                                            );
                                                                            recomputePackingTotals();
                                                                        }}
                                                                    />
                                                                )}
                                                            />
                                                        </Form.Item>
                                                    </Col>
                                                    <Col xs={12} sm={8}>
                                                        {/* The derived direction flipped with the input:
                                                            boxes are typed, so trays are the arithmetic
                                                            the factory asked to SEE — "how much product
                                                            and how much per tray" from the boxes. */}
                                                        <Form.Item
                                                            label={`${innerName.charAt(0).toUpperCase()}${innerName.slice(1)} — derived`}
                                                            style={{ marginBottom: 0 }}
                                                            extra={
                                                                trayPieceSize !== null
                                                                    ? `${trayPieceSize} pcs/${innerOne} · ${line.nos_per_box ?? 0} pcs/carton`
                                                                    : `enter pcs per ${innerOne}`
                                                            }
                                                        >
                                                            <Typography.Text strong style={{ display: 'block', fontSize: 16, lineHeight: '40px' }}>
                                                                {trayPieceSize !== null
                                                                    ? `= ${(line.boxes ?? 0) * step + (line.loose_inner ?? 0)} ${innerName}`
                                                                    : '—'}
                                                            </Typography.Text>
                                                        </Form.Item>
                                                    </Col>
                                                </>
                                            ) : (
                                                <>
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
                                                </>
                                            )}
                                            {step === null && inner !== null && (line.nos_per_inner ?? null) === null && (
                                                <Col xs={24}>
                                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                        This standard has no pieces-per-{innerOne}, so loose {inner} cannot be
                                                        converted to pieces — count them into the cartons or correct the pieces below.
                                                    </Typography.Text>
                                                </Col>
                                            )}
                                            {/* The loose-trays box for a line with NO tray step. The
                                                step branch above has its own (with the fold that
                                                turns a carton's worth back into a carton); this one
                                                exists so a standard that never stated trays-per-carton
                                                can still record trays not yet in a carton, priced at
                                                the one figure it does have — pieces per tray. */}
                                            {step === null && inner !== null && (line.nos_per_inner ?? null) !== null && (
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
                                            {step !== null && trayPieceSize !== null ? (
                                                <>
                                                    {innerCount ?? 0} {innerName} × {trayPieceSize} ={' '}
                                                    <strong>{derived}</strong> pcs · = {cartonText}
                                                    {` (${step} ${innerName}/carton)`}
                                                </>
                                            ) : step !== null ? (
                                                <>
                                                    {innerCount ?? 0} {innerName} from the cartons entered · enter pcs per{' '}
                                                    {innerOne} to get the pieces
                                                </>
                                            ) : (
                                                <>
                                                    {line.boxes ?? 0} cartons × {line.nos_per_box ?? 0}
                                                    {inner && line.nos_per_inner
                                                        ? ` + ${line.loose_inner ?? 0} loose ${inner} × ${line.nos_per_inner}`
                                                        : ''}{' '}
                                                    = <strong>{derived}</strong> pcs
                                                    {packaging && innersPerBox(packaging)
                                                        ? ` · ${innersPerBox(packaging)} ${inner}/carton`
                                                        : ''}
                                                </>
                                            )}
                                            {actual !== derived ? ` · counted ${actual}` : ''}
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
                            <Form.Item label="Lumps (Kg)" extra="Always kg — melted waste is weighed, never counted. Counts into resin consumed.">
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

                    {/* The scroll target for the over-100% warning's "adjust
                        here" link — the hours and cavities it asks about are
                        these two boxes. */}
                    <div ref={runDetailsRef}>
                        <Typography.Text strong>Run Details</Typography.Text>
                    </div>
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
                                // The standard sits beside the actual so the pair
                                // reads as expectation vs what the machine really
                                // ran — and so a machine running faster than its
                                // standard is visible here, where the over-100%
                                // warning below says to look.
                                extra={
                                    completingEntry?.standard_cycle_time
                                        ? `std: ${fmtNum(toNum(completingEntry.standard_cycle_time))} s`
                                        : undefined
                                }
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
                    {/* Where consumption comes OUT of — STATED, never asked. The
                        source was already recorded when the bag was loaded into
                        the factory day bin, so these rows carry it silently and
                        completing the batch reduces the bin. ONE line says which
                        place that is, with somewhere to change it. */}
                    {dayBinWarehouseId === null ? (
                        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                            No factory day bin chosen yet, so each line still asks where its material came from.{' '}
                            <Link to="/production/day-bin">Choose one in Day Bin (factory)</Link> and it stops asking.
                        </Typography.Text>
                    ) : (
                        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                            Issued from the factory day bin — {factoryDayBin?.warehouse?.name ?? 'the day bin'}.{' '}
                            <Link to="/production/day-bin">Change it in Day Bin (factory)</Link>.
                        </Typography.Text>
                    )}

                    {/* THREE BOXES: the material, grams per bottle, total kg.
                        Both figures arrive filled in and both stay editable; the
                        TOTAL is the figure that is stored and that Tally
                        receives, and grams per bottle is how it was arrived at. */}
                    <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                        Resin
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
                            <Controller
                                name="resin_grams_per_bottle"
                                control={completeForm.control}
                                render={({ field }) => (
                                    <InputNumber
                                        {...field}
                                        size="large"
                                        min={0}
                                        step={0.1}
                                        placeholder="g/bottle"
                                        suffix="g"
                                        style={{ width: '100%' }}
                                        onChange={(value) => {
                                            // A corrected bottle weight owns this box
                                            // for the batch — and re-runs the SAME kg
                                            // formula at the supervisor's weight.
                                            resinGramsTouchedRef.current = true;
                                            field.onChange(value);
                                        }}
                                    />
                                )}
                            />
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
                                        placeholder="kg total"
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
                        {resinRowNote && (
                            <Col xs={24}>
                                <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                    {resinRowNote}
                                </Typography.Text>
                            </Col>
                        )}
                        <Col xs={24}>{dayBinHint(resinItemIdWatch, resinKgWatch)}</Col>
                    </Row>

                    {/* A Clear bottle takes no colour, so the row is not shown at
                        all — an empty masterbatch row on a clear run is an
                        invitation to book a material that never went in. One line
                        says so, and names where a genuine exception goes. */}
                    {hideMbRow ? (
                        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                            Clear bottles take no masterbatch — if this run genuinely used one, add it under Other
                            materials below.
                        </Typography.Text>
                    ) : (
                        <>
                            <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                                Masterbatch
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
                                    <Controller
                                        name="mb_grams_per_bottle"
                                        control={completeForm.control}
                                        render={({ field }) => (
                                            <InputNumber
                                                {...field}
                                                size="large"
                                                min={0}
                                                step={0.01}
                                                placeholder="g/bottle"
                                                suffix="g"
                                                style={{ width: '100%' }}
                                                onChange={(value) => {
                                                    // The floor's own dosing beats the
                                                    // master's for this batch, and the
                                                    // total kg follows it live.
                                                    mbGramsTouchedRef.current = true;
                                                    field.onChange(value);
                                                }}
                                            />
                                        )}
                                    />
                                </Col>
                                <Col xs={12} sm={7}>
                                    <Controller
                                        name="mb_kg"
                                        control={completeForm.control}
                                        render={({ field }) => (
                                            <InputNumber
                                                {...field}
                                                size="large"
                                                min={0}
                                                placeholder="kg total"
                                                suffix="Kg"
                                                style={{ width: '100%' }}
                                                onChange={(value) => {
                                                    // Same contract as resin: a supervisor-typed
                                                    // figure owns the field for the rest of this
                                                    // batch and the dosing suggestion backs off.
                                                    mbKgTouchedRef.current = true;
                                                    field.onChange(value);
                                                }}
                                            />
                                        )}
                                    />
                                </Col>
                                {mbRowNote && (
                                    <Col xs={24}>
                                        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                            {mbRowNote}
                                        </Typography.Text>
                                    </Col>
                                )}
                                <Col xs={24}>{dayBinHint(mbItemIdWatch, mbKgWatch)}</Col>
                            </Row>
                        </>
                    )}

                    {/* PACKING CONSUMPTION — the carton, the tray, the film and
                        the tape, counted off the packing entry already made
                        above rather than typed a second time. One row per
                        material: the item is FIXED by the factory's mapping
                        (this is not a place to pick a different carton), the
                        quantity is calculated and editable, and the unit is
                        stated because these are not all kilograms.

                        The whole section is absent until the mapping serves a
                        row — a heading over nothing tells the floor a figure is
                        missing when in fact none was ever due. */}
                    {packingRows.length > 0 && (
                        <>
                            <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 12 }}>
                                Packing consumption
                            </Typography.Text>
                            {packingRows.map((row) => {
                                // No mapping — one quiet line naming the spec.
                                // Never a zero quantity against an item nobody
                                // has chosen, and never a reason not to
                                // complete the batch.
                                if (row.itemId === null) {
                                    return (
                                        <Typography.Text
                                            key={row.key}
                                            type="secondary"
                                            style={{ display: 'block', fontSize: 12, marginTop: 6 }}
                                        >
                                            {/* The mapping's own sentence when
                                                it sends one — it already names
                                                the spec and says what is
                                                missing, and saying it twice in
                                                two wordings reads as two
                                                different problems. */}
                                            {row.reason ??
                                                `${row.label}${row.spec ? ` “${row.spec}”` : ''} — no packing item mapped yet, so nothing is counted for it.`}
                                        </Typography.Text>
                                    );
                                }

                                // The one grey line under the row: the
                                // arithmetic, then the mapping's own sentence —
                                // which is also where an inferred spec is
                                // declared, the backend having appended "spec
                                // inferred from row N" to it rather than sending
                                // a provenance object of its own.
                                const note = [
                                    // LEADS the line, and the whole line turns
                                    // from grey to warning below, because this
                                    // is the one caveat that changes what the
                                    // number MEANS. The figure beside it is real
                                    // and the arithmetic behind it is sound —
                                    // it simply is not going anywhere. Reading
                                    // "229 m" and assuming the tape was issued
                                    // is precisely the misreading that put 229
                                    // "Nos" of tape on the live books, so the
                                    // disclaimer comes before the number rather
                                    // than trailing after it.
                                    //
                                    // Driven by the server's ruling, not by the
                                    // kind: the day the factory answers the unit
                                    // question this sentence disappears on its
                                    // own and the row starts posting, with
                                    // nothing changed here.
                                    row.submitAsStock
                                        ? null
                                        : 'NOT posted to stock or Tally until the tape unit is confirmed',
                                    row.arithmetic ??
                                        (row.perUnit === null
                                            ? `no per-${row.basis ?? 'carton'} figure mapped yet — enter what went in`
                                            : null),
                                    // The mapping's sentence already names the
                                    // spec and carries the "spec inferred from
                                    // row N" note, so the spec is only spelled
                                    // out here when nothing else says it. For a
                                    // withheld tape row it also carries the two
                                    // ways the factory can settle it, which is
                                    // why no second copy of that is written here.
                                    row.reason ?? (row.spec ? `${row.label.toLowerCase()} spec “${row.spec}”` : null),
                                    // The "not recorded until a packing store is
                                    // named above" caveat is gone with the
                                    // picker: these rows are always recorded
                                    // now, issued from the store the server
                                    // resolves. Leaving the sentence in would
                                    // have been a warning about a condition
                                    // that can no longer arise.
                                    //
                                    // "Kept" only where keeping it means
                                    // something. The box is read-only on a
                                    // withheld row so this is all but
                                    // unreachable — but a figure typed before a
                                    // preview refetch flipped the flag would
                                    // otherwise read "kept" directly after "NOT
                                    // posted", which is the contradiction this
                                    // whole change exists to remove.
                                    row.touched
                                        ? row.submitAsStock
                                            ? 'your figure, kept'
                                            : 'your earlier figure is kept on screen only'
                                        : null,
                                ]
                                    .filter((part): part is string => !!part)
                                    .join(' — ');

                                return (
                                    <Row key={row.key} gutter={[8, 8]} align="middle" style={{ marginTop: 4 }}>
                                        <Col xs={24} sm={14}>
                                            <Typography.Text>{row.itemName ?? row.label}</Typography.Text>
                                            <Typography.Text type="secondary" style={{ fontSize: 12, marginLeft: 6 }}>
                                                {row.label}
                                            </Typography.Text>
                                        </Col>
                                        <Col xs={12} sm={10}>
                                            <InputNumber
                                                size="large"
                                                min={0}
                                                value={row.quantity}
                                                suffix={row.unit}
                                                placeholder={row.unit}
                                                style={{ width: '100%' }}
                                                // A row that cannot be filed must
                                                // not take a figure either. An
                                                // editable box on a withheld line
                                                // invites a supervisor to correct
                                                // the tape metres and be told
                                                // "your figure, kept" while the
                                                // filter above drops it — the
                                                // screen and the voucher
                                                // disagreeing again, one step
                                                // earlier. Read-only says the
                                                // truth: this line is the
                                                // factory's own arithmetic, shown
                                                // for the record, going nowhere
                                                // until the unit is settled.
                                                disabled={!row.submitAsStock}
                                                onChange={(value) =>
                                                    // A supervisor's figure owns
                                                    // this row for the rest of the
                                                    // batch — including a cleared
                                                    // box, which means "none went
                                                    // in", not "recalculate".
                                                    setPackingEdits((edits) => ({ ...edits, [row.key]: value }))
                                                }
                                            />
                                        </Col>
                                        {note && (
                                            <Col xs={24}>
                                                {/* Warning-coloured, not grey, when
                                                    the row is display-only. Every
                                                    other note under this section is
                                                    context; this one is the
                                                    difference between a figure that
                                                    is going to Tally and one that is
                                                    not, and it has to be legible as
                                                    that at a glance on a shop-floor
                                                    tablet. */}
                                                <Typography.Text
                                                    type={row.submitAsStock ? 'secondary' : 'warning'}
                                                    style={{ fontSize: 12 }}
                                                >
                                                    {note}
                                                </Typography.Text>
                                            </Col>
                                        )}
                                    </Row>
                                );
                            })}
                        </>
                    )}

                    <Space style={{ justifyContent: 'space-between', width: '100%', marginTop: 12 }}>
                        {/* On a correction these rows are not exceptions — they
                            are every material this batch actually issued,
                            cartons and trays included, at the kilograms the
                            store recorded. Calling them exceptions there would
                            invite a supervisor to delete them. */}
                        <Typography.Text type="secondary">
                            {amending
                                ? 'Materials this batch issued — edit the kilograms, or remove a line to unbook it'
                                : 'Other materials (exceptions)'}
                        </Typography.Text>
                        <Button
                            size="small"
                            onClick={() =>
                                materialFields.append({
                                    item_id: undefined as unknown as number,
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
                        //
                        // The label is decided by the SAME predicate that decides
                        // whether the figure joins the kg sums below, so the two
                        // can never disagree — a line printed "Kg" is a line the
                        // "Material issued" total counted, and a line printed in
                        // anything else is one it left out. (Reading the raw uom
                        // through packingUnitLabel alone would print a master
                        // spelled "KILOGRAMS" verbatim while still weighing it.)
                        //
                        // Everything non-kg goes through packingUnitLabel so the
                        // master's spelling ("NOS", "Nos.") lands in the same
                        // vocabulary the packing rows above already print — an
                        // exception row reading "500 NOS" beside a carton row
                        // reading "24 Nos" is the "two different screens" look
                        // that helper exists to prevent — and an unrecognised
                        // unit prints as the master wrote it, so a metre item
                        // still reads "m".
                        const selectedItemId = completeForm.watch(`material_consumptions.${index}.item_id`);
                        const selectedRawUom = items?.data.find((i) => i.id === selectedItemId)?.uom;
                        const selectedUom = isKgFamilyUom(selectedRawUom) ? 'Kg' : packingUnitLabel(selectedRawUom ?? '');
                        return (
                        <Row key={field.id} gutter={[8, 8]} align="middle" style={{ marginTop: 8 }}>
                            {/* The exception lines no longer carry a source
                                either. They are usually the Nos consumables
                                (caps, labels, cartons) which never pass through
                                the kg day bin — and that is now the server's
                                problem to solve, not a question to hand over:
                                consumptionSource falls through to the factory
                                store for exactly these. The item column takes
                                back the width the picker held. */}
                            <Col xs={24} sm={16}>
                                <Controller
                                    name={`material_consumptions.${index}.item_id`}
                                    control={completeForm.control}
                                    render={({ field }) => (
                                        <Select {...field} size="large" options={itemOptions} showSearch optionFilterProp="label" style={{ width: '100%' }} placeholder="Resin/Masterbatch" />
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
                    {scrapFields.fields.map((field, index) => {
                        // "Lumps is always in kgs, there is no nos" — lumps are
                        // melted waste, weighed on the scale; there is nothing
                        // to count. Rejected FG is countable bottles and keeps
                        // its Nos box, so this is per-LINE, not a blanket
                        // removal. The backend contract is untouched
                        // (quantity_nos stays nullable) — the UI simply never
                        // offers it for lumps.
                        const isLumpsLine = (scrapsWatch ?? [])[index]?.type === 'lumps';
                        return (
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
                                            onChange={(value) => {
                                                field.onChange(value);
                                                // Switching a half-typed Rejected FG line to
                                                // Lumps hides the Nos box; without this the
                                                // number typed into it would still be in the
                                                // payload, invisible to the person submitting.
                                                if (value === 'lumps') {
                                                    completeForm.setValue(`scraps.${index}.quantity_nos`, undefined, {
                                                        shouldDirty: true,
                                                    });
                                                }
                                            }}
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
                            {/* The column stays even when the input goes:
                                dropping it would slide Remove up against the Kg
                                box, one fat-fingered tap from deleting the line
                                on a factory tablet. */}
                            <Col xs={12} sm={5}>
                                {isLumpsLine ? (
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        kg only
                                    </Typography.Text>
                                ) : (
                                    <Controller
                                        name={`scraps.${index}.quantity_nos`}
                                        control={completeForm.control}
                                        render={({ field }) => <InputNumber {...field} size="large" min={0} placeholder="Nos" style={{ width: '100%' }} />}
                                    />
                                )}
                            </Col>
                            <Col xs={24} sm={3}>
                                <Button danger block onClick={() => scrapFields.remove(index)}>Remove</Button>
                            </Col>
                        </Row>
                        );
                    })}

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
                            {/* Over 100% is impossible against a correct
                                standard, so it is said out loud here — in the
                                words the owner used — while the entry can
                                still be fixed. A WARNING, never a gate: the
                                figures on this screen are what actually came
                                off the machine, and refusing the batch would
                                only push the shift to type something untrue.

                                EVERYTHING IT ASKS FOR IS ON THIS DRAWER. It
                                used to offer two buttons out to Product
                                Standards and Machine Exceptions; the owner
                                ruled that out — "no need to change the
                                configuration, just adjust the cavities and the
                                number of units on the same page". The produced
                                count, the running hours and the active
                                cavities are all fields a few rows above, all
                                three are dependencies of the `results` memo,
                                so the percentage re-reads as they are typed
                                and this warning clears itself the moment the
                                figure comes back under the ceiling. The link
                                below scrolls to them rather than navigating,
                                because navigating away is what loses a
                                half-filled batch. */}
                            {isOverStandard(results.efficiencyPct, efficiencyCeiling) && (
                                <Alert
                                    type="warning"
                                    showIcon
                                    style={{ marginBottom: 12 }}
                                    message={`More than 100% (${results.efficiencyPct}%) — a machine cannot produce more than its standard allows`}
                                    description={
                                        <>
                                            <Typography.Paragraph style={{ marginBottom: 8 }}>
                                                Fix it here on this screen — nothing on the configuration pages needs changing.
                                                Re-check the three figures on this drawer: the produced count
                                                {usePackingLines ? ' (the packing lines it is summed from)' : ''}, the running hours,
                                                and the active cavities
                                                {results.cavities !== null ? ` (${results.cavities} this run)` : ''}. The percentage
                                                updates as you correct them, and this warning goes away on its own once it is back
                                                under the standard.
                                            </Typography.Paragraph>
                                            <Button size="small" type="link" style={{ padding: 0, height: 'auto' }} onClick={scrollToRunDetails}>
                                                Adjust here — go to Running Hours &amp; Active Cavities ↑
                                            </Button>
                                            <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                                                {results.ct !== null
                                                    ? `The standard this is measured against is ${fmtNum(results.ct)} s cycle time — shown beside Actual Cycle Time above. `
                                                    : ''}
                                                You can still submit this batch — this is a warning, not a block.
                                            </Typography.Text>
                                        </>
                                    }
                                />
                            )}
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
                                    // With packing lines on, this figure is not
                                    // this caption's arithmetic at all — it is
                                    // the sum recomputePackingTotals() posts
                                    // from the line cards. Naming the wrong
                                    // source sends a supervisor checking a
                                    // pcs/box field the drawer is not using.
                                    formula={
                                        usePackingLines
                                            ? 'summed from the packing lines: cartons × pcs/carton + loose inners × pcs/inner, + loose pieces'
                                            : results.nosPerBox && results.nosPerBox >= 1
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
                                            {efficiencyTag(results.efficiencyPct, efficiencyCeiling)}
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
                            {/* Sits directly under the three figures it is made
                                of. This is the batch's material answer now —
                                there is no "unaccounted" row beneath it,
                                because subtracting these same three from a
                                consumption derived from them could only ever
                                give zero. */}
                            {resinShownKg !== null && resinShownKg > 0 && (
                                <ResultRow
                                    label={resinIsCalculated ? 'Resin consumed (calculated)' : 'Resin consumed (entered)'}
                                    value={`${fmtNum(resinShownKg)} kg`}
                                    formula={
                                        resinIsCalculated
                                            ? 'production kg + rejection kg + lumps kg, at the bottle weight above'
                                            : 'set by hand or from a day-bin weighment — the kg box wins for this batch'
                                    }
                                />
                            )}
                            {/* Only when it is a DIFFERENT number from the resin
                                line above. A clear run takes no masterbatch
                                (hideMbRow) and usually has no other kg lines, so
                                the two figures are identical — and the panel was
                                printing the same 25 kg twice under two labels,
                                which reads as two facts. */}
                            {results.issuedKg > 0 &&
                                (resinShownKg === null || Math.abs(results.issuedKg - resinShownKg) > 0.0001) && (
                                <ResultRow
                                    label="Material issued"
                                    value={`${fmtNum(results.issuedKg)} kg`}
                                    // Says what it counted AND what it left
                                    // out. The cartons and caps are still on
                                    // screen in their own rows above with
                                    // their own units — they are listed, just
                                    // not weighed.
                                    formula="resin + masterbatch + kg materials only — Nos/other-unit lines are issued but not weighed"
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
                            Scan a bag into the machine it was loaded into: its kg move out of the store, and that
                            machine's estimated resin remaining goes up by them. The scanner types the code and
                            presses Enter by itself; the machine stays selected between bags.
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
                            {/* THE MACHINE, ABOVE THE BARCODE — the one field
                                the scanner gun cannot fill in, and the one this
                                load is meaningless without. */}
                            <Form.Item
                                label="Machine"
                                required
                                extra={
                                    loadBagMachineId !== null && loadBagMachineId === soleRunningMachineId
                                        ? 'Defaulted to the only machine running — change it if the bag went elsewhere.'
                                        : 'Which machine this bag was emptied into.'
                                }
                            >
                                <Select
                                    value={loadBagMachineId ?? undefined}
                                    onChange={(value) => {
                                        setLoadBagMachineId(value);
                                        setLoadBagError(null);
                                    }}
                                    options={machineOptions}
                                    placeholder="Choose the machine…"
                                    showSearch
                                    optionFilterProp="label"
                                />
                            </Form.Item>
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
                                disabled={!scannedLoadBag || !loadBagKg || loadBagKg <= 0 || loadBagMachineId === null}
                            >
                                {loadBagMachineId === null ? 'Pick a machine first' : 'Load into machine'}
                            </Button>
                        </Form>
                    </Modal>
                </>
            )}
        </>
    );
}
