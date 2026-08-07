import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Card,
    Col,
    Collapse,
    DatePicker,
    Descriptions,
    Empty,
    Form,
    Input,
    InputNumber,
    Row,
    Select,
    Space,
    Table,
    Tag,
    Typography,
    message,
} from 'antd';
import type { InputRef } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuthStore } from '@/features/auth/store';
import { listAllWarehouses } from '@/features/inventory/api';
import {
    findMaterialBagByBarcode,
    getFactoryDayBin,
    getCommonResinEstimate,
    listRawMaterials,
    loadBagToFactoryDayBin,
    factoryStoreLabel,
    loadFactoryDayBin,
    readBalanceAckRefusal,
    resolveFactoryStore,
    setDayBinWarehouse,
} from '@/features/production/api';
import { useProductionSettings } from '@/features/production/packing';
import { BALANCE_ACK_REASON_OPTIONS } from '@/features/production/types';
import type {
    BalanceAckReason,
    CommonResinMaterial,
    FactoryDayBinLoadRow,
    FactoryDayBinUnopenedBags,
    MaterialBag,
} from '@/features/production/types';
import type { Item } from '@/features/inventory/types';
import { itemLabel } from '@/lib/itemLabel';

/** "1250.5000" → "1250.5"; "—" for null/unparseable. */
function fmtQty(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const parsed = parseFloat(value);
    return Number.isNaN(parsed) ? '—' : String(parseFloat(parsed.toFixed(4)));
}

/** The SKU line under a material name — or null when it just repeats the name. */
function skuLine(item: { sku: string; name: string }): string | null {
    const bare = (v: string) => v.toLowerCase().replace(/\s+/g, '');
    return item.sku.trim() === '' || bare(item.sku) === bare(item.name) ? null : item.sku;
}

/**
 * One row of the balances table — the union of the day-bin read's `summary`
 * and `materials` arrays, keyed by item_id.
 *
 * `store_kg`/`unopened_bags` are null for a material the `summary` array does
 * not cover, and null means UNKNOWN, never zero: an unknown store balance
 * shown as 0 reads as "the store is out of it", which would stop a shift for
 * no reason.
 */
interface BalanceRow {
    item_id: number;
    item?: Item;
    bin_kg: string;
    store_kg: string | null;
    unopened_bags: FactoryDayBinUnopenedBags | null;
}

/**
 * THE COMMON RESIN INPUT — the owner's material control room, and the
 * factory's one loading point for material coming out of the store.
 *
 * WHAT THE OWNER CORRECTED (2-Aug, decisive, and it replaced the design this
 * page carried for four days): the factory has ONE COMMON resin input point
 * serving every machine. A bag is NEVER assigned or scanned to a machine.
 * The machine selector, the per-machine cards and the per-machine estimated
 * balance were all false, and they are gone — not nulled, not hidden behind
 * a flag. A per-machine number had no physical referent, so every reading of
 * it was a reading of nothing.
 *
 * What survives from the 31-Jul ruling is the shape of the estimate itself:
 * loads in, calculated consumption out, no physical weighing. This factory
 * takes no bin weight, so the daily reconciliation that once lived here is
 * still gone. The route and the setting keep the "day bin" name because
 * renaming a live surface mid-freeze costs more than it explains.
 *
 * Top to bottom, in the order the floor uses it:
 *
 *  1. SCAN — a barcode input built for a USB scanner gun, and nothing else to
 *     pick. The gun types the code and presses Enter (lookup), the bag shows
 *     itself (material, SKU, kg remaining), and Enter again loads the whole
 *     bag — kg editable first for a part bag. Same POST the Shift Floor's
 *     Load Material modal uses — one flow, two doors.
 *  2. ESTIMATED REMAINING IN THE COMMON INPUT — one row per material: loaded
 *     in, calculated out across ALL machines, and what should therefore still
 *     be in the input. Negative is shown, not hidden: it is the one figure
 *     here worth acting on. It LAGS the floor, because consumption is booked
 *     at batch completion, and the caption says so.
 *  3. WHAT IS OUT OF THE STORE — one row per raw material: on the floor now,
 *     still in the store, bags still holding material. The floor figures are
 *     the WIP warehouse's ordinary stock balances (it IS a warehouse — no
 *     second arithmetic). The row set is the UNION of the read's two arrays:
 *     `summary` (kg-uom materials out or in the store, with all three
 *     figures) plus any `materials` row it doesn't cover — a non-kg material
 *     still has to be visible, with "—" where the store and bag figures are
 *     genuinely unknown rather than zero.
 *  4. MANUAL LOAD (collapsed) — for unlabelled bags and opening stock: raw
 *     materials only (the backend's kg-uom picker — never the bottle list),
 *     posting the EXISTING store → WIP stock transfer. A location move, never
 *     a consumption, never a Tally post. It writes no day-bin movement, so it
 *     does not feed the estimate above — said on screen in one line.
 *  5. LOADED TODAY — every load today with time, material, kg, bag and who.
 *     Always shown once the warehouse is named: an empty list is the normal
 *     morning state and says so in one plain line.
 *
 * Consumption never happens here: Complete Batch on the Shift Floor issues
 * each material line FROM this warehouse, so the balances fall by themselves.
 *
 * Until someone names the warehouse, the page shows one plain line asking for
 * it and nothing else in the ERP changes behaviour.
 */
