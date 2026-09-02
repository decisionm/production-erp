import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Alert, Form, Input, Modal, Typography, message } from 'antd';
import { useEffect } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { amendPurchaseOrder, cancelPurchaseOrder, closePurchaseOrder } from '@/features/procurement/api';
import { apiMessage } from '@/features/procurement/components/apiMessage';
import PurchaseOrderLinesFields, {
    type PurchaseOrderLineFormValues,
    purchaseOrderLinesSchema,
} from '@/features/procurement/components/PurchaseOrderLinesFields';
import { amendFormDefaults, poNumber } from '@/features/procurement/purchaseOrders';
import type { PurchaseOrder } from '@/features/procurement/types';

/**
 * THE LIFECYCLE WRITES (Phase 6, P6-01) — Close, Cancel, Amend — each an
 * append-only POST the server guards with its own state machine; the
 * modals collect the reason and print the server's refusal verbatim. None
 * of these touches stock or posts to Tally: a close records what remained
 * per line as a revision; a cancel is refused once anything was received;
 * an amend replaces a DRAFT's lines and keeps the old ones as a revision.
 * A Tally-originated order (mirror) is refused all three — it is changed in
 * Tally, never rewritten here.
 */

/** Everything the list and the drawers cached about purchase orders is stale after a write. */
function useInvalidatePurchaseOrders() {
    const queryClient = useQueryClient();

    return () => queryClient.invalidateQueries({ queryKey: ['procurement', 'purchase-orders'] });
}

// -------------------------------------------------------------- reason modal --

export type ReasonAction = 'close' | 'cancel';

const REASON_COPY: Record<ReasonAction, { title: (n: string) => string; okText: string; hint: string; done: (n: string) => string }> = {
    close: {
        title: (n) => `Close ${n}?`,
        okText: 'Close the order',
        hint:
            'Short-closes the order: what remains unreceived on each line is recorded and no further goods receipt is accepted '
            + 'against it. Nothing already received changes, no stock moves, nothing is sent to Tally.',
        done: (n) => `${n} closed — remaining quantities recorded; no stock moved and nothing was queued for Tally.`,
    },
    cancel: {
        title: (n) => `Cancel ${n}?`,
        okText: 'Cancel the order',
        hint:
            'Allowed only while NOTHING has been received against the order — the server refuses otherwise. '
            + 'No stock moves and nothing is sent to Tally.',
        done: (n) => `${n} cancelled — no stock moved and nothing was queued for Tally.`,
    },
};

const reasonSchema = z.object({
    reason: z.string().trim().min(3, 'Give the reason — it is recorded on the order').max(1000),
});
type ReasonValues = z.infer<typeof reasonSchema>;

interface ReasonModalProps {
    action: ReasonAction | null;
    order: PurchaseOrder | null;
    onClose: () => void;
    /** Called with the server's answer once the write succeeded. */
    onDone?: (order: PurchaseOrder) => void;
}

/**
 * Close / Cancel with a REQUIRED reason. The reason is not decoration: it
 * is written to `closed_reason` / `cancelled_reason` and printed on the
 * order for as long as it exists.
 */
export function PurchaseOrderReasonModal({ action, order, onClose, onDone }: ReasonModalProps) {
    const invalidate = useInvalidatePurchaseOrders();
    const { control, handleSubmit, reset, formState: { errors } } = useForm<ReasonValues>({
        resolver: zodResolver(reasonSchema),
        defaultValues: { reason: '' },
    });

    const mutation = useMutation({
        mutationFn: ({ action, id, reason }: { action: ReasonAction; id: number; reason: string }) =>
            action === 'close' ? closePurchaseOrder(id, reason) : cancelPurchaseOrder(id, reason),
        onSuccess: async (updated, variables) => {
            message.success(REASON_COPY[variables.action].done(poNumber(updated)));
            await invalidate();
            reset();
            onDone?.(updated);
            onClose();
        },
    });

    // A refusal belongs to the order it was refused for — not to the next
    // one opened in this modal.
    const resetMutation = mutation.reset;
    useEffect(() => {
        resetMutation();
        reset();
    }, [action, order?.id, resetMutation, reset]);

    const open = action !== null && order !== null;
    const copy = action ? REASON_COPY[action] : null;

    return (
        <Modal
            maskClosable={false}
            open={open}
            title={copy && order ? copy.title(poNumber(order)) : ''}
            okText={copy?.okText}
            okButtonProps={{ danger: true }}
            cancelText="Keep it"
            confirmLoading={mutation.isPending}
            onCancel={onClose}
            onOk={handleSubmit((values) => {
                if (!action || !order) return;
                mutation.mutate({ action, id: order.id, reason: values.reason.trim() });
            })}
            destroyOnHidden
        >
            {copy && (
                <Typography.Paragraph type="secondary" style={{ fontSize: 13 }}>
                    {copy.hint}
                </Typography.Paragraph>
            )}
            {mutation.isError && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message={action === 'close' ? 'Not closed' : 'Not cancelled'}
                    description={apiMessage(mutation.error, 'The server refused this change.')}
                />
            )}
            <Form layout="vertical">
                <Form.Item label="Reason" required validateStatus={errors.reason ? 'error' : ''} help={errors.reason?.message}>
                    <Controller
                        name="reason"
                        control={control}
                        render={({ field }) => <Input.TextArea {...field} rows={3} maxLength={1000} showCount autoFocus />}
                    />
                </Form.Item>
            </Form>
        </Modal>
    );
}

