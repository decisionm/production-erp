import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Table, Tag, Typography } from 'antd';
import { listTallySyncEntries, retryTallySyncEntry } from '@/features/tally-sync/api';
import type { TallySyncEntry, TallySyncStatus } from '@/features/tally-sync/types';

const statusColor: Record<TallySyncStatus, string> = {
    pending: 'default',
    synced: 'green',
    failed: 'red',
};

export default function TallySyncPage() {
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['tally-sync', 'entries'], queryFn: listTallySyncEntries });

    const retryMutation = useMutation({
        mutationFn: retryTallySyncEntry,
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['tally-sync', 'entries'] }),
    });

    return (
        <>
            <Typography.Title level={3}>Tally Sync</Typography.Title>
            <Typography.Paragraph type="secondary">
                Vouchers queued for export to Tally. A local sync agent running on-site polls for pending entries
                and reports back success or failure — see TECHNICAL-DOCS.md for the agent spec. This page is the
                cloud-side view: what's queued, what synced, and what needs attention.
            </Typography.Paragraph>

            <Table<TallySyncEntry>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'ID', dataIndex: 'id' },
                    { title: 'Voucher Type', dataIndex: 'tally_voucher_type' },
                    { title: 'Source', render: (_, row) => `${row.syncable_type} #${row.syncable_id}` },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: TallySyncStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Attempts', dataIndex: 'attempts' },
                    { title: 'Error', dataIndex: 'error_message' },
                    { title: 'Synced At', dataIndex: 'synced_at' },
                    {
                        title: 'Actions',
                        render: (_, row) =>
                            row.status === 'failed' && (
                                <Button size="small" onClick={() => retryMutation.mutate(row.id)} loading={retryMutation.isPending}>
                                    Retry
                                </Button>
                            ),
                    },
                ]}
                expandable={{
                    expandedRowRender: (row) => (
                        <pre style={{ margin: 0, whiteSpace: 'pre-wrap' }}>{JSON.stringify(row.payload, null, 2)}</pre>
                    ),
                }}
            />
        </>
    );
}
