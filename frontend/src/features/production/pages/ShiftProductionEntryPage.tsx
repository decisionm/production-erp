import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Checkbox, Col, Drawer, Form, Input, InputNumber, Modal, Radio, Row, Select, Space, Table, Tag, TimePicker, Typography } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listAllEmployees } from '@/features/hrms/api';
import { listAllItems, listAllWarehouses } from '@/features/inventory/api';
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
    ShiftProductionEntry,
    ShiftProductionEntryStatus,
    WorkCenter,
} from '@/features/production/types';

// Combines a picked "HH:mm" with today's date into a full ISO datetime for
// the API — shared by every backdate-capable modal below (Report Down,
// Close Breakdown, Mold Change, Finish Mold Change). Mirrors the same
// combine step used for Power Interruption.
function combineWithToday(today: string, time: string): string {
    return dayjs(`${today} ${time}`).toISOString();
}

const locationLabelOptions = [
    'Hoppers', 'Day Bin', 'Loose Bag', 'Store',
    'MB-Clear', 'MB-Blue', 'MB-Amber', 'MB-White', 'MB-Green', 'MB-Orange', 'MB-Black',
].map((label) => ({ value: label, label }));

const startBatchSchema = z.object({
    item_id: z.number({ error: 'Pick an item' }),
    warehouse_id: z.number({ error: 'Pick a warehouse' }),
    operator_id: z.number().optional(),
});
type StartBatchFormValues = z.infer<typeof startBatchSchema>;

const completeBatchSchema = z.object({
    batch_number: z.string().optional(),
    quantity_produced: z.number().gt(0, 'Must be greater than 0'),
    quantity_scrap: z.number().min(0).optional(),
    scrap_reason_id: z.number().optional(),
    nos_per_tray: z.number().min(0).optional(),
    no_of_trays: z.number().min(0).optional(),
    nos_per_box: z.number().min(0).optional(),
    no_of_box: z.number().min(0).optional(),
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
    const itemOptions = items?.data.map((i) => ({ value: i.id, label: `${i.sku} — ${i.name}` })) ?? [];
    const moldOptions =
        molds?.data.filter((m) => m.status === 'active').map((m) => ({ value: m.id, label: `${m.code} — ${m.name}` })) ?? [];
    // "Changed From" is a historical record of what just came out, not a
    // pick of something to install — it can be any mold regardless of
    // current status (it may have gone straight to "under repair").
    const allMoldOptions = molds?.data.map((m) => ({ value: m.id, label: `${m.code} — ${m.name}` })) ?? [];
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];
    const scrapReasonOptions = scrapReasons?.data.map((r) => ({ value: r.id, label: `${r.code} — ${r.name}` })) ?? [];
    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];

    const effectiveShiftId = selectedShiftId ?? shiftOptions[0]?.value;
    const today = new Date().toISOString().slice(0, 10);

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
    const startMutation = useMutation({
        mutationFn: (values: StartBatchFormValues) => {
            if (!startingMachine || !effectiveShiftId) throw new Error('Missing machine or shift');
            return startBatch({ ...values, work_center_id: startingMachine.id, shift_id: effectiveShiftId });
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

    const nominalWeight = completingEntry?.item.nominal_weight_grams ? Number(completingEntry.item.nominal_weight_grams) : null;
    const previewProducedKg = nominalWeight && quantityProduced ? ((quantityProduced * nominalWeight) / 1000).toFixed(4) : null;
    const previewRejectionKg = nominalWeight && quantityScrap ? ((quantityScrap * nominalWeight) / 1000).toFixed(4) : null;

    const completeMutation = useMutation({
        mutationFn: (values: CompleteBatchFormValues) => {
            if (!completingEntry) throw new Error('No batch selected');
            return completeBatch(completingEntry.id, values);
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

                    const primaryClick = () => {
                        if (down) {
                            setClosingDowntimeLog(down);
                            closeDowntimeForm.reset();
                        } else if (moldChange) {
                            setFinishingMoldChangeLog(moldChange);
                        } else if (running) {
                            setCompletingEntry(running);
                            completeForm.reset({ material_consumptions: [], scraps: [] });
                        } else {
                            setStartingMachine(wc);
                            startForm.reset();
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
                                {!down && !moldChange && (
                                    <Space size={4}>
                                        <Button
                                            size="small"
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
                                                size="small"
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

                    <Row gutter={16}>
                        <Col span={12}>
                            <Form.Item
                                label="Quantity Produced (Nos)"
                                validateStatus={completeForm.formState.errors.quantity_produced ? 'error' : ''}
                                help={completeForm.formState.errors.quantity_produced?.message}
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

                    <Typography.Text strong>Packing</Typography.Text>
                    <Row gutter={16} style={{ marginTop: 8, marginBottom: 16 }}>
                        <Col xs={12} sm={6}>
                            <Form.Item label="Nos/Tray">
                                <Controller name="nos_per_tray" control={completeForm.control} render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />} />
                            </Form.Item>
                        </Col>
                        <Col xs={12} sm={6}>
                            <Form.Item label="Trays">
                                <Controller name="no_of_trays" control={completeForm.control} render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />} />
                            </Form.Item>
                        </Col>
                        <Col xs={12} sm={6}>
                            <Form.Item label="Nos/Box">
                                <Controller name="nos_per_box" control={completeForm.control} render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />} />
                            </Form.Item>
                        </Col>
                        <Col xs={12} sm={6}>
                            <Form.Item label="Boxes">
                                <Controller name="no_of_box" control={completeForm.control} render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />} />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Space style={{ justifyContent: 'space-between', width: '100%' }}>
                        <Typography.Text strong>Material Consumption</Typography.Text>
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
                    {materialFields.fields.map((field, index) => (
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
                                    render={({ field }) => <InputNumber {...field} size="large" min={0} placeholder="Kg" style={{ width: '100%' }} />}
                                />
                            </Col>
                            <Col xs={24} sm={3}>
                                <Button danger block onClick={() => materialFields.remove(index)}>Remove</Button>
                            </Col>
                        </Row>
                    ))}

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

                    <Form.Item label="Notes (optional)" style={{ marginTop: 16 }}>
                        <Controller name="notes" control={completeForm.control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
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
