import { Button, DatePicker, Input, InputNumber, Select, Space, Typography } from 'antd';
import dayjs from 'dayjs';
import { type Control, Controller, type FieldErrors, useFieldArray } from 'react-hook-form';
import { z } from 'zod';

/**
 * THE LINES EDITOR ONE PURCHASE ORDER FORM SHARES WITH THE OTHER — the
 * create modal and the amend modal (Phase 6) edit exactly the same thing:
 * item, quantity, unit price, and the item/due-date delivery windows
 * (schedules) beneath each line. Extracted from PurchaseOrdersPage so the
 * two cannot drift in what a line is or how a schedule is validated.
 *
 * The zod schema for a line lives here too, and both forms compose it:
 * `lines: purchaseOrderLinesSchema`. The server re-validates everything
 * (StorePurchaseOrderRequest / the amend request); this is the first
 * screen's honesty, not the last word.
 */

export const purchaseOrderLineSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    quantity: z.number().gt(0, 'Quantity must be greater than 0'),
    unit_price: z.number().min(0),
    // Item/due-date delivery windows (their sum may not exceed the line —
    // the server enforces it; the arrival screen consumes them
    // oldest-due-first).
    schedules: z
        .array(
            z.object({
                due_date: z.string({ error: 'Due date is required' }),
                quantity: z.number().gt(0, 'Quantity must be greater than 0'),
                tally_reference: z.string().trim().max(64).optional(),
            }),
        )
        .optional(),
});

export const purchaseOrderLinesSchema = z.array(purchaseOrderLineSchema).min(1, 'Add at least one line');

export type PurchaseOrderLineFormValues = z.infer<typeof purchaseOrderLineSchema>;

/** The shape any form hosting these fields must have — `lines` at the top level. */
export interface LinesFormValues {
    lines: PurchaseOrderLineFormValues[];
}

/** One blank line, the way both forms start (and add) one. */
export function emptyPurchaseOrderLine(): PurchaseOrderLineFormValues {
    return {
        item_id: undefined as unknown as number,
        quantity: undefined as unknown as number,
        unit_price: undefined as unknown as number,
    };
}

/**
 * Item/due-date delivery windows for one order line — the mirror of Tally's
 * order allocations. Optional on an ERP-native order; the arrival screen
 * consumes them oldest-due-first with an editable preview.
 */
function LineSchedulesEditor({ control, lineIndex }: { control: Control<LinesFormValues>; lineIndex: number }) {
    const { fields, append, remove } = useFieldArray({ control, name: `lines.${lineIndex}.schedules` });

    return (
        <div style={{ marginLeft: 24, marginTop: 4 }}>
            {fields.map((field, scheduleIndex) => (
                <Space key={field.id} align="baseline" style={{ display: 'flex', marginTop: 4 }}>
                    <Controller
                        name={`lines.${lineIndex}.schedules.${scheduleIndex}.due_date`}
                        control={control}
                        render={({ field }) => (
                            <DatePicker
                                placeholder="Due date"
                                value={field.value ? dayjs(field.value) : null}
                                onChange={(_, dateString) => field.onChange(dateString || undefined)}
                            />
                        )}
                    />
                    <Controller
                        name={`lines.${lineIndex}.schedules.${scheduleIndex}.quantity`}
                        control={control}
                        render={({ field }) => <InputNumber {...field} min={0} placeholder="Qty" />}
                    />
                    <Controller
                        name={`lines.${lineIndex}.schedules.${scheduleIndex}.tally_reference`}
                        control={control}
                        render={({ field }) => <Input {...field} placeholder="Tally ref (optional)" style={{ width: 160 }} />}
                    />
                    <Button size="small" danger onClick={() => remove(scheduleIndex)}>×</Button>
                </Space>
            ))}
            <Button
                size="small"
                type="link"
                style={{ paddingLeft: 0 }}
                onClick={() => append({ due_date: undefined as unknown as string, quantity: undefined as unknown as number })}
            >
                + delivery schedule
            </Button>
        </div>
    );
}

