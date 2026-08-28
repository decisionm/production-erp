import { Space, Tag, Tooltip } from 'antd';
import { grnTallyStateLine } from '@/features/procurement/grnTally';
import type { GoodsReceiptNote } from '@/features/procurement/types';
import { TallyLinkCell } from '@/features/sales/SalesDocumentDrawer';
import { instant } from '@/features/tally-sync/drawer';

/**
 * WHERE THIS RECEIPT STANDS WITH TALLY — PurchaseOrderTallyCell's sibling,
 * rendered by the goods-receipt list's Tally column and the detail drawer,
 * so a state is spelled one way everywhere (the words come from
 * grnTallyStateLine, one place).
 *
 * With a queue entry it IS the Sales pages' TallyLinkCell — the same status
 * Tag the Tally Sync page uses, the voucher type and number, the deep link
 * into Tally Sync. Without one it is a single honest sentence: not sent
 * because Receipt Note posting is off (Q63), not sent because staging
 * refused (and why — an unmapped item, an unmapped vendor ledger, no
 * allowed company), queued but unreadable, or recorded before staging
 * existed. Never a dash: for a receipt "no entry" is not silence, it has a
 * reason, and the reason is what the receiving desk needs to read.
 */
export default function GoodsReceiptTallyCell({ receipt, compact = false }: { receipt: GoodsReceiptNote; compact?: boolean }) {
    const state = grnTallyStateLine(receipt);

    if (state.kind === 'link' && state.link) {
        return <TallyLinkCell link={state.link} compact={compact} />;
    }

    const recordedAt = receipt.tally_staging?.at ?? null;
    const tag = (
        <Tag color={state.color} style={{ marginInlineEnd: 0, whiteSpace: 'normal', maxWidth: compact ? 260 : 420 }}>
            {state.text}
        </Tag>
    );

    return (
        <Space direction="vertical" size={2}>
            {recordedAt ? <Tooltip title={`Recorded ${instant(recordedAt)}`}>{tag}</Tooltip> : tag}
        </Space>
    );
}