export default function FactoryDayBinPage() {
    const queryClient = useQueryClient();
    const [manualForm] = Form.useForm();
    const currentUser = useAuthStore((s) => s.user);
    const [pickingWarehouse, setPickingWarehouse] = useState<number | null>(null);
    const [manualOpenKeys, setManualOpenKeys] = useState<string[]>([]);
    const manualOpen = manualOpenKeys.includes('manual');

    // ----- Scan state: plain state, not a form — the driver is a barcode gun.
    const scanInputRef = useRef<InputRef>(null);
    const [scanCode, setScanCode] = useState('');
    const [scannedBag, setScannedBag] = useState<MaterialBag | null>(null);
    const [scanKg, setScanKg] = useState<number | null>(null);
    const [scanError, setScanError] = useState<string | null>(null);
    const [scanSuccess, setScanSuccess] = useState<string | null>(null);
    /**
     * THE REFUSED SCAN, awaiting one word.
     *
     * Set ONLY by a 422 from the server's balance gate, and holding the
     * server's own sentence — this screen never decides on its own that a
     * scan needs explaining, because the estimate the decision rests on
     * lives on the server and a client-side copy of that rule would drift.
     *
     * ONE OBJECT, not three loose fields, and that is the correctness of it:
     * the reason must never outlive the refusal that asked for it. The
     * server's gate short-circuits whenever a reason is present, so a reason
     * left lying in state would sail the NEXT scan straight past the check
     * with nobody told. Cleared on success and on a new barcode — every path
     * that makes the refusal stale.
     */
    const [scanAck, setScanAck] = useState<{
        message: string;
        reason: BalanceAckReason | null;
        note: string;
    } | null>(null);

    const { data: dayBin, isLoading } = useQuery({
        queryKey: ['production', 'factory-day-bin'],
        queryFn: getFactoryDayBin,
    });
    // Scanning resolves barcodes to bags, which only exist with the
    // traceability flag on (the routes 404 with it off) — same master switch
    // the Shift Floor's Load Material button obeys.
    const settings = useProductionSettings();
    const traceabilityEnabled = settings?.traceability_enabled === true;
    // The warehouse/material pick-lists are Inventory's reads. A production-only
    // login 403s on them — a normal answer, not a crash: the balances still
    // show and one plain line explains why the pickers are empty.
    const { data: warehouses, isError: warehousesUnavailable } = useQuery({
        queryKey: ['inventory', 'warehouses', 'all'],
        queryFn: listAllWarehouses,
        retry: false,
    });
    const { data: rawMaterials, isError: rawMaterialsUnavailable } = useQuery({
        queryKey: ['production', 'raw-materials'],
        queryFn: listRawMaterials,
        retry: false,
    });

    const dayBinWarehouse = dayBin?.warehouse ?? null;
    const configured = dayBinWarehouse !== null;

    // ----- ESTIMATED REMAINING IN THE COMMON INPUT. Not date-scoped and not
    // countable: it is a running figure per material, counted from the first
    // load of that material. No date picker, because there is no daily
    // closing to pick — and no count box, because this factory takes no
    // physical bin weight. The query key is UNCHANGED ('machine-resin'): the
    // Shift Floor's Load Material modal invalidates the same key, and
    // renaming it on one side only would silently stop this card refreshing
    // after a floor scan.
    const {
        data: commonResin,
        isLoading: commonResinLoading,
        isError: commonResinUnavailable,
    } = useQuery({
        queryKey: ['production', 'machine-resin'],
        queryFn: getCommonResinEstimate,
        retry: false,
    });

    const warehouseOptions = useMemo(
        () =>
            (warehouses?.data ?? [])
                .filter((w) => w.is_active)
                .map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })),
        [warehouses],
    );
    // `sourceOptions` (warehouses minus the bin itself) is gone with the manual
    // load's "From" picker. `warehouseOptions` above survives for ONE purpose
    // only: the two controls that CONFIGURE which warehouse is the day bin.
    // That is a setup question with a real choice behind it, asked once by
    // whoever sets the factory up — not a question put to a supervisor
    // mid-shift about stock they are holding. It is also the target every
    // "could not work this out" link on the floor points at, so removing it
    // would leave those messages with nowhere to send anyone.
    // Raw materials ONLY — resin and masterbatch, everything bought by the
    // kg. Deliberately never the full items master: a day bin holding
    // "1 Litre Pet Bottle" is a booking mistake this Select refuses to allow.
    //
    // The picker route already sends the display string as `label`; NOT
    // itemLabel(), which reads `sku`/`name` this shape does not carry and
    // would render every option blank without failing a type-check.
    const rawMaterialOptions = useMemo(
        () => (rawMaterials ?? []).map((option) => ({ value: option.id, label: option.label })),
        [rawMaterials],
    );

    // The balances table's row set: `summary` first (the backend name-orders
    // it) and then any material in the bin it left out — never fewer rows
    // than either array alone.
    const balanceRows = useMemo<BalanceRow[]>(() => {
        const rows: BalanceRow[] = (dayBin?.summary ?? []).map((row) => ({
            item_id: row.item_id,
            item: row.item,
            bin_kg: row.bin_kg,
            store_kg: row.store_kg,
            unopened_bags: row.unopened_bags,
        }));

        const covered = new Set(rows.map((row) => row.item_id));
        for (const material of dayBin?.materials ?? []) {
            if (covered.has(material.item_id)) continue;
            covered.add(material.item_id);
            rows.push({
                item_id: material.item_id,
                item: material.item,
                bin_kg: material.quantity_kg,
                store_kg: null,
                unopened_bags: null,
            });
        }

        return rows;
    }, [dayBin]);

    /**
     * WHICH STORE THE MATERIAL CAME OUT OF — resolved, never asked.
     *
     * This is the one warehouse question on the floor that the SERVER cannot
     * answer for us: a manual day-bin load is a plain stock transfer, and its
     * endpoint still requires a real `from_warehouse_id`. So the id is worked
     * out here instead — but by the books, not by warehouse names.
     *
     * What this replaced was a name heuristic (/raw|rm|resin/ scored against
     * code and name, excluding /fg|finish|dispatch/), and before that a rule
     * preferring any name containing "store" — which on this factory's masters
     * is FG Store, so the form once offered to book PET resin out of the
     * bottle warehouse. Warehouse names are not a fact. `tally_guid` is: it is
     * written only by WarehouseService::syncGodownsFromTally, mirroring the
     * accountant's godown list, and this factory's Tally carries exactly one
     * godown. So "the single active Tally-linked warehouse" IS the factory,
     * and rehearsal residue (RM-STORE, WIP, FG-STORE) can never be it.
     *
     * The bin is excluded because the transfer endpoint refuses from === to.
     * undefined means the books do not name a single store, and then the panel
     * says so plainly and refuses to submit rather than guessing.
     */
    const factoryStore = useMemo(
        () => resolveFactoryStore(warehouses?.data, dayBinWarehouse?.id ?? null),
        [warehouses, dayBinWarehouse],
    );

    const chooseWarehouse = useMutation({
        mutationFn: (warehouseId: number | null) => setDayBinWarehouse(warehouseId),
        onSuccess: () => {
            message.success('Day bin warehouse saved');
            setPickingWarehouse(null);
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-day-bin'] });
            // The Shift Floor reads the setting from /production/settings to
            // default its consumption warehouse — it must see this at once.
            queryClient.invalidateQueries({ queryKey: ['production', 'settings'] });
        },
        onError: (error: any) => {
            message.error(
                error?.response?.status === 403
                    ? 'You do not have permission to change production settings (needs Production: Manage).'
                    : (error?.response?.data?.message ?? 'Could not save the day bin warehouse'),
            );
        },
    });

    // ----- Scan flow: lookup on Enter, load on the next Enter (or the button).

    const bagLookup = useMutation({
        mutationFn: findMaterialBagByBarcode,
        onSuccess: (bag, barcode) => {
            if (!bag) {
                setScannedBag(null);
                setScanKg(null);
                setScanError(`No open bag with barcode "${barcode}" in the store.`);
                return;
            }
            setScannedBag(bag);
            // Prefill the whole bag; the field stays editable for a part bag.
            setScanKg(Number(bag.remaining_kg));
            setScanError(null);
            // A NEW BAG IS A NEW QUESTION. Whatever was being explained about
            // the last one does not carry over to this one.
            setScanAck(null);
            // Back to the gun: Enter now loads, or the next scan replaces.
            scanInputRef.current?.focus();
        },
        onError: (error: any) => {
            setScannedBag(null);
            setScanKg(null);
            setScanError(error?.response?.data?.message ?? 'Could not look up that barcode.');
        },
    });

    const bagLoad = useMutation({
        mutationFn: loadBagToFactoryDayBin,
        onSuccess: (result, payload) => {
            // Compose the confirmation from the response where it answers,
            // falling back to what was scanned — never a blank. NO MACHINE IS
            // NAMED BACK, because none was sent: the material entered the
            // factory's one common input.
            const material = result?.day_bin?.item ?? result?.bag?.lot?.item ?? scannedBag?.lot?.item ?? null;
            setScanSuccess(
                `Loaded ${payload.quantity_kg} kg of ${material ? itemLabel(material) : 'material'} into the common resin input.`,
            );
            setScannedBag(null);
            setScanKg(null);
            setScanError(null);
            // THE ACKNOWLEDGEMENT DIES WITH THE SCAN IT EXPLAINED. Left
            // standing, it would be posted with the next bag and wave it
            // through the gate unasked.
            setScanAck(null);
            // The bag lost kg, the floor gained it, and the common input's
            // estimate moved — every surface quoting any of those must refetch.
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-day-bin'] });
            queryClient.invalidateQueries({ queryKey: ['production', 'machine-resin'] });
            queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
            queryClient.invalidateQueries({ queryKey: ['production', 'material-bags', 'pick-list'] });
            // Back to the gun: the next bag scans without a tap.
            scanInputRef.current?.focus();
        },
        onError: (error: any) => {
            // THE BALANCE GATE, first: the server says the common input is
            // still estimated to hold material and wants one word before it
            // takes more. Its sentence names the figure AND states that the
            // figure does not count batches still running, so it is shown as
            // written rather than restated here — the estimate is the
            // server's to quote and this screen has no business paraphrasing
            // it, least of all the caveat.
            const refusal = readBalanceAckRefusal(error);
            if (refusal !== null) {
                setScanError(null);
                setScanAck({ message: refusal, reason: null, note: '' });
                return;
            }
            // Anything else is an ordinary failure, and it SUPERSEDES any
            // half-answered acknowledgement rather than sitting beside it.
            setScanAck(null);
            setScanError(error?.response?.data?.message ?? 'Could not load the bag.');
        },
    });

    /**
     * Post the scan. `ack` is present only on the resubmit of a scan the
     * server already refused — never on a first attempt, because a reason
     * sent up front turns the gate off for that scan.
     *
     * The payload is rebuilt from LIVE state on both passes, not replayed
     * from a stashed copy, so a supervisor who lowers the kg while answering
     * the question loads the kg they can see. Nothing here clears the bag on
     * failure, which is what makes that safe.
     */
    const submitBagLoad = (ack?: { reason: BalanceAckReason; note: string }) => {
        if (!scannedBag || !scanKg || scanKg <= 0 || !currentUser || bagLoad.isPending) {
            return;
        }
        bagLoad.mutate({
            barcode: scannedBag.barcode,
            quantity_kg: scanKg,
            // Recorded as a note; the audit identity is the login either way.
            supervisor_id: currentUser.id,
            ...(ack
                ? {
                      balance_ack_reason: ack.reason,
                      // Blank stays ABSENT rather than going up as "": the
                      // note is optional and an empty one is not a note.
                      ...(ack.note.trim() !== '' ? { balance_ack_note: ack.note.trim() } : {}),
                  }
                : {}),
        });
    };

    /**
     * One Enter key, two meanings: a typed/scanned code looks the bag up; an
     * EMPTY Enter with a bag on screen loads it — so gun-scan → Enter loads
     * the whole bag with no other touch.
     */
    const submitScan = () => {
        const code = scanCode.trim();
        if (code !== '') {
            setScanCode('');
            setScanSuccess(null);
            // A new code supersedes an unanswered refusal even if the lookup
            // then fails — clearing it here rather than only in the lookup's
            // success path is what makes that true.
            setScanAck(null);
            bagLookup.mutate(code);
            return;
        }
        // WITH A QUESTION ON SCREEN, ENTER DOES NOTHING. Reposting without
        // the reason only earns the same refusal back, and the answer being
        // typed at that moment would be wiped by it.
        if (scanAck !== null) return;
        if (scannedBag) submitBagLoad();
    };

    // ----- Manual (no-barcode) load: the existing store → bin transfer.

    const manualLoad = useMutation({
        // No `from_warehouse_id` in the form values any more — the resolved
        // factory store supplies it. The submit is disabled unless it resolved,
        // so the non-null assertion here is guarded by the button, the same way
        // `dayBinWarehouse!` already is.
        mutationFn: (values: { item_id: number; quantity: number; loaded_at?: Dayjs }) =>
            loadFactoryDayBin({
                item_id: values.item_id,
                from_warehouse_id: factoryStore!.id,
                to_warehouse_id: dayBinWarehouse!.id,
                quantity: values.quantity,
                movement_date: (values.loaded_at ?? dayjs()).format('YYYY-MM-DD HH:mm:ss'),
                reference: 'Day bin load',
            }),
        onSuccess: () => {
            message.success('Loaded into the day bin');
            // Keep the source and time — unlabelled stock usually arrives as
            // several materials from the same store in one go.
            manualForm.setFieldsValue({ item_id: undefined, quantity: undefined });
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-day-bin'] });
            queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
        },
        onError: (error: any) => {
            const status = error?.response?.status;
            if (status === 403) {
                message.error('You do not have permission to move stock (needs Inventory: Manage).');
                return;
            }
            message.error(error?.response?.data?.message ?? 'Could not load the day bin');
        },
    });

    // ----- Tables ----------------------------------------------------------

    const balanceColumns = [
        {
            title: 'Material',
            key: 'material',
            render: (_: unknown, row: BalanceRow) =>
                row.item ? (
                    <Space direction="vertical" size={0}>
                        <Typography.Text strong>{row.item.name}</Typography.Text>
                        {skuLine(row.item) !== null && (
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                {skuLine(row.item)}
                            </Typography.Text>
                        )}
                    </Space>
                ) : (
                    <Typography.Text type="secondary">Item #{row.item_id}</Typography.Text>
                ),
        },
        {
            title: 'On the floor',
            key: 'in_bin',
            align: 'right' as const,
            render: (_: unknown, row: BalanceRow) => (
                <Typography.Text strong style={{ fontSize: 18 }}>
                    {fmtQty(row.bin_kg)} <Typography.Text type="secondary">{row.item?.uom ?? 'Kg'}</Typography.Text>
                </Typography.Text>
            ),
        },
        {
            title: 'In store',
            key: 'in_store',
            align: 'right' as const,
            // "—" (not 0) for a material the summary doesn't cover — an
            // unknown store balance must never read as an empty store.
            render: (_: unknown, row: BalanceRow) =>
                row.store_kg === null ? (
                    <Typography.Text type="secondary">—</Typography.Text>
                ) : (
                    <Typography.Text>
                        {fmtQty(row.store_kg)} <Typography.Text type="secondary">{row.item?.uom ?? 'Kg'}</Typography.Text>
                    </Typography.Text>
                ),
        },
        {
            title: 'Unopened bags',
            key: 'unopened',
            align: 'right' as const,
            render: (_: unknown, row: BalanceRow) =>
                row.unopened_bags === null ? (
                    <Typography.Text type="secondary">—</Typography.Text>
                ) : (
                    <Typography.Text>
                        {row.unopened_bags.count} <Typography.Text type="secondary">·</Typography.Text>{' '}
                        {fmtQty(row.unopened_bags.kg)} <Typography.Text type="secondary">kg</Typography.Text>
                    </Typography.Text>
                ),
        },
    ];

    /**
     * The common input's estimate table — ONE table, one row per material.
     * There is no machine dimension to group by any more, and there is no
     * second table: the factory has one loading point.
     *
     * NEGATIVE IS PRINTED, in red, with the reason beside it. The estimate is
     * loaded-in minus calculated-out, and consumption is derived from output
     * rather than weighed — so a negative figure means the factory ran on
     * material nobody recorded loading. That is the one line on this page
     * worth acting on, and clamping it at zero would erase exactly it.
     */
    const commonResinColumns = [
        {
            title: 'Material',
            key: 'material',
            render: (_: unknown, row: CommonResinMaterial) => (
                <Space direction="vertical" size={0}>
                    <Typography.Text strong>{row.item.name}</Typography.Text>
                    {skuLine(row.item) !== null && (
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            {skuLine(row.item)}
                        </Typography.Text>
                    )}
                </Space>
            ),
        },
        {
            title: 'Loaded',
            key: 'loaded',
            align: 'right' as const,
            render: (_: unknown, row: CommonResinMaterial) => fmtQty(row.loaded_kg),
        },
        {
            title: 'Consumed (all batches)',
            key: 'consumed',
            align: 'right' as const,
            render: (_: unknown, row: CommonResinMaterial) => fmtQty(row.consumed_kg),
        },
        {
            title: 'Estimated remaining (never counted — drifts)',
            key: 'remaining',
            align: 'right' as const,
            render: (_: unknown, row: CommonResinMaterial) => {
                const remaining = parseFloat(row.estimated_remaining_kg);
                const short = !Number.isNaN(remaining) && remaining < 0;
                return (
                    <Space direction="vertical" size={0} align="end">
                        <Typography.Text strong type={short ? 'danger' : undefined} style={{ fontSize: 18 }}>
                            {fmtQty(row.estimated_remaining_kg)}{' '}
                            <Typography.Text type="secondary">{row.item.uom}</Typography.Text>
                        </Typography.Text>
                        {/* Quiet, not an alarm: it is a likely explanation, not
                            a verdict, and the fix is a scan somebody missed. */}
                        {short && (
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                more consumed than loaded — a bag was probably not scanned
                            </Typography.Text>
                        )}
                    </Space>
                );
            },
        },
        {
            title: 'Last load',
            key: 'last_load',
            render: (_: unknown, row: CommonResinMaterial) =>
                row.last_load_at === null ? (
                    <Typography.Text type="secondary">—</Typography.Text>
                ) : (
                    dayjs(row.last_load_at).format('DD MMM HH:mm')
                ),
        },
    ];

    const loadColumns = [
        {
            title: 'Time',
            key: 'time',
            // Guarded: dayjs(null) silently means "now", which would print a
            // believable but invented clock time.
            render: (_: unknown, row: FactoryDayBinLoadRow) =>
                row.time === null ? <Typography.Text type="secondary">—</Typography.Text> : dayjs(row.time).format('HH:mm'),
        },
        {
            title: 'Material',
            key: 'material',
            render: (_: unknown, row: FactoryDayBinLoadRow) =>
                row.item ? itemLabel(row.item) : <Typography.Text type="secondary">—</Typography.Text>,
        },
        {
            title: 'Kg',
            key: 'kg',
            align: 'right' as const,
            render: (_: unknown, row: FactoryDayBinLoadRow) => fmtQty(row.quantity_kg),
        },
        {
            title: 'Bag',
            key: 'bag',
            render: (_: unknown, row: FactoryDayBinLoadRow) =>
                row.bag_barcode ?? <Typography.Text type="secondary">no barcode</Typography.Text>,
        },
        {
            title: 'Who',
            key: 'who',
            render: (_: unknown, row: FactoryDayBinLoadRow) =>
                row.user ?? <Typography.Text type="secondary">—</Typography.Text>,
        },
    ];

    return (
        <Space direction="vertical" size="large" style={{ width: '100%' }}>
            <Space direction="vertical" size={0}>
                <Typography.Title level={3} style={{ marginBottom: 0 }}>
                    Common resin input
                </Typography.Title>
                <Typography.Text type="secondary">
                    This factory has ONE resin input point. Every bag is loaded into it and no bag is ever tied to a
                    machine — so what you see below is the whole input: what was loaded, what every machine's batches
                    calculated out, and what should therefore still be in it. Nothing is consumed here and loading
                    posts nothing to Tally — Complete Batch on the Shift Floor takes the material out by itself.
                </Typography.Text>
            </Space>

            {warehousesUnavailable && (
                <Alert
                    type="warning"
                    showIcon
                    message="You can see the day bin but not set it up or load it by hand"
                    description="Choosing a warehouse and moving material both need Inventory access, which this login does not have. Ask for Inventory access (View to pick, Manage to load)."
                />
            )}

            {!configured && (
                <Alert
                    type="info"
                    showIcon
                    message="No day bin warehouse chosen yet"
                    description={
                        <Space direction="vertical" style={{ width: '100%' }}>
                            <Typography.Text>
                                Pick which warehouse is the factory day bin. Until then everything works exactly as it
                                does today.
                            </Typography.Text>
                            <Space wrap>
                                <Select
                                    style={{ minWidth: 280 }}
                                    placeholder="Choose the day bin warehouse…"
                                    options={warehouseOptions}
                                    showSearch
                                    optionFilterProp="label"
                                    value={pickingWarehouse ?? undefined}
                                    onChange={(value) => setPickingWarehouse(value)}
                                />
                                <Button
                                    type="primary"
                                    disabled={pickingWarehouse === null}
                                    loading={chooseWarehouse.isPending}
                                    onClick={() => chooseWarehouse.mutate(pickingWarehouse)}
                                >
                                    Save
                                </Button>
                            </Space>
                        </Space>
                    }
                />
            )}

            {configured && (
                <>
                    {traceabilityEnabled && (
                        <Card size="small" title="Scan a bag into the common input">
                            <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
                                Just scan — there is nothing else to choose, because there is one input point and a bag
                                is never tied to a machine. The scanner types the code and presses Enter by itself;
                                Enter again (or the button) loads the whole bag, and lowering the kg loads a part bag
                                with the rest left in it. A whole pallet goes in one bag after another without a tap.
                            </Typography.Paragraph>
                            {scanSuccess && (
                                <Alert type="success" showIcon message={scanSuccess} style={{ marginBottom: 12 }} />
                            )}
                            {scanError && (
                                <Alert type="error" showIcon message={scanError} style={{ marginBottom: 12 }} />
                            )}
                            {/* THE BARCODE, AND NOTHING ABOVE IT. The machine
                                selector that used to sit here is deleted, not
                                hidden: a bag is never assigned to a machine, so
                                there is no field for the gun to be waiting on. */}
                            <Input
                                ref={scanInputRef}
                                autoFocus
                                size="large"
                                value={scanCode}
                                onChange={(e) => setScanCode(e.target.value)}
                                onPressEnter={submitScan}
                                placeholder="Scan or type the bag barcode, then Enter"
                                style={{ maxWidth: 480 }}
                            />
                            {bagLookup.isPending && (
                                <Typography.Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
                                    Looking up the bag…
                                </Typography.Paragraph>
                            )}
                            {scannedBag && (
                                <div style={{ marginTop: 12, maxWidth: 480 }}>
                                    <Descriptions size="small" column={1} bordered style={{ marginBottom: 12 }}>
                                        <Descriptions.Item label="Bag">{scannedBag.barcode}</Descriptions.Item>
                                        <Descriptions.Item label="Material">
                                            {scannedBag.lot?.item ? itemLabel(scannedBag.lot.item) : '—'}
                                        </Descriptions.Item>
                                        <Descriptions.Item label="Remaining in bag (kg)">
                                            {fmtQty(scannedBag.remaining_kg)}
                                        </Descriptions.Item>
                                    </Descriptions>
                                    <Form layout="vertical" component="div">
                                        <Form.Item
                                            label="Kg to load"
                                            extra="The whole bag unless you lower it for a part bag."
                                        >
                                            <InputNumber
                                                min={0.001}
                                                max={Number(scannedBag.remaining_kg)}
                                                value={scanKg}
                                                onChange={(value) => setScanKg(value)}
                                                style={{ width: '100%' }}
                                            />
                                        </Form.Item>
                                    </Form>
                                    {/* THE REFUSED SCAN. The server's sentence
                                        first, WORD FOR WORD — it names the
                                        estimated kg still in the common input
                                        AND says that figure does not count
                                        batches still running, and both halves
                                        are the whole reason anyone is being
                                        asked. Then the four words, and nothing
                                        else: NO WEIGHT IS ASKED FOR HERE OR
                                        ANYWHERE. */}
                                    {scanAck !== null && (
                                        <Alert
                                            type="warning"
                                            showIcon
                                            style={{ marginBottom: 12 }}
                                            message="Say what happened to the material already in the common input"
                                            description={
                                                <Space direction="vertical" style={{ width: '100%' }}>
                                                    <Typography.Text>{scanAck.message}</Typography.Text>
                                                    <Select
                                                        style={{ width: '100%' }}
                                                        placeholder="What happened to it?"
                                                        options={BALANCE_ACK_REASON_OPTIONS}
                                                        value={scanAck.reason ?? undefined}
                                                        onChange={(value) =>
                                                            setScanAck((prev) =>
                                                                prev === null ? prev : { ...prev, reason: value },
                                                            )
                                                        }
                                                    />
                                                    <Input.TextArea
                                                        rows={2}
                                                        maxLength={200}
                                                        placeholder="Anything worth adding (optional)"
                                                        value={scanAck.note}
                                                        onChange={(e) =>
                                                            setScanAck((prev) =>
                                                                prev === null
                                                                    ? prev
                                                                    : { ...prev, note: e.target.value },
                                                            )
                                                        }
                                                    />
                                                </Space>
                                            }
                                        />
                                    )}
                                    <Button
                                        type="primary"
                                        block
                                        onClick={() =>
                                            scanAck !== null && scanAck.reason !== null
                                                ? submitBagLoad({ reason: scanAck.reason, note: scanAck.note })
                                                : submitBagLoad()
                                        }
                                        loading={bagLoad.isPending}
                                        disabled={
                                            !scanKg ||
                                            scanKg <= 0 ||
                                            // A pending refusal with no word picked
                                            // yet: the button stays put and says
                                            // what it wants, rather than firing a
                                            // post that can only be refused again.
                                            (scanAck !== null && scanAck.reason === null)
                                        }
                                    >
                                        {scanAck !== null
                                            ? scanAck.reason === null
                                                ? 'Pick a reason first'
                                                : 'Confirm and load into the common input'
                                            : 'Load into the common input'}
                                    </Button>
                                </div>
                            )}
                        </Card>
                    )}

                    {/* ESTIMATED REMAINING IN THE COMMON INPUT — the owner's
                        replacement for the day's reconciliation, and since the
                        2-Aug correction a single table rather than a card per
                        machine. First, because it is the question the floor
                        actually asks ("how much resin is left?"), and because
                        the balances below answer a different one (where the
                        stock is, in the books). */}
                    <Card size="small" title="Estimated remaining in the common input">
                        <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
                            Everything loaded into the one factory input, less what every machine's batches calculated
                            out — resin consumed is (pieces packed and sent to QC + pieces rejected during production)
                            × the standard weight per piece, plus lumps. Counted from the first load of each material,
                            so material burnt before anyone recorded a load is deliberately not subtracted. It is an
                            estimate, not a weight — nothing here is weighed, and it does not count batches still
                            running, whose material has left the input but has not been calculated out yet. The factory
                            never physically counts or weighs this input (owner, 07-Aug — DEC-20260807-007), so the
                            figure is never re-anchored: an unscanned bag or a spill stays in it for good, and it
                            drifts over time. Read it as an estimate, never as a counted fact — stock truth comes from
                            the Tally reconcile.
                        </Typography.Paragraph>

                        {commonResinUnavailable ? (
                            <Typography.Text type="secondary">
                                The common-input estimate could not be loaded. Everything else on this page is
                                unaffected.
                            </Typography.Text>
                        ) : commonResinLoading ? (
                            <Typography.Text type="secondary">
                                Working out what is left in the common input…
                            </Typography.Text>
                        ) : (commonResin ?? []).length === 0 ? (
                            // NOT "the input is empty". The server answers with
                            // no rows at all until something has been loaded,
                            // and printing zeros would be a reading nobody took.
                            //
                            // The second sentence depends on whether there IS a
                            // scan panel: with barcode traceability off the card
                            // above does not render, and pointing at it would
                            // send somebody looking for a control this
                            // deployment does not have.
                            <Typography.Text type="secondary">
                                Nothing has been loaded into the common input yet, so there is no baseline to estimate
                                against — this is not a reading of an empty input.{' '}
                                {traceabilityEnabled
                                    ? 'It fills in from the first scan above.'
                                    : 'Barcode scanning is switched off for this factory, so nothing feeds it: material still moves out of the store below, but no load movement is recorded for it.'}
                            </Typography.Text>
                        ) : (
                            <Table<CommonResinMaterial>
                                rowKey={(row) => row.item_id}
                                size="small"
                                columns={commonResinColumns}
                                dataSource={commonResin ?? []}
                                pagination={false}
                                scroll={{ x: 'max-content' }}
                            />
                        )}
                    </Card>

                    <Card
                        size="small"
                        title={
                            <Space wrap>
                                <span>
                                    Out of the store, on the floor: {dayBinWarehouse.code} — {dayBinWarehouse.name}
                                </span>
                                {dayBinWarehouse.tally_guid === null && (
                                    <Tag>Internal bin — Tally keeps seeing the main godown</Tag>
                                )}
                            </Space>
                        }
                        extra={
                            <Space wrap>
                                <Select
                                    size="small"
                                    style={{ minWidth: 220 }}
                                    placeholder="Change warehouse…"
                                    options={warehouseOptions}
                                    showSearch
                                    optionFilterProp="label"
                                    value={pickingWarehouse ?? undefined}
                                    onChange={(value) => setPickingWarehouse(value)}
                                />
                                <Button
                                    size="small"
                                    disabled={pickingWarehouse === null || pickingWarehouse === dayBinWarehouse.id}
                                    loading={chooseWarehouse.isPending}
                                    onClick={() => chooseWarehouse.mutate(pickingWarehouse)}
                                >
                                    Change
                                </Button>
                            </Space>
                        }
                    >
                        <Table<BalanceRow>
                            rowKey={(row) => row.item_id}
                            size="middle"
                            loading={isLoading}
                            columns={balanceColumns}
                            dataSource={balanceRows}
                            pagination={false}
                            scroll={{ x: 'max-content' }}
                            // A bin nobody has loaded yet is a normal state:
                            // one plain line, never a table full of blanks.
                            locale={{
                                emptyText: (
                                    <Typography.Text type="secondary">
                                        {traceabilityEnabled
                                            ? 'Nothing has left the store yet — scan a bag into the common input above.'
                                            : 'Nothing has left the store yet — load material below.'}
                                    </Typography.Text>
                                ),
                            }}
                        />
                    </Card>

                    <Collapse
                        activeKey={manualOpenKeys}
                        onChange={(keys) => setManualOpenKeys(keys as string[])}
                        items={[
                            {
                                key: 'manual',
                                label: 'Load without a barcode — unlabelled bags or opening stock',
                                children: (
                                    <Form
                                        form={manualForm}
                                        layout="vertical"
                                        onFinish={(values) => manualLoad.mutate(values)}
                                        initialValues={{ loaded_at: dayjs() }}
                                    >
                                        {/* WHAT THIS DOOR CANNOT DO. A manual
                                            load is a plain stock transfer and
                                            writes no day-bin load movement, so
                                            it moves the balance below but not
                                            the common input's estimate above.
                                            Said here rather than discovered
                                            later, when the estimate reads short
                                            by exactly a load somebody made. */}
                                        <Typography.Text
                                            type="secondary"
                                            style={{ display: 'block', fontSize: 12, marginBottom: 8 }}
                                        >
                                            A manual load records no load movement, so it does not raise the common
                                            input's estimated remaining — scan the bag above whenever it carries a
                                            barcode.
                                        </Typography.Text>
                                        <Row gutter={[12, 0]}>
                                            <Col xs={24} md={8}>
                                                <Form.Item
                                                    name="item_id"
                                                    label="Material"
                                                    extra={
                                                        rawMaterialsUnavailable
                                                            ? 'Could not load the raw-material list — reload the page, or ask for Production access if this keeps happening.'
                                                            : undefined
                                                    }
                                                    rules={[{ required: true, message: 'Pick the material' }]}
                                                >
                                                    <Select
                                                        size="large"
                                                        options={rawMaterialOptions}
                                                        showSearch
                                                        optionFilterProp="label"
                                                        placeholder="Resin / Masterbatch / …"
                                                        notFoundContent={
                                                            <Empty
                                                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                                                                description="No raw materials (kg items) found"
                                                            />
                                                        }
                                                    />
                                                </Form.Item>
                                            </Col>
                                            <Col xs={12} md={4}>
                                                <Form.Item
                                                    name="quantity"
                                                    label="Kg"
                                                    rules={[
                                                        { required: true, message: 'Enter the kg' },
                                                        // The transfer endpoint requires gt:0 — say so here
                                                        // rather than letting the floor read a 422.
                                                        {
                                                            validator: (_, value) =>
                                                                value === undefined || value === null || value > 0
                                                                    ? Promise.resolve()
                                                                    : Promise.reject(new Error('Must be more than zero')),
                                                        },
                                                    ]}
                                                >
                                                    <InputNumber
                                                        size="large"
                                                        min={0}
                                                        style={{ width: '100%' }}
                                                        placeholder="Kg"
                                                    />
                                                </Form.Item>
                                            </Col>
                                            <Col xs={12} md={6}>
                                                <Form.Item name="loaded_at" label="Date & time">
                                                    <DatePicker
                                                        size="large"
                                                        showTime
                                                        format="DD MMM YYYY HH:mm"
                                                        style={{ width: '100%' }}
                                                    />
                                                </Form.Item>
                                            </Col>
                                        </Row>
                                        {/* Where it came from: STATED in one
                                            line, not picked. One factory, one
                                            place — and when the books cannot
                                            name it, the panel says exactly that
                                            and refuses rather than moving stock
                                            out of a warehouse nobody chose. */}
                                        {factoryStore ? (
                                            <Typography.Text
                                                type="secondary"
                                                style={{ display: 'block', fontSize: 12, marginBottom: 8 }}
                                            >
                                                Comes out of {factoryStoreLabel(factoryStore)}.
                                            </Typography.Text>
                                        ) : (
                                            <Alert
                                                type="warning"
                                                showIcon
                                                style={{ marginBottom: 8 }}
                                                message="No store to load from"
                                                description={
                                                    <Typography.Text>
                                                        The factory store could not be worked out — there is no single
                                                        Tally-linked warehouse to move this material out of. Link the
                                                        factory godown on <Link to="/inventory/warehouses">Warehouses</Link>{' '}
                                                        and this panel works again.
                                                    </Typography.Text>
                                                }
                                            />
                                        )}
                                        <Button
                                            type="primary"
                                            size="large"
                                            htmlType="submit"
                                            disabled={!factoryStore}
                                            loading={manualLoad.isPending}
                                        >
                                            Load out of the store
                                        </Button>
                                    </Form>
                                ),
                            },
                        ]}
                    />

                    <Card size="small" title="Loaded today">
                        <Table<FactoryDayBinLoadRow>
                            rowKey={(row) => row.id}
                            size="small"
                            loading={isLoading}
                            columns={loadColumns}
                            dataSource={dayBin?.todays_loads ?? []}
                            pagination={false}
                            scroll={{ x: 'max-content' }}
                            locale={{
                                emptyText: (
                                    <Typography.Text type="secondary">
                                        Nothing has been loaded out of the store yet today.
                                    </Typography.Text>
                                ),
                            }}
                        />
                    </Card>
                </>
            )}
        </Space>
    );
}
