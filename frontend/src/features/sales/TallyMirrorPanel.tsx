import { useQuery } from '@tanstack/react-query';
import { InfoCircleOutlined } from '@ant-design/icons';
import { Space, Tag, Tooltip, Typography } from 'antd';
import { getTallyMirror } from '@/features/sales/api';

/**
 * WHAT THESE PAGES ARE NOT — one line above the Sales Orders and Invoices
 * lists, no more.
 *
 * Real sales are invoiced in Tally (DEC-20260809-003). The ERP has no read
 * path from Tally, so Tally-side Sales / Sales Order vouchers are NOT
 * mirrored here, and the table below is the ERP-originated subset only. An
 * empty table must never read as "no sales" — this line is what says so.
 *
 * IT USED TO BE A FOUR-SENTENCE ALERT BOX, and the owner asked why the
 * screens carry so many notes (26-Aug, the same instruction as 573179f:
 * "dont add text on the applicaiton, they will not sit and ready this").
 * The honesty is not allowed to vanish — so it is now ONE line of label and
 * tags, and the server's full sentences (the body, the unvalidated-builder
 * note, the payments note) moved into the info tooltip for whoever asks.
 * EVERY SENTENCE IS STILL THE SERVER'S (GET /sales/tally-mirror): nothing
 * here types copy that could drift from the decision it stands on.
 *
 * When the endpoint cannot be read the line says THAT, in warning colour,
 * rather than vanishing — a page with nothing over an empty table is
 * exactly the misreading this exists to prevent.
 */
export default function TallyMirrorPanel() {
    const { data, isError, isPending } = useQuery({
        queryKey: ['sales', 'tally-mirror'],
        queryFn: getTallyMirror,
        staleTime: 5 * 60 * 1000,
    });

    if (isPending) {
        return (
            <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 12, fontSize: 12 }}>
                Reading what this page mirrors from Tally…
            </Typography.Text>
        );
    }

    if (isError || !data) {
        return (
            <Typography.Text type="warning" style={{ display: 'block', marginBottom: 12, fontSize: 12 }}>
                Could not confirm what this page mirrors from Tally — the rows below are ERP-originated documents only.
            </Typography.Text>
        );
    }

    return (
        <Space size={8} wrap style={{ marginBottom: 12 }}>
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                {data.headline}
            </Typography.Text>
            {data.decision && <Tag style={{ marginInlineEnd: 0 }}>{data.decision}</Tag>}
            {!data.erp_invoice_builder.validated && (
                <Tag color="warning" style={{ marginInlineEnd: 0 }}>
                    voucher builder unvalidated
                </Tag>
            )}
            <Tooltip
                // The server's own sentences, verbatim — the detail a reader
                // asks for, off the page until they do.
                title={
                    <Space direction="vertical" size={4}>
                        <span>{data.body}</span>
                        <span>{data.erp_invoice_builder.note}</span>
                        <span>{data.payments_note}</span>
                    </Space>
                }
            >
                <InfoCircleOutlined style={{ color: 'rgba(0,0,0,0.45)' }} />
            </Tooltip>
        </Space>
    );
}
