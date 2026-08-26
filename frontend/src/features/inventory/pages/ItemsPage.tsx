import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tag, Tooltip, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
import {
    createItem,
    getIdentityHealth,
    listAllItems,
    listIdentityItems,
    updateItem,
} from '@/features/inventory/api';
import { IdentityHealthStrip } from '@/features/inventory/components/IdentityHealthStrip';
import { ItemIdentityFields } from '@/features/inventory/components/ItemIdentityFields';
import { VariantCell } from '@/features/inventory/components/VariantCell';
import {
    editValuesFromItem,
    type ItemFormValues,
    itemSchema,
    NEW_ITEM_DEFAULTS,
    toUpdatePayload,
} from '@/features/inventory/itemForm';
import {
    ANY_WARNING,
    badgeLabel,
    categoryColor,
    categoryLabel,
    itemDisplayName,
    itemGroupName,
    mergeIdentityRow,
    type WarningFilter,
    warningColor,
    warningLabel,
    warningTooltip,
} from '@/features/inventory/itemIdentity';
import type {
    Item,
    ItemCategoryValue,
    ItemRow,
    ItemTrackingType,
} from '@/features/inventory/types';

const trackingTypeOptions: { value: ItemTrackingType; label: string }[] = [
    { value: 'none', label: 'None' },
    { value: 'batch', label: 'Batch / Lot' },
    { value: 'serial', label: 'Serial Number' },
];
const trackingTypeColor: Record<ItemTrackingType, string> = { none: 'default', batch: 'blue', serial: 'purple' };

