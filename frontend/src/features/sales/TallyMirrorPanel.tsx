import { useQuery } from '@tanstack/react-query';
import { Alert, Space, Tag, Typography } from 'antd';
import { getTallyMirror } from '@/features/sales/api';

/**
 * WHAT THESE PAGES ARE NOT — the panel above the Sales Orders and Invoices
 * lists.
 *
 * Real sales are invoiced in Tally (DEC-20260809-003). The ERP has no read
 * path from Tally, so Tally-side Sales / Sales Order vouchers are NOT
 * mirrored here, and the table below is the ERP-originated subset only. An
 * empty table must never read as "no sales" — this panel is what says so.
 *
 * EVERY SENTENCE IS THE SERVER'S (GET /sales/tally-mirror). Nothing here
 * types the headline, the body, the builder note or the payments note: the
 * copy stands on a decision, and copy typed on this side is copy that
 * drifts from it. What this file adds is only the labels around those
 * sentences and the tags read off the booleans.
 *
 * When the endpoint cannot be read the panel says THAT, in warning colour,
 * rather than vanishing — a page with no panel over an empty table is
 * exactly the misreading the panel exists to prevent.
 */
export default function TallyMirrorPanel() {
    const { data, isError, isPending } = useQuery({
        queryKey: ['sales', 'tally-mirror'],
        queryFn: getTallyMirror,
        staleTime: 5 * 60 * 1000,
    });

    if (isPending) {
        return null;
    }

    if (isError || !data) {
        return (
            <Alert
                type="warning"
                showIcon
                style={{ marginBottom: 16 }}
                message="Could not read what this page mirrors from Tally"
                description="The rows below are ERP-originated documents only; whether Tally-side sales are mirrored could not be confirmed just now. Refresh, or check this login's Sales access."
            />
        );
    }

    return (
        <Alert
            // Informational, never green: "not mirrored" is a fact about
            // scope, not an all-clear.
            type={data.mirrored ? 'success' : 'info'}
            showIcon
            style={{ marginBottom: 16 }}
            message={
                <Space size={8} wrap>
                    <strong>{data.headline}</strong>
                    {data.decision && <Tag>{data.decision}</Tag>}
                </Space>
            }
            description={
                <Space direction="vertical" size={6} style={{ width: '100%' }}>
                    <span>{data.body}</span>
                    <span>
                        <Typography.Text strong>Sales voucher builder:</Typography.Text>{' '}
                        {/* The tag is read off the boolean; the sentence
                            beside it is the server's note verbatim, which
                            is where "no GST" is actually said. */}
                        {data.erp_invoice_builder.validated ? (
                            <Tag color="success">validated</Tag>
                        ) : (
                            <Tag color="warning">unvalidated, no GST</Tag>
                        )}
                        <Typography.Text type="secondary">{data.erp_invoice_builder.note}</Typography.Text>
                    </span>
                    <span>
                        <Typography.Text strong>Payments:</Typography.Text>{' '}
                        {!data.payments_recorded_here && <Tag>recorded in Tally, not here</Tag>}
                        <Typography.Text type="secondary">{data.payments_note}</Typography.Text>
                    </span>
                </Space>
            }
        />
    );
}
