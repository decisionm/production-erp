import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Input, InputNumber, Modal, Segmented, Select, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import { useState } from 'react';
import { useAuthStore } from '@/features/auth/store';
import { hasManageAccess } from '@/features/auth/permissions';
import { listAllWarehouses } from '@/features/inventory/api';
import {
    getFactoryWarehouseSettings,
    listFactorySettings,
    saveFactorySetting,
    setFactoryWarehouse,
} from '@/features/production/api';
import type { FactoryWarehouseRole } from '@/features/production/api';
import {
    checkRuleValue,
    ruleAppliedLabel,
    ruleDataType,
    ruleDisplayValue,
    ruleDraftChanged,
} from '@/features/production/factoryRules';
import type { FactorySetting } from '@/features/production/types';
import { columnSorter, filterOptions, onFilterBy } from '@/lib/clientSort';
import { formatDateTime } from '@/lib/datetime';
import { ListEmpty, ListReadAlert } from '@/lib/ListEmpty';
import { showApiError } from '@/lib/showApiError';
import { TABLE_STICKY } from '@/lib/tableProps';

/**
 * Factory Rules — the settings a factory changes without a deploy.
 *
 * Two halves. The warehouses card is where the three location roles are
 * chosen (finished goods, raw material, packing material) — the floor's
 * Start Batch and the Tally voucher resolve through them. Below it, every
 * `factory_settings` row, edited in its own type: a yes/no is a Yes/No
 * control, a number is a number field, a JSON map is checked before it is
 * sent. The old screen offered one free-text box for all of them and let
 * "yes" reach a boolean the server would read as false.
 *
 * Each row also says whether anything READS it. Ten of these rows are the
 * workbook's System Config sheet loaded as data, and no screen or rule
 * consults them yet — a switch labelled "Require a reason for every
 * override" changed nothing. The server's `applied` flag is what the tag
 * shows; the list of keys behind it lives with the model, next to its
 * reader, and is pinned by FactorySettingsTest.
 */
export default function FactoryRulesTab() {
    return (
        <>
            <FactoryWarehousesCard />
            <FactoryRulesTable />
        </>
    );
}

const SETTINGS_KEY = ['production', 'factory-settings'] as const;