export default function ItemsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<ItemRow | null>(null);
    const [search, setSearch] = useState('');
    /** The health badge that is filtering the table, and the SERVER's page of it. */
    const [warning, setWarning] = useState<WarningFilter>(null);
    const [warningPage, setWarningPage] = useState(1);
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    // Fetch the full item list (not the default first 20) and paginate/search
    // client-side — with 600+ Tally-synced items the old page-1-only fetch hid
    // almost everything.
    const { data, isLoading } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });

    /*
     * Both identity reads live UNDER the items key, so the mutations below
     * already refresh them: fixing an item's category and leaving the
     * "Unclassified" count sitting at its old number is the one way this strip
     * could lie.
     */
    const { data: health } = useQuery({
        queryKey: ['inventory', 'items', 'identity', 'health'],
        queryFn: getIdentityHealth,
        retry: false,
    });

    const { data: flagged, isFetching: flaggedFetching } = useQuery({
        queryKey: ['inventory', 'items', 'identity', 'flagged', warning, warningPage],
        // The "everything flagged" badge is the endpoint's no-class case, so
        // the sentinel is dropped here rather than sent — a `warning` the
        // server does not know is a 422, by design.
        queryFn: () => listIdentityItems({
            warning: warning === ANY_WARNING ? undefined : (warning ?? undefined),
            page: warningPage,
        }),
        enabled: warning !== null,
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['inventory', 'items'] });

    const allItems = useMemo(() => data?.data ?? [], [data]);
    const itemsById = useMemo(() => new Map(allItems.map((item) => [item.id, item])), [allItems]);

    /** Which packs hang off which base product (DEC-20260821-001), from the list already loaded. */
    const variantsByBase = useMemo(() => {
        const map = new Map<number, Item[]>();
        for (const item of allItems) {
            const base = item.variant_of_item_id;
            if (base === null || base === undefined) continue;
            map.set(base, [...(map.get(base) ?? []), item]);
        }
        return map;
    }, [allItems]);

    const q = search.trim().toLowerCase();
    const searchedItems = q
        ? allItems.filter(
              (i) => i.name.toLowerCase().includes(q)
                  || i.sku.toLowerCase().includes(q)
                  || (i.display_name ?? '').toLowerCase().includes(q),
          )
        : allItems;

    // The identity read answers the identity question; the items list carries
    // the lifecycle `can` block. Laid over each other so the filtered rows keep
    // their Edit button — see mergeIdentityRow.
    const flaggedRows = useMemo(
        () => (flagged?.data ?? []).map((row) => mergeIdentityRow(row, itemsById.get(row.id))),
        [flagged, itemsById],
    );

    const rows: ItemRow[] = warning === null ? searchedItems : flaggedRows;

    const selectWarning = (next: WarningFilter) => {
        setWarning(next);
        setWarningPage(1);
    };

    const { control, handleSubmit, reset, formState: { errors } } = useForm<ItemFormValues>({
        resolver: zodResolver(itemSchema),
        defaultValues: NEW_ITEM_DEFAULTS,
    });

    const mutation = useMutation({
        mutationFn: createItem,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        setValue: setEditValue,
        formState: { errors: editErrors },
    } = useForm<ItemFormValues>({ resolver: zodResolver(itemSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, values }: { id: number; values: ItemFormValues }) =>
            updateItem(id, toUpdatePayload(values)),
        onSuccess: () => {
            invalidate();
            setEditingItem(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update item', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    // The item name is the Tally wire key (every voucher line carries it, no
    // GUID does), so on a Tally-linked item it is Tally's to change — the API
    // refuses a rename; the masters pull brings a Tally-side one across.
    const nameIsTallys = editingItem?.tally_stock_item_guid != null;

    /** A variant is never the parent of another variant — bases only, and never itself. */
    const baseItems = useMemo(
        () => allItems.filter(
            (item) => (item.variant_of_item_id === null || item.variant_of_item_id === undefined)
                && item.id !== editingItem?.id,
        ),
        [allItems, editingItem],
    );

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Items</Typography.Title>
                <Space>
                    <Input.Search
                        placeholder="Search by name or SKU"
                        allowClear
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        // A warning filter is answered a server page at a time,
                        // so searching the page in front of you would answer a
                        // different question than the box promises.
                        disabled={warning !== null}
                        style={{ width: 260 }}
                    />
                    <Button type="primary" onClick={() => setModalOpen(true)}>New Item</Button>
                </Space>
            </Space>

            <IdentityHealthStrip health={health} active={warning} onSelect={selectWarning} />

            <Table<ItemRow>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={warning === null ? isLoading : flaggedFetching}
                dataSource={rows}
                pagination={warning === null
                    ? { pageSize: 20, showSizeChanger: true, pageSizeOptions: [20, 50, 100], showTotal: (t) => `${t} items` }
                    : {
                        current: warningPage,
                        pageSize: flagged?.meta.per_page ?? 20,
                        total: flagged?.meta.total ?? 0,
                        showSizeChanger: false,
                        onChange: setWarningPage,
                        showTotal: (t) => `${t} items`,
                    }}
                columns={[
                    {
                        // Q42, answered by the owner: the SKU is an internal
                        // mapping and a lookup handle — NOT a barcode. It used
                        // to open a barcode drawer, which is exactly the thing
                        // it is not; it is text somebody types or copies.
                        title: 'SKU',
                        dataIndex: 'sku',
                        render: (sku: string) => <Typography.Text copyable={{ text: sku }}>{sku}</Typography.Text>,
                    },
                    {
                        title: 'Name',
                        dataIndex: 'name',
                        render: (_: string, row: ItemRow) => {
                            const display = row.display_name;
                            const separate = display !== null && display !== undefined
                                && display.trim() !== '' && display !== row.name;
                            return separate ? (
                                <Space direction="vertical" size={0}>
                                    <span>{display}</span>
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>{row.name}</Typography.Text>
                                </Space>
                            ) : row.name;
                        },
                    },
                    {
                        // `undefined` is a server that does not serve the field
                        // and `null` is nobody having said yet — one of those
                        // is a warning and the other is not this screen's news.
                        title: 'Category',
                        dataIndex: 'category',
                        render: (value: ItemCategoryValue | null | undefined) => {
                            if (value === undefined) return '—';
                            if (value === null) {
                                return (
                                    <Tooltip title={warningTooltip('unclassified')}>
                                        <Tag color={warningColor('unclassified')}>{warningLabel('unclassified')}</Tag>
                                    </Tooltip>
                                );
                            }
                            return <Tag color={categoryColor(value)}>{categoryLabel(value)}</Tag>;
                        },
                    },
                    {
                        title: 'Group',
                        render: (_: unknown, row: ItemRow) => itemGroupName(row) ?? '—',
                    },
                    {
                        title: 'Variant',
                        render: (_: unknown, row: ItemRow) => (
                            <VariantCell row={row} itemsById={itemsById} variantsByBase={variantsByBase} />
                        ),
                    },
                    // Only where the rows actually carry warnings — an empty
                    // column on every unfiltered row says nothing. The words
                    // are the SERVER's, note included: its sentence carries
                    // this item's own numbers and nothing here rewrites it.
                    ...(warning === null ? [] : [{
                        title: 'Warnings',
                        render: (_: unknown, row: ItemRow) => (
                            <Space size={4} wrap>
                                {(row.warnings ?? []).map((entry) => (
                                    <Tooltip
                                        key={entry.class}
                                        title={entry.note || (warningTooltip(entry.class) ?? undefined)}
                                    >
                                        <Tag color={warningColor(entry.class)} style={{ marginInlineEnd: 0 }}>
                                            {badgeLabel(entry.class, entry.label)}
                                        </Tag>
                                    </Tooltip>
                                ))}
                            </Space>
                        ),
                    }]),
                    { title: 'UOM', dataIndex: 'uom' },
                    { title: 'HSN/SAC', dataIndex: 'hsn_sac_code' },
                    { title: 'Reorder Level', dataIndex: 'reorder_level' },
                    {
                        title: 'Nominal Wt (g)',
                        dataIndex: 'nominal_weight_grams',
                        render: (v: string | null | undefined) => v ?? '—',
                    },
                    {
                        // The identity read serves a narrow row and does not
                        // carry this — a dash, not a blank tag claiming "none".
                        title: 'Tracking',
                        dataIndex: 'tracking_type',
                        render: (type: ItemTrackingType | undefined) => (type
                            ? <Tag color={trackingTypeColor[type]}>{type}</Tag>
                            : '—'),
                    },
                    {
                        // The state, in the product's two words. The Switch this
                        // replaces PUT `is_active` straight onto the item — a
                        // deactivate that ran no dependency report, took no
                        // reason and left no audit line, on the most-referenced
                        // master in the schema.
                        title: 'Status',
                        dataIndex: 'is_active',
                        render: (_: boolean, row: ItemRow) => <ConfigurationStatusTag entity="item" row={row} />,
                    },
                    {
                        // The owner's switch, made visible. The eligibility
                        // rule is backfilled from what the factory has
                        // configured and actually used, and anything it could
                        // not infer is left OFF rather than guessed at — so
                        // the residue has to be readable and correctable here,
                        // or "just switch it on" would not be true (Q56).
                        title: 'Requestable',
                        dataIndex: 'is_production_input',
                        render: (yes: boolean | undefined) => {
                            if (yes === undefined) return '—';
                            return yes ? <Tag color="blue">Production material</Tag> : <Tag>Not requestable</Tag>;
                        },
                    },
                    {
                        title: 'Actions',
                        render: (_: unknown, row: ItemRow) => {
                            const edit = () => {
                                setEditingItem(row);
                                resetEdit(editValuesFromItem(row));
                            };
                            return (
                                <Space>
                                    <Button size="small" onClick={() => navigate(`/inventory/items/${row.id}`)}>
                                        Details
                                    </Button>
                                    <ConfigurationActionsCell
                                        entity="item"
                                        id={row.id}
                                        can={row.can}
                                        recordName={`${row.sku} — ${row.name}`}
                                        onEdit={edit}
                                    />
                                </Space>
                            );
                        },
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Item"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="SKU" validateStatus={errors.sku ? 'error' : ''} help={errors.sku?.message}>
                        <Controller name="sku" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="UOM" validateStatus={errors.uom ? 'error' : ''} help={errors.uom?.message}>
                        <Controller name="uom" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="HSN/SAC Code"
                        validateStatus={errors.hsn_sac_code ? 'error' : ''}
                        help={errors.hsn_sac_code?.message}
                    >
                        <Controller name="hsn_sac_code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Reorder Level">
                        <Controller
                            name="reorder_level"
                            control={control}
                            render={({ field }) => (
                                <InputNumber {...field} min={0} style={{ width: '100%' }} />
                            )}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Nominal Weight (g)"
                        tooltip="Weight of a single unit — used to compute Kg figures on the shop floor from a piece count (Production Report's WT column). Leave blank for raw materials."
                    >
                        <Controller
                            name="nominal_weight_grams"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Tracking Type">
                        <Controller
                            name="tracking_type"
                            control={control}
                            render={({ field }) => <Select {...field} options={trackingTypeOptions} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Requestable as a production material"
                        help="When on, the floor may ask the store to issue this item. Finished goods and saleable products stay off."
                    >
                        {/* On the CREATE form too, not only on edit: a new
                            material would otherwise always arrive
                            non-requestable and need a second trip through the
                            edit modal before the store could issue it. */}
                        <Controller
                            name="is_production_input"
                            control={control}
                            render={({ field }) => <Switch checked={field.value ?? false} onChange={field.onChange} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Edit "${editingItem ? itemDisplayName(editingItem) : ''}"`}
                open={editingItem !== null}
                onCancel={() => setEditingItem(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingItem) editMutation.mutate({ id: editingItem.id, values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="SKU" validateStatus={editErrors.sku ? 'error' : ''} help={editErrors.sku?.message}>
                        <Controller name="sku" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Name"
                        validateStatus={editErrors.name ? 'error' : ''}
                        help={editErrors.name?.message}
                        extra={nameIsTallys ? "Tally's name — rename in Tally, then pull masters." : undefined}
                    >
                        <Controller
                            name="name"
                            control={editControl}
                            render={({ field }) => <Input {...field} disabled={nameIsTallys} />}
                        />
                    </Form.Item>

                    <ItemIdentityFields
                        control={editControl}
                        baseItems={baseItems}
                        suggestedCategory={editingItem?.suggested_category}
                        suggestionConfidence={editingItem?.suggested_category_confidence}
                        onApplySuggestion={(value) => setEditValue('category', value, { shouldDirty: true })}
                    />

                    <Form.Item label="UOM" validateStatus={editErrors.uom ? 'error' : ''} help={editErrors.uom?.message}>
                        <Controller name="uom" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="HSN/SAC Code"
                        validateStatus={editErrors.hsn_sac_code ? 'error' : ''}
                        help={editErrors.hsn_sac_code?.message}
                    >
                        <Controller name="hsn_sac_code" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Reorder Level">
                        <Controller
                            name="reorder_level"
                            control={editControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Nominal Weight (g)"
                        tooltip="Weight of a single unit — used to compute Kg figures on the shop floor from a piece count. Leave blank for raw materials."
                    >
                        <Controller
                            name="nominal_weight_grams"
                            control={editControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Tracking Type">
                        <Controller
                            name="tracking_type"
                            control={editControl}
                            render={({ field }) => <Select {...field} options={trackingTypeOptions} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Requestable as a production material"
                        help="When on, the floor may ask the store to issue this item. Finished goods and saleable products stay off."
                    >
                        <Controller
                            name="is_production_input"
                            control={editControl}
                            render={({ field }) => (
                                <Switch checked={field.value ?? false} onChange={field.onChange} />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
