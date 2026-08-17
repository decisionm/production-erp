import { Space, Tag, Tooltip, Typography } from 'antd';
import { tallyStateLine } from '@/features/procurement/purchaseOrders';
import type { PurchaseOrder } from '@/features/procurement/types';
import { TallyLinkCell } from '@/features/sales/SalesDocumentDrawer';
import { instant } from '@/features/tally-sync/drawer';

/**
 * WHERE THIS ORDER STANDS WITH TALLY — the one cell the list's Tally column,
 * the detail drawer and the trace drawer all render, so a state is spelled
 * one way everywhere (the words come from tallyStateLine, one place).
 *
 * With a queue entry it IS the Sales pages' TallyLinkCell — the same status
 * Tag the Tally Sync page uses, the voucher type and number, the deep link
 * into Tally Sync. Without one it is a single honest sentence: not sent
 * because the owner gate is closed (Q35), not sent because the cloud
 * refused (and why), queued but unreadable, a draft, a Tally mirror. Never
 * a dash: for a purchase order "no entry" is not silence, it has a reason,
 * and the reason is the fact worth reading.
 */
export default function PurchaseOrderTallyCell({ order, compact = false }: { order: PurchaseOrder; compact?: boolean }) {
    const state = tallyStateLine(order);

    if (state.kind === 'link' && state.link) {
        return <TallyLinkCell link={state.link} compact={compact} />;
    }

    const recordedAt = order.tally_staging?.at ?? null;
    const tag = (
        <Tag color={state.color} style={{ marginInlineEnd: 0, whiteSpace: 'normal', maxWidth: compact ? 260 : 420 }}>
            {state.text}
        </Tag>
    );

    return (
        <Space direction="vertical" size={2}>
            {recordedAt ? <Tooltip title={`Recorded ${instant(recordedAt)}`}>{tag}</Tooltip> : tag}
            {!compact && state.kind === 'disabled' && (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    PO posting to Tally is staged but off; the first live write is owner-gated.
                </Typography.Text>
            )}
        </Space>
    );
}