function FactoryRulesTable() {
    const queryClient = useQueryClient();
    const user = useAuthStore((state) => state.user);
    const canManage = hasManageAccess(user, 'production');
    const query = useQuery({ queryKey: SETTINGS_KEY, queryFn: listFactorySettings });

    const [drafts, setDrafts] = useState<Record<string, string>>({});
    const [reasons, setReasons] = useState<Record<string, string>>({});
    const [savingKey, setSavingKey] = useState<string | null>(null);

    const mutation = useMutation({
        mutationFn: saveFactorySetting,
        onMutate: ({ key }) => setSavingKey(key),
        onSettled: () => setSavingKey(null),
        onSuccess: (saved) => {
            message.success(`${saved.label ?? saved.key} saved`);
            discard(saved.key);
            queryClient.invalidateQueries({ queryKey: SETTINGS_KEY });
        },
        onError: (error) => showApiError(error),
    });

    const discard = (key: string) => {
        setDrafts(({ [key]: _dropped, ...rest }) => rest);
        setReasons(({ [key]: _dropped, ...rest }) => rest);
    };

    const rows = query.data?.data ?? [];

    return (
        <>
            <ListReadAlert state={query} entity="factory rules" />
            <Table<FactorySetting>
                rowKey="key"
                size="small"
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                loading={query.isFetching && rows.length > 0}
                dataSource={rows}
                pagination={false}
                locale={{ emptyText: <ListEmpty state={query} entity="factory rules" empty="No factory rules recorded." /> }}
                // Every rule is on screen, so the columns sort and filter
                // here, on the values the cells show.
                columns={[
                    {
                        title: 'Rule',
                        key: 'rule',
                        sorter: columnSorter((row: FactorySetting) => row.label ?? row.key, 'text'),
                        render: (_, row) => (
                            <Space direction="vertical" size={0}>
                                <Typography.Text strong>{row.label ?? row.key}</Typography.Text>
                                {row.description && (
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        {row.description}
                                    </Typography.Text>
                                )}
                                <Typography.Text code style={{ fontSize: 11 }}>
                                    {row.key}
                                </Typography.Text>
                            </Space>
                        ),
                    },
                    {
                        title: 'Value',
                        key: 'value',
                        width: 340,
                        render: (_, row) => {
                            const draft = drafts[row.key];
                            const editing = draft !== undefined;
                            const dataType = ruleDataType(row);
                            const check = checkRuleValue(dataType, draft ?? row.value ?? '');
                            const changed = editing && ruleDraftChanged(row, draft);
                            const saving = savingKey === row.key && mutation.isPending;

                            if (!canManage) {
                                return <Typography.Text>{ruleDisplayValue(row)}</Typography.Text>;
                            }

                            return (
                                <Space direction="vertical" size={4} style={{ width: '100%' }}>
                                    <RuleEditor
                                        row={row}
                                        draft={draft ?? row.value ?? ''}
                                        disabled={saving}
                                        onChange={(next) => setDrafts((state) => ({ ...state, [row.key]: next }))}
                                    />
                                    {changed && !check.ok && (
                                        <Typography.Text type="danger" style={{ fontSize: 12 }}>
                                            {check.reason}
                                        </Typography.Text>
                                    )}
                                    {changed && (
                                        <Space.Compact style={{ width: '100%' }}>
                                            <Input
                                                size="small"
                                                placeholder="Reason (optional)"
                                                maxLength={500}
                                                aria-label={`Reason for changing ${row.label ?? row.key}`}
                                                value={reasons[row.key] ?? ''}
                                                onChange={(event) =>
                                                    setReasons((state) => ({ ...state, [row.key]: event.target.value }))
                                                }
                                            />
                                            <Button
                                                size="small"
                                                type="primary"
                                                disabled={!check.ok}
                                                loading={saving}
                                                onClick={() => {
                                                    if (!check.ok) return;
                                                    const reason = reasons[row.key]?.trim();
                                                    mutation.mutate({
                                                        key: row.key,
                                                        value: check.value,
                                                        ...(reason ? { change_reason: reason } : {}),
                                                    });
                                                }}
                                            >
                                                Save
                                            </Button>
                                            <Button size="small" disabled={saving} onClick={() => discard(row.key)}>
                                                Cancel
                                            </Button>
                                        </Space.Compact>
                                    )}
                                </Space>
                            );
                        },
                    },
                    {
                        title: 'Read by',
                        key: 'applied',
                        width: 120,
                        render: (_, row) => {
                            const label = ruleAppliedLabel(row.applied);
                            const tag = <Tag color={label.tone === 'success' ? 'success' : undefined}>{label.text}</Tag>;

                            return row.applied ? (
                                tag
                            ) : (
                                <Tooltip title="No screen or rule reads this value yet. Changing it changes nothing on the floor.">
                                    {tag}
                                </Tooltip>
                            );
                        },
                    },
                    {
                        title: 'Confirmation',
                        dataIndex: 'confirmation_status',
                        key: 'confirmation_status',
                        width: 160,
                        filters: filterOptions(rows, (row) => row.confirmation_status),
                        onFilter: onFilterBy((row: FactorySetting) => row.confirmation_status),
                        render: (status: string | null) => (status ? <Tag>{status}</Tag> : '—'),
                    },
                    {
                        title: 'Last changed',
                        key: 'changed',
                        width: 220,
                        sorter: columnSorter((row: FactorySetting) => (row.changed_by ? row.updated_at : null), 'date'),
                        render: (_, row) =>
                            row.changed_by ? (
                                <Space direction="vertical" size={0}>
                                    <Typography.Text>{row.changed_by}</Typography.Text>
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        {formatDateTime(row.updated_at)}
                                    </Typography.Text>
                                    {row.change_reason && (
                                        <Typography.Text type="secondary" style={{ fontSize: 12 }} italic>
                                            {row.change_reason}
                                        </Typography.Text>
                                    )}
                                </Space>
                            ) : (
                                <Typography.Text type="secondary">—</Typography.Text>
                            ),
                    },
                ]}
            />
        </>
    );
}

