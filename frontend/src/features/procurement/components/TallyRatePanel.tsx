import { useQuery } from '@tanstack/react-query';
import { Button, Space, Tag, Tooltip, Typography } from 'antd';
import { fetchTallyVendorItemRate } from '@/features/procurement/api';
import { describeGst, describeVoucher, formatRate, prefillFrom, unavailableMessage } from '@/features/procurement/tallyRates';
import type { TallyPurchaseRateQuote } from '@/features/procurement/types';

/**
 * WHAT TALLY SAYS THIS VENDOR LAST CHARGED FOR THIS ITEM, under the line
 * being priced.
 *
 * It SUGGESTS. It never decides, and it never silently changes a number: the
 * Use button is the person's act, the field stays editable afterwards, and a
 * price already typed is left alone (see prefillFrom).
 *
 * THE UNIT IS SHOWN BESIDE EVERY RATE, always. Tally quotes `674.000/Kgs.`
 * and Q40 records 28 of 382 purchase-order lines carrying two units. Where
 * the basis does not match the item's own unit the figure is still shown —
 * it is real and a buyer may want it — but Use is withheld and the reason is
 * printed, rather than a number appearing on a basis nobody checked.
 *
 * The whole surface is Owner/Accounts (FC-06). A login without that standing
 * is refused the endpoint outright, and this panel simply renders nothing.
 */

interface TallyRatePanelProps {
    vendorId: number | null | undefined;
    itemId: number | null | undefined;
    currentUnitPrice: number | null | undefined;
    onUse: (rate: number) => void;
}

function Quote({ label, quote }: { label: string; quote: TallyPurchaseRateQuote }) {
    const gst = describeGst(quote);

    return (
        <div style={{ marginTop: 2 }}>
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                {label}: <Typography.Text strong style={{ fontSize: 12 }}>{formatRate(quote)}</Typography.Text>
                {' · '}{describeVoucher(quote)}
                {gst !== '' ? ` · ${gst}` : ''}
                {quote.gst.hsn_code !== null ? ` · HSN ${quote.gst.hsn_code}` : ''}
                {' · '}{quote.party_ledger_name}
            </Typography.Text>
        </div>
    );
}

export default function TallyRatePanel({ vendorId, itemId, currentUnitPrice, onUse }: TallyRatePanelProps) {
    const enabled = typeof vendorId === 'number' && typeof itemId === 'number';

    const { data, isLoading, isError } = useQuery({
        queryKey: ['tally-vendor-item-rate', vendorId, itemId],
        queryFn: () => fetchTallyVendorItemRate(vendorId as number, itemId as number),
        enabled,
        // A rate a person is about to confirm on screen does not need to be
        // re-fetched on every focus change.
        staleTime: 5 * 60 * 1000,
        retry: false,
    });

    // Nothing to ask about yet, or the reader is not Owner/Accounts (the
    // endpoint 403s and this stays silent rather than announcing a refusal on
    // a form the person can otherwise use).
    if (!enabled || isError) return null;

    if (isLoading) {
        return (
            <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginLeft: 24 }}>
                Looking up the Tally rate…
            </Typography.Text>
        );
    }

    if (!data) return null;

    const suggestion = data.suggestion;
    const prefill = prefillFrom(data, currentUnitPrice);
    const blocked = unavailableMessage(data);

    return (
        <div style={{ marginLeft: 24, marginTop: 4, paddingLeft: 8, borderLeft: '2px solid #f0f0f0' }}>
            <Space size={6} align="center" wrap>
                <Tag color="blue" style={{ marginInlineEnd: 0 }}>Tally</Tag>
                {data.last_synced_at !== null && (
                    // The provenance the owner asked for, on every imported
                    // value: where it came from, and when it was last read.
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        synced {new Date(data.last_synced_at).toLocaleString()}
                    </Typography.Text>
                )}
                {prefill !== null && (
                    <Button size="small" type="link" style={{ padding: 0 }} onClick={() => onUse(prefill)}>
                        Use {formatRate(suggestion as TallyPurchaseRateQuote)}
                    </Button>
                )}
                {suggestion !== null && prefill === null && suggestion.may_prefill && currentUnitPrice !== null && currentUnitPrice !== undefined && (
                    <Tooltip title="A rate is already entered on this line. Clear it first to take the Tally figure.">
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>rate already entered</Typography.Text>
                    </Tooltip>
                )}
            </Space>

            {data.purchase_order !== null && <Quote label="Latest Tally PO" quote={data.purchase_order} />}
            {data.purchase_invoice !== null && <Quote label="Latest Tally invoice" quote={data.purchase_invoice} />}

            {blocked !== null && (
                <Typography.Text type="warning" style={{ display: 'block', fontSize: 12, marginTop: 2 }}>
                    {blocked}
                </Typography.Text>
            )}
        </div>
    );
}
