import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, Empty, Popconfirm, Select, Space, Table, Tag, Typography, message } from 'antd';
import { useState } from 'react';
import {
    confirmTallyVendorFields,
    confirmTallyVendorNew,
    dismissTallyVendorDifference,
    fetchTallyVendorReview,
    setTallyVendorGroups,
} from '@/features/procurement/api';
import { apiMessage } from '@/features/procurement/components/apiMessage';
import type { TallyVendorReviewRow } from '@/features/procurement/types';

/**
 * TALLY PROPOSES; ADMIN/ACCOUNTS DECIDES.
 *
 * The masters pull mirrors Tally's ledgers and stops there. This screen is
 * where a party Tally knows about becomes — or corrects — a vendor in the ERP
 * master, one confirmed decision at a time. Nothing on it happens on a timer,
 * and reading it changes nothing.
 *
 * THREE KINDS OF ROW, and the third is the one worth reading twice:
 *
 *   NEW        a party with no ERP vendor. Confirm creates one, with a code
 *              minted the same way the vendor form mints it.
 *   CONFLICT   a matched vendor whose recorded details Tally now disagrees
 *              with. Each field is confirmed or set aside on its own — taking
 *              the GSTIN does not touch the phone number somebody typed.
 *   AMBIGUOUS  a GSTIN that could mean more than one party. NOTHING is
 *              offered to apply. In the live company's own books 23 GSTINs sit
 *              on more than one ledger, two Sundry Creditors among them
 *              sharing one, so a GSTIN is not an identity here and this screen
 *              will not pretend otherwise. A person resolves it in Tally, or
 *              links the vendor by hand.
 *
 * WHICH GROUPS ARE VENDOR GROUPS IS AN OWNER CHOICE, and it defaults to none.
 * Sundry Creditors is not a list of suppliers: this factory's own books carry,
 * among the parties, an INTEREST ledger whose name differs from a real
 * supplier's by two letters and the company's second GST registration.
 *
 * Owner/Accounts only, on both halves (FC-06) — the API refuses everyone else.
 */

const FIELD_LABELS: Record<string, string> = {
    name: 'Vendor name',
    email: 'Email',
    phone: 'Phone',
    gstin: 'GSTIN',
    state_code: 'State code',
    tally_ledger_name: 'Tally ledger name',
};

function Synced({ at }: { at: string | null }) {
    if (at === null) return <Typography.Text type="secondary">not yet synced</Typography.Text>;

    return (
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            Tally · synced {new Date(at).toLocaleString()}
        </Typography.Text>
    );
}