function RuleEditor({
    row,
    draft,
    disabled,
    onChange,
}: {
    row: FactorySetting;
    draft: string;
    disabled: boolean;
    onChange: (next: string) => void;
}) {
    const label = row.label ?? row.key;

    switch (ruleDataType(row)) {
        case 'boolean':
            return (
                <Segmented
                    size="small"
                    disabled={disabled}
                    value={draft === 'true' || draft === 'false' ? draft : undefined}
                    options={[
                        { label: 'Yes', value: 'true' },
                        { label: 'No', value: 'false' },
                    ]}
                    onChange={(value) => onChange(String(value))}
                />
            );
        case 'integer':
            return (
                <InputNumber
                    size="small"
                    stringMode
                    precision={0}
                    disabled={disabled}
                    aria-label={label}
                    value={draft === '' ? null : draft}
                    onChange={(value) => onChange(value === null || value === undefined ? '' : String(value))}
                    style={{ width: 160 }}
                />
            );
        case 'decimal':
            return (
                <InputNumber
                    size="small"
                    stringMode
                    disabled={disabled}
                    aria-label={label}
                    value={draft === '' ? null : draft}
                    onChange={(value) => onChange(value === null || value === undefined ? '' : String(value))}
                    style={{ width: 160 }}
                />
            );
        case 'json':
            return (
                <Input.TextArea
                    size="small"
                    disabled={disabled}
                    aria-label={label}
                    autoSize={{ minRows: 1, maxRows: 6 }}
                    style={{ fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', fontSize: 12 }}
                    value={draft}
                    onChange={(event) => onChange(event.target.value)}
                />
            );
        default:
            return (
                <Input
                    size="small"
                    disabled={disabled}
                    aria-label={label}
                    value={draft}
                    onChange={(event) => onChange(event.target.value)}
                />
            );
    }
}

/**
 * The three warehouse roles the floor and the Tally voucher resolve through.
 *
 * `unsetText` is per role, not shared. The default sentence names the
 * consequence of leaving a role blank — and for packing material that
 * consequence is a different one: nothing on the floor is refused, the
 * VOUCHER is. Saying "Start Batch is REFUSED" there would be a threat the
 * software does not carry out, which is how a warning stops being read.
 * The other two roles fall back to the single Tally-linked warehouse, which
 * is safe on a one-godown factory: the resin and the bottles are both in
 * the one place Tally knows. Packing material is exactly the case where
 * that stops being true — a Packing Material Store is a SECOND named
 * location, named separately because cartons do not come out of the resin
 * store. So it has no fallback, an unset value blocks nothing on the floor,
 * and what waits for it is the Tally POST.
 */
function FactoryWarehousesCard() {
    const queryClient = useQueryClient();
    const { data: settings } = useQuery({
        queryKey: ['production', 'factory-warehouse-settings'],
        queryFn: getFactoryWarehouseSettings,
    });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses', 'all'], queryFn: listAllWarehouses });

    const mutation = useMutation({
        mutationFn: ({ role, warehouseId }: { role: FactoryWarehouseRole; warehouseId: number | null }) =>
            setFactoryWarehouse(role, warehouseId),
        onSuccess: () => {
            // The preview/readiness reads resolve through these settings, so
            // stale caches would keep showing the refusal after it is fixed.
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-warehouse-settings'] });
            queryClient.invalidateQueries({ queryKey: ['production', 'settings'] });
            message.success('Saved — the floor uses this from the next action.');
        },
        onError: (error: any) =>
            Modal.error({ title: 'Could not save', content: error?.response?.data?.message ?? 'Unexpected error.' }),
    });

    const options = (warehouses?.data ?? [])
        .filter((w) => w.is_active)
        .map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` }));

    const describe = (
        stored: number | null | undefined,
        resolved: number | null | undefined,
        unsetText = 'Nothing set and nothing resolvable — Start Batch is REFUSED until this is chosen.',
    ): string => {
        if (stored != null) return '';
        if (resolved != null) {
            const w = (warehouses?.data ?? []).find((x) => x.id === resolved);
            return `Nothing set — currently resolving to ${w ? w.name : `warehouse #${resolved}`}.`;
        }
        return unsetText;
    };

    const row = (
        label: string,
        help: string,
        role: FactoryWarehouseRole,
        stored: number | null | undefined,
        resolved: number | null | undefined,
        unsetText?: string,
    ) => (
        <div style={{ marginBottom: 12 }}>
            <Typography.Text strong style={{ display: 'block' }}>{label}</Typography.Text>
            <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>{help}</Typography.Text>
            <Select
                style={{ width: 360, maxWidth: '100%' }}
                placeholder="Choose a warehouse…"
                aria-label={label}
                showSearch
                optionFilterProp="label"
                allowClear
                value={stored ?? undefined}
                options={options}
                onChange={(v) => mutation.mutate({ role, warehouseId: v ?? null })}
            />
            {describe(stored, resolved, unsetText) && (
                <Typography.Text
                    type={resolved == null && stored == null ? 'danger' : 'secondary'}
                    style={{ fontSize: 12, display: 'block', marginTop: 4 }}
                >
                    {describe(stored, resolved, unsetText)}
                </Typography.Text>
            )}
        </div>
    );

    return (
        <Card size="small" title="Factory warehouses" style={{ marginBottom: 16 }}>
            {row(
                'Finished-goods warehouse',
                'Where produced bottles are booked when a batch completes. Start Batch is refused until this resolves.',
                'finished_goods_warehouse_id',
                settings?.finished_goods_warehouse_id,
                settings?.finished_goods_resolved_warehouse_id,
            )}
            {row(
                'Raw-material store',
                'The store that material is issued from. It is the source of every issue to production — RM Store → Production/WIP → FG Store.',
                'raw_material_warehouse_id',
                settings?.raw_material_warehouse_id,
                settings?.raw_material_resolved_warehouse_id,
            )}
            {row(
                'Packing Material Store',
                'Where cartons, trays, pouches and tape are issued from on the Tally voucher. No fallback: cartons must not come out of the resin store, so this one is never guessed.',
                'packing_material_warehouse_id',
                settings?.packing_material_warehouse_id,
                settings?.packing_material_resolved_warehouse_id,
                // The truthful consequence. Nothing on the floor is blocked —
                // the shift is real and gets recorded either way; it is the
                // Tally post that waits for this.
                'Nothing set — packing lines have no store to issue from, so the Tally voucher will not post until this is chosen. Production itself is unaffected.',
            )}
        </Card>
    );
}
