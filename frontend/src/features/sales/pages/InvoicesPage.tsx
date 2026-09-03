import { useQuery } from '@tanstack/react-query';
import { Button, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { listInvoices } from '@/features/sales/api';
import { hasActiveFilters } from '@/features/sales/filters';
import SalesDocumentDrawer, { TallyLinkCell } from '@/features/sales/SalesDocumentDrawer';
import SalesFilterBar from '@/features/sales/SalesFilterBar';
import type { Invoice, InvoiceStatus } from '@/features/sales/types';
import { useSalesListParams } from '@/features/sales/useSalesListParams';
import { listEmptyText } from '@/features/sales/drawer';

const statusColor: Record<InvoiceStatus, string> = {
    draft: 'default',
    issued: 'blue',
    paid: 'green',
};

/**
 * INVOICE HISTORY — read only (DEC-20260903-004).
 *
 * The ERP raises no sales invoice of its own: Tally originates the invoice,
 * the e-invoice and the IRN (DEC-20260831-012), and the ERP imports that
 * voucher and matches it to the order (DEC-20260902-046). The New Invoice
 * button, its form and the Issue action are gone with the routes behind
 * them; no proforma replaces them (DEC-20260902-052).
 *
 * The page stays because the rows do. Every invoice the ERP wrote before the
 * retirement is still readable here and from the order's trace, and the
 * receivables and GST figures still stand on them.
 */
export default function InvoicesPage() {
    // The filters, the page and the open drawer all live in the URL — a
    // pasted link is the same view. The server does the narrowing.
    const { filters, setFilters, setPage, target, openTarget, closeTarget } = useSalesListParams('invoice');
    const filtersActive = hasActiveFilters('invoice', filters);

    const { data, isLoading, isPending, isError, error } = useQuery({
        queryKey: ['sales', 'invoices', 'list', filters],
        queryFn: () => listInvoices(filters),
        placeholderData: (previous) => previous,
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Invoices</Typography.Title>
                {/* A LABEL, not a paragraph: the one fact a reader needs is
                    that nothing new lands here and Tally is where the invoice
                    is now raised. */}
                <Tag color="default">History · Tally raises invoices</Tag>
            </Space>

            <SalesFilterBar kind="invoice" filters={filters} onChange={setFilters} />

            <Table<Invoice>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                locale={{
                    emptyText: listEmptyText({ isPending, isError, error }, 'invoice', filtersActive),
                }}
                pagination={
                    data?.meta
                        ? {
                              current: data.meta.current_page,
                              pageSize: data.meta.per_page,
                              total: data.meta.total,
                              showSizeChanger: true,
                              pageSizeOptions: [20, 50, 100],
                              showTotal: (total) => `${total} invoice${total === 1 ? '' : 's'}`,
                              onChange: (page, pageSize) => setPage(page, pageSize),
                          }
                        : false
                }
                columns={[
                    { title: 'Number', render: (_, row) => <strong>{row.document_number ?? `INV-${row.id}`}</strong> },
                    {
                        title: (
                            // Paid is never set by this ERP: receipts are recorded
                            // in Tally (DEC-20260809-003). Said on the column so a
                            // long-issued invoice is not read as unpaid.
                            <Tooltip title="Paid: recorded in Tally, not here — this ERP never marks an invoice paid.">
                                <span>Status</span>
                            </Tooltip>
                        ),
                        dataIndex: 'status',
                        render: (status: InvoiceStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Customer', render: (_, row) => row.customer?.name ?? '—' },
                    {
                        title: 'SO',
                        render: (_, row) => (
                            <Button
                                type="link"
                                size="small"
                                style={{ padding: 0 }}
                                onClick={() => openTarget({ kind: 'sales_order', id: row.sales_order?.id ?? row.sales_order_id })}
                            >
                                {row.sales_order?.document_number ?? `SO-${row.sales_order_id}`}
                            </Button>
                        ),
                    },
                    { title: 'Invoice Date', dataIndex: 'invoice_date' },
                    { title: 'Due Date', dataIndex: 'due_date' },
                    { title: 'Lines', render: (_, row) => row.lines.length },
                    {
                        title: (
                            <Tooltip title="Where this invoice's Sales voucher stands in the Tally sync queue. A dash means nothing was ever queued — a draft queues nothing.">
                                <span>Tally</span>
                            </Tooltip>
                        ),
                        render: (_, row) => <TallyLinkCell link={row.tally} compact />,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button size="small" onClick={() => openTarget({ kind: 'invoice', id: row.id })}>
                                View
                            </Button>
                        ),
                    },
                ]}
            />

            {/* The trace drawer: the order it bills, lines, and where its
                Sales voucher stands with Tally. */}
            <SalesDocumentDrawer target={target} onClose={closeTarget} onOpen={openTarget} />
        </>
    );
}