export default function TallyVendorReviewPage() {
    const queryClient = useQueryClient();
    const [selected, setSelected] = useState<Record<string, string[]>>({});

    const { data, isLoading, isError, error } = useQuery({
        queryKey: ['tally-vendor-review'],
        queryFn: fetchTallyVendorReview,
        retry: false,
    });

    const refresh = () => void queryClient.invalidateQueries({ queryKey: ['tally-vendor-review'] });

    const groups = useMutation({
        mutationFn: setTallyVendorGroups,
        onSuccess: () => { void message.success('Vendor source groups saved.'); refresh(); },
        onError: (err) => void message.error(apiMessage(err, 'The server refused that group selection.')),
    });

    const createVendor = useMutation({
        mutationFn: confirmTallyVendorNew,
        onSuccess: () => { void message.success('Vendor created from the Tally ledger.'); refresh(); },
        onError: (err) => void message.error(apiMessage(err, 'The server refused to create that vendor.')),
    });

    const applyFields = useMutation({
        mutationFn: ({ guid, vendorId, fields }: { guid: string; vendorId: number; fields: string[] }) =>
            confirmTallyVendorFields(guid, vendorId, fields),
        onSuccess: () => { void message.success('Vendor master updated from Tally.'); refresh(); },
        onError: (err) => void message.error(apiMessage(err, 'The server refused that update.')),
    });

    const dismiss = useMutation({
        mutationFn: ({ guid, field }: { guid: string; field: string }) => dismissTallyVendorDifference(guid, field),
        onSuccess: () => { void message.success('Set aside. It returns if Tally later says something different.'); refresh(); },
        onError: (err) => void message.error(apiMessage(err, 'The server refused that dismissal.')),
    });

    if (isError) {
        return (
            <Alert
                type="warning"
                showIcon
                message="Not available to this login"
                description={apiMessage(error, 'Supplier details from Tally are Owner/Accounts only (FC-06).')}
            />
        );
    }

    const rows = data?.rows ?? [];

    const columns = [
        {
            title: 'Tally ledger',
            key: 'ledger',
            render: (_: unknown, row: TallyVendorReviewRow) => (
                <div>
                    <div><Typography.Text strong>{row.ledger_name}</Typography.Text></div>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>{row.ledger_group ?? 'no group'}</Typography.Text>
                    <div><Synced at={row.tally_synced_at} /></div>
                </div>
            ),
        },
        {
            title: 'What is owed',
            key: 'kind',
            render: (_: unknown, row: TallyVendorReviewRow) => {
                if (row.kind === 'new') {
                    return (
                        <Space direction="vertical" size={2}>
                            <Tag color="green">New vendor</Tag>
                            {row.name_clash && (
                                <Typography.Text type="warning" style={{ fontSize: 12 }}>
                                    A vendor named “{row.name_clash.name}” ({row.name_clash.code}) already exists. Resolve that
                                    first — two rows for one supplier is worse than a delay.
                                </Typography.Text>
                            )}
                        </Space>
                    );
                }

                if (row.kind === 'ambiguous') {
                    return (
                        <Space direction="vertical" size={2}>
                            <Tag color="red">GSTIN is ambiguous</Tag>
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                This GSTIN could mean {row.ambiguous_with?.map((v) => `${v.name} (${v.code})`).join(', ')}.
                                Nothing is offered to apply — resolve it in Tally, or link the vendor by hand.
                            </Typography.Text>
                        </Space>
                    );
                }

                return (
                    <Space direction="vertical" size={2}>
                        <Tag color="orange">Details differ</Tag>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            {row.vendor?.name} ({row.vendor?.code}) · matched by {row.match_basis === 'gstin' ? 'GSTIN' : 'Tally identity'}
                        </Typography.Text>
                    </Space>
                );
            },
        },
        {
            title: 'Tally says',
            key: 'proposed',
            render: (_: unknown, row: TallyVendorReviewRow) => {
                if (row.kind !== 'conflict') {
                    return (
                        <Space direction="vertical" size={0}>
                            {Object.entries(row.proposed)
                                .filter(([, value]) => value !== null)
                                .map(([field, value]) => (
                                    <Typography.Text key={field} style={{ fontSize: 12 }}>
                                        {FIELD_LABELS[field] ?? field}: {value}
                                    </Typography.Text>
                                ))}
                        </Space>
                    );
                }

                const chosen = selected[row.tally_ledger_guid] ?? [];

                return (
                    <Space direction="vertical" size={4} style={{ width: '100%' }}>
                        {row.differences.map((difference) => {
                            const isChosen = chosen.includes(difference.field);

                            return (
                                <div key={difference.field}>
                                    <Space size={6} wrap>
                                        <Button
                                            size="small"
                                            type={isChosen ? 'primary' : 'default'}
                                            onClick={() => setSelected((current) => {
                                                const existing = current[row.tally_ledger_guid] ?? [];

                                                return {
                                                    ...current,
                                                    [row.tally_ledger_guid]: isChosen
                                                        ? existing.filter((f) => f !== difference.field)
                                                        : [...existing, difference.field],
                                                };
                                            })}
                                        >
                                            {FIELD_LABELS[difference.field] ?? difference.field}
                                        </Button>
                                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                            {difference.current ?? '(blank)'} → <Typography.Text strong style={{ fontSize: 12 }}>{difference.proposed}</Typography.Text>
                                        </Typography.Text>
                                        <Button
                                            size="small"
                                            type="link"
                                            onClick={() => dismiss.mutate({ guid: row.tally_ledger_guid, field: difference.field })}
                                        >
                                            set aside
                                        </Button>
                                    </Space>
                                </div>
                            );
                        })}
                        {row.links_identity && (
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                Confirming also records which Tally ledger this vendor is, so the next sync matches exactly
                                instead of re-deriving the GSTIN guess.
                            </Typography.Text>
                        )}
                    </Space>
                );
            },
        },
        {
            title: '',
            key: 'actions',
            width: 210,
            render: (_: unknown, row: TallyVendorReviewRow) => {
                if (row.kind === 'new') {
                    return (
                        <Space>
                            <Popconfirm
                                title="Create this vendor?"
                                description="A vendor code is minted the same way the vendor form mints one."
                                onConfirm={() => createVendor.mutate(row.tally_ledger_guid)}
                            >
                                <Button size="small" type="primary" loading={createVendor.isPending}>Create vendor</Button>
                            </Popconfirm>
                            <Button
                                size="small"
                                onClick={() => dismiss.mutate({ guid: row.tally_ledger_guid, field: '*' })}
                            >
                                Not a vendor
                            </Button>
                        </Space>
                    );
                }

                if (row.kind === 'ambiguous') {
                    return (
                        <Button size="small" onClick={() => dismiss.mutate({ guid: row.tally_ledger_guid, field: '*' })}>
                            Not a vendor
                        </Button>
                    );
                }

                const chosen = selected[row.tally_ledger_guid] ?? [];

                return (
                    <Button
                        size="small"
                        type="primary"
                        disabled={chosen.length === 0 || row.vendor === undefined}
                        loading={applyFields.isPending}
                        onClick={() => {
                            if (!row.vendor) return;
                            applyFields.mutate({ guid: row.tally_ledger_guid, vendorId: row.vendor.vendor_id, fields: chosen });
                            setSelected((current) => ({ ...current, [row.tally_ledger_guid]: [] }));
                        }}
                    >
                        Confirm {chosen.length > 0 ? `${chosen.length} change${chosen.length === 1 ? '' : 's'}` : 'selected'}
                    </Button>
                );
            },
        },
    ];

    return (
        <Space direction="vertical" size={16} style={{ width: '100%' }}>
            <Typography.Title level={3} style={{ marginBottom: 0 }}>Tally vendor review</Typography.Title>
            <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>
                What Tally holds about a supplier, against what this ERP records. Nothing here changes the vendor master until
                you confirm it, and nothing is read from Tally on a timer — the agent pulls when an operator asks it to.
                {' '}<Synced at={data?.last_synced_at ?? null} />
            </Typography.Paragraph>

            <Card size="small" title="Which Tally groups hold your suppliers">
                <Typography.Paragraph type="secondary" style={{ fontSize: 13 }}>
                    Nothing is proposed until these are named, and that is deliberate: a creditors group is not a list of
                    suppliers. Choose from the groups actually present in the mirror.
                </Typography.Paragraph>
                <Space wrap>
                    <Select
                        mode="multiple"
                        style={{ minWidth: 420 }}
                        placeholder="Select the vendor ledger groups"
                        value={data?.groups ?? []}
                        onChange={(next: string[]) => groups.mutate(next)}
                        loading={groups.isPending}
                        options={Object.entries(data?.group_census ?? {}).map(([group, count]) => ({
                            value: group,
                            label: `${group} (${count})`,
                        }))}
                    />
                </Space>
            </Card>

            <Table
                rowKey="tally_ledger_guid"
                loading={isLoading}
                dataSource={rows}
                columns={columns}
                pagination={{ pageSize: 25, showSizeChanger: true }}
                locale={{
                    emptyText: (
                        <Empty
                            description={
                                (data?.groups ?? []).length === 0
                                    ? 'Name the vendor ledger groups above to see what Tally proposes.'
                                    : 'Nothing owed — every mirrored party matches the vendor master.'
                            }
                        />
                    ),
                }}
            />
        </Space>
    );
}