// --------------------------------------------------------------- amend modal --

const amendSchema = z.object({
    reason: z.string().trim().max(1000).optional(),
    lines: purchaseOrderLinesSchema,
});
type AmendValues = z.infer<typeof amendSchema>;

/** The form's starting values for one order (amendFormDefaults, tested in purchaseOrders.test.ts) plus the FC-06 flag. */
function amendDefaults(order: PurchaseOrder): { values: AmendValues; ratesNotPrefilled: boolean } {
    const { lines, ratesNotPrefilled } = amendFormDefaults(order);

    return { values: { reason: '', lines: lines as PurchaseOrderLineFormValues[] }, ratesNotPrefilled };
}

interface AmendModalProps {
    order: PurchaseOrder | null;
    onClose: () => void;
    onDone?: (order: PurchaseOrder) => void;
    itemOptions: { value: number; label: string }[];
    /** DEC-20260902-023 — forwarded to the lines editor unchanged. */
    showAdditional: boolean;
    onShowAdditionalChange: (value: boolean) => void;
    unclassifiedItemIds: ReadonlySet<number>;
}

/**
 * Amend a DRAFT: the same lines editor as the create form, prefilled from
 * the order, with an optional reason recorded on the revision. The server
 * replaces the lines and schedules in one transaction and keeps the prior
 * lines as revision N; after Send it refuses (422) — a sent order's
 * amendment changes what the vendor holds and is an owner question, not a
 * button.
 */
export function AmendPurchaseOrderModal({
    order,
    onClose,
    onDone,
    itemOptions,
    showAdditional,
    onShowAdditionalChange,
    unclassifiedItemIds,
}: AmendModalProps) {
    const invalidate = useInvalidatePurchaseOrders();
    const { control, handleSubmit, reset, setValue, formState: { errors } } = useForm<AmendValues>({
        resolver: zodResolver(amendSchema),
        defaultValues: { reason: '', lines: [] },
    });

    const defaults = order ? amendDefaults(order) : null;
    useEffect(() => {
        if (order) reset(amendDefaults(order).values);
    }, [order, reset]);

    const mutation = useMutation({
        mutationFn: ({ id, values }: { id: number; values: AmendValues }) =>
            amendPurchaseOrder(id, {
                reason: values.reason?.trim() || undefined,
                lines: values.lines.map((line) => ({
                    item_id: line.item_id,
                    quantity: line.quantity,
                    unit_price: line.unit_price,
                    ...(line.schedules && line.schedules.length > 0 ? { schedules: line.schedules } : {}),
                    // DEC-20260902-023: this whitelist maps the form's line
                    // shape onto the wire payload explicitly, so a new field
                    // on the form is silently dropped unless it is named
                    // here too — the create modal's `...values` spread has
                    // no such gate, and this one must not drift from it.
                    ...(line.unclassified_reason ? { unclassified_reason: line.unclassified_reason } : {}),
                })),
            }),
        onSuccess: async (updated) => {
            message.success(`${poNumber(updated)} amended — the previous lines are kept as a revision.`);
            await invalidate();
            onDone?.(updated);
            onClose();
        },
    });

    const resetMutation = mutation.reset;
    useEffect(() => {
        resetMutation();
    }, [order?.id, resetMutation]);

    return (
        <Modal
            maskClosable={false}
            open={order !== null}
            title={order ? `Amend ${poNumber(order)} (draft)` : ''}
            okText="Save amendment"
            confirmLoading={mutation.isPending}
            onCancel={onClose}
            onOk={handleSubmit((values) => {
                if (!order) return;
                mutation.mutate({ id: order.id, values });
            })}
            destroyOnHidden
            width={760}
        >
            <Typography.Paragraph type="secondary" style={{ fontSize: 13 }}>
                Replaces the lines and delivery schedules of this draft. The lines as they stand now are kept as a revision on
                the order. Only a draft can be amended — once sent, short-close or cancel it instead.
            </Typography.Paragraph>
            {mutation.isError && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message="Not amended"
                    description={apiMessage(mutation.error, 'The server refused this amendment.')}
                />
            )}
            <Form layout="vertical">
                <Form.Item label="Reason (recorded on the revision)" validateStatus={errors.reason ? 'error' : ''} help={errors.reason?.message}>
                    <Controller name="reason" control={control} render={({ field }) => <Input {...field} maxLength={1000} />} />
                </Form.Item>
                <PurchaseOrderLinesFields
                    control={control}
                    errors={errors}
                    itemOptions={itemOptions}
                    // The order already knows its vendor and it cannot change
                    // on an amendment, so the Tally rates under these lines
                    // are this party's throughout.
                    vendorId={order?.vendor?.id ?? null}
                    setUnitPrice={(index, rate) => setValue(`lines.${index}.unit_price`, rate, { shouldDirty: true, shouldValidate: true })}
                    ratesNotPrefilled={defaults?.ratesNotPrefilled ?? false}
                    showAdditional={showAdditional}
                    onShowAdditionalChange={onShowAdditionalChange}
                    unclassifiedItemIds={unclassifiedItemIds}
                    clearUnclassifiedReason={(index) => setValue(`lines.${index}.unclassified_reason`, undefined)}
                />
            </Form>
        </Modal>
    );
}
