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
import {
    CATEGORY_FACET_ALL,
    catalogueEmptyText,
    type CategoryFacetKey,
    categoryFacets,
    matchesCategoryFacet,
    readRememberedFacet,
    rememberFacet,
    skuPresentation,
} from '@/features/inventory/catalogue';
import { CategoryFacets } from '@/features/inventory/components/CategoryFacets';
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
    /**
     * Which category the catalogue is filtered to. Independent of the warning
     * filter, and restored from the last choice made in THIS browser so the
     * store's machine opens on the store's material — see readRememberedFacet.
     * Read lazily: the choice is only consulted when the page first mounts.
     */
    const [facet, setFacet] = useState<CategoryFacetKey>(readRememberedFacet);
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

    /*
     * THE TWO FILTERS ARE EXCLUSIVE, and that is a correctness rule rather
     * than a simplification.
     *
     * The warning list is paginated by the SERVER; the category filter can
     * only be applied to the page that has already arrived, because
     * `ListItemWarningsRequest` accepts no category. Combining them therefore
     * emptied the table while the pager underneath went on reporting the
     * server's unfiltered total — a screen whose whole product is counts,
     * stating one that is not true of what it is showing.
     *
     * Nothing is lost by refusing the combination: it is empty by
     * construction. An unclassified item has no category, so "unclassified
     * packing material" cannot match a row, and every other pairing is
     * answerable by picking the category and reading the Warnings column that
     * is already on each row.
     */
    const rows: ItemRow[] = warning === null
        ? searchedItems.filter((item) => matchesCategoryFacet(item, facet))
        : flaggedRows;

    /*
     * Counted over the WHOLE catalogue, never over the filtered rows: a count
     * that shrinks as you filter is describing your filter, not the factory,
     * and the number a storekeeper is deciding on is how many exist. With the
     * filters exclusive this count also PREDICTS what clicking will show,
     * which is how a number on a control is read whatever it was meant as.
     */
    const facets = useMemo(() => categoryFacets(allItems, facet), [allItems, facet]);

    /** The units this catalogue actually uses — never a hardcoded list. */
    const uomFilters = useMemo(
        () => [...new Set(allItems.map((item) => (item.uom ?? '').trim()).filter((uom) => uom !== ''))]
            .sort((a, b) => a.localeCompare(b))
            .map((uom) => ({ text: uom, value: uom })),
        [allItems],
    );

    const selectWarning = (next: WarningFilter) => {
        setWarning(next);
        setWarningPage(1);
        // Exclusive with the category filter — see the note on `rows`.
        setFacet(CATEGORY_FACET_ALL);
    };

    const selectFacet = (next: CategoryFacetKey) => {
        setFacet(next);
        rememberFacet(next);
        setWarning(null);
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

            <CategoryFacets facets={facets} active={facet} onSelect={selectFacet} />

            <IdentityHealthStrip health={health} active={warning} onSelect={selectWarning} />

            <Table<ItemRow>
                // STICKY HEADER: at 700 rows a person scrolling the middle of
                // the catalogue was reading unlabelled columns.
                sticky
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={warning === null ? isLoading : flaggedFetching}
                dataSource={rows}
                // Two filters stack here and a search sits above both, so an
                // empty table names the narrowest one that is on rather than
                // leaving a person to clear all three to find out which.
                // The search box is disabled while a warning filter is on, so
                // its text is not applied and must not be blamed for an empty
                // table.
                locale={{ emptyText: catalogueEmptyText(facet, warning, warning === null ? search : '') }}
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
                        /*
                         * Q42, answered by the owner: the SKU is an internal
                         * mapping and a lookup handle — NOT a barcode. It used
                         * to open a barcode drawer, which is exactly the thing
                         * it is not; it is text somebody types or copies.
                         *
                         * AND MOST OF THEM WERE NOT CHOSEN. The masters pull
                         * seeds a SKU from the Tally NAME and marks the row
                         * `sku_provisional`, so a column headed "SKU" was
                         * printing the product name again, in the voice of a
                         * decision. Seeded ones are set quietly and marked;
                         * chosen ones read plainly. Monospaced because these
                         * are compared character by character down a column,
                         * and someone types them into the delivery scanner.
                         */
                        title: 'SKU',
                        dataIndex: 'sku',
                        // FIXED, because this table is thirteen columns wide on
                        // a catalogue of hundreds: scrolling right to read a
                        // tracking type used to take the product's identity off
                        // screen, and the row you were reading became a row of
                        // numbers belonging to nothing.
                        fixed: 'left' as const,
                        width: 200,
                        sorter: (a: ItemRow, b: ItemRow) => (a.sku ?? '').localeCompare(b.sku ?? ''),
                        render: (_: string, row: ItemRow) => {
                            const shown = skuPresentation(row);

                            return (
                                <Space size={4}>
                                    <Typography.Text
                                        copyable={{ text: shown.text }}
                                        type={shown.provisional ? 'secondary' : undefined}
                                        style={{ fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', fontSize: 12 }}
                                    >
                                        {shown.text}
                                    </Typography.Text>
                                    {shown.provisional ? (
                                        <Tooltip title="Seeded from the Tally name when this item was pulled — nobody has chosen a code yet.">
                                            <Tag bordered={false} style={{ marginInlineEnd: 0, fontSize: 11 }}>seeded</Tag>
                                        </Tooltip>
                                    ) : null}
                                </Space>
                            );
                        },
                    },
                    {
                        title: 'Name',
                        dataIndex: 'name',
                        fixed: 'left' as const,
                        width: 260,
                        /*
                         * THE DEFAULT ORDER, and the one a person scanning a
                         * catalogue expects: names, A to Z. Sorting on the ERP
                         * name where there is one, because that is the string
                         * the row is showing — sorting by a Tally name the
                         * reader cannot see puts rows in an order the screen
                         * does not explain.
                         */
                        defaultSortOrder: 'ascend' as const,
                        sorter: (a: ItemRow, b: ItemRow) =>
                            itemDisplayName(a).localeCompare(itemDisplayName(b), undefined, { numeric: true }),
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
                        //
                        // ONLY WORTH A COLUMN IN "ALL". Filtered to Packing,
                        // every row reads "Packing material" — the column then
                        // repeats the question instead of answering anything.
                        // Hidden rather than dimmed: a column holding one
                        // repeated value is width taken from the names, which
                        // are what this catalogue is genuinely hard to read.
                        hidden: facet !== CATEGORY_FACET_ALL,
                        title: 'Category',
                        dataIndex: 'category',
                        sorter: (a: ItemRow, b: ItemRow) =>
                            (a.category ?? '\uffff').localeCompare(b.category ?? '\uffff'),
                        render: (value: ItemCategoryValue | null | undefined, row: ItemRow) => {
                            const group = itemGroupName(row);

                            const tag = value === undefined
                                ? <Typography.Text type="secondary">—</Typography.Text>
                                : value === null
                                    ? (
                                        <Tooltip title={warningTooltip('unclassified')}>
                                            <Tag color={warningColor('unclassified')}>{warningLabel('unclassified')}</Tag>
                                        </Tooltip>
                                    )
                                    : <Tag color={categoryColor(value)}>{categoryLabel(value)}</Tag>;

                            return (
                                <Space direction="vertical" size={0}>
                                    {tag}
                                    {group === null ? null : (
                                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>{group}</Typography.Text>
                                    )}
                                </Space>
                            );
                        },
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
                    {
                        title: 'UOM',
                        dataIndex: 'uom',
                        // Built from what the catalogue actually holds rather
                        // than a hardcoded list: this factory counts in Nos.,
                        // Kgs., Pcs. and a compound unit, and a filter naming a
                        // unit no item uses is a dead option.
                        filters: uomFilters,
                        onFilter: (value, row: ItemRow) => (row.uom ?? '') === value,
                        sorter: (a: ItemRow, b: ItemRow) => (a.uom ?? '').localeCompare(b.uom ?? ''),
                    },
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
                        filters: [
                            { text: 'Active', value: true },
                            { text: 'Archived', value: false },
                        ],
                        onFilter: (value, row: ItemRow) => row.is_active === value,
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