interface PurchaseOrderLinesFieldsProps<T extends LinesFormValues> {
    control: Control<T>;
    errors: FieldErrors<LinesFormValues>;
    itemOptions: { value: number; label: string }[];
    /**
     * When the reader was NOT served the order's rates (FC-06 — the server
     * omits unit_price for a login without finance standing), the amend
     * form cannot prefill them: the field starts empty and must be typed
     * again. Said above the lines so nobody wonders where the rate went.
     */
    ratesNotPrefilled?: boolean;
}

/**
 * The lines block: heading, the array-level error, one row per line (item
 * picker, quantity, unit price, remove) with its schedules editor, and the
 * Add Line button. Generic over the host form's values so both forms pass
 * their own `control`; internally the fields address `lines.*` only.
 */
export default function PurchaseOrderLinesFields<T extends LinesFormValues>({
    control,
    errors,
    itemOptions,
    ratesNotPrefilled = false,
}: PurchaseOrderLinesFieldsProps<T>) {
    // The host form has more fields than `lines`; these fields only ever
    // address `lines.*`, so the narrower control type is the honest one.
    const linesControl = control as unknown as Control<LinesFormValues>;
    const { fields, append, remove } = useFieldArray({ control: linesControl, name: 'lines' });

    return (
        <>
            <Typography.Text strong>Lines</Typography.Text>
            {ratesNotPrefilled && (
                <Typography.Text type="warning" style={{ display: 'block', fontSize: 12, marginTop: 4 }}>
                    Unit prices are not shown to this login (FC-06), so they are not prefilled — enter the rate for each line
                    again. The previous lines are kept in the order's revision history.
                </Typography.Text>
            )}
            {errors.lines?.root && (
                <div style={{ color: '#ff4d4f', marginBottom: 8 }}>{errors.lines.root.message}</div>
            )}
            {fields.map((field, index) => (
                <div key={field.id} style={{ marginTop: 8 }}>
                    <Space align="baseline" style={{ display: 'flex' }}>
                        <Controller
                            name={`lines.${index}.item_id`}
                            control={linesControl}
                            render={({ field }) => (
                                <Select
                                    {...field}
                                    options={itemOptions}
                                    showSearch
                                    optionFilterProp="label"
                                    style={{ width: 220 }}
                                    placeholder="Item"
                                />
                            )}
                        />
                        <Controller
                            name={`lines.${index}.quantity`}
                            control={linesControl}
                            render={({ field }) => <InputNumber {...field} min={0} placeholder="Quantity" />}
                        />
                        <Controller
                            name={`lines.${index}.unit_price`}
                            control={linesControl}
                            render={({ field }) => <InputNumber {...field} min={0} placeholder="Unit Price" />}
                        />
                        <Button danger onClick={() => remove(index)}>Remove</Button>
                    </Space>
                    {/*
                      WHICH LINE IS WRONG, AND WHY. Only the array-level error
                      was rendered, so a line missing an item, a quantity or a
                      rate failed validation with nothing shown against it: OK
                      appeared to do nothing at all and the operator had no way
                      to find the offending row. The messages already existed in
                      form state; nothing but the rendering was missing.
                    */}
                    {(() => {
                        const line = errors.lines?.[index];
                        const messages = [
                            line?.item_id?.message,
                            line?.quantity?.message,
                            line?.unit_price?.message,
                        ].filter(Boolean) as string[];

                        return messages.length > 0 ? (
                            <div style={{ color: '#ff4d4f', marginTop: 4 }}>{messages.join(' · ')}</div>
                        ) : null;
                    })()}
                    <LineSchedulesEditor control={linesControl} lineIndex={index} />
                </div>
            ))}
            <Button type="dashed" style={{ marginTop: 8 }} onClick={() => append(emptyPurchaseOrderLine())}>
                Add Line
            </Button>
        </>
    );
}
