import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Empty, Form, Input, Modal, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
import { createVendor, listVendors, updateVendor } from '@/features/procurement/api';
import type { Vendor } from '@/features/procurement/types';
import { ListEmpty } from '@/lib/ListEmpty';
import { hasModuleAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import TallyVendorReviewPage from '@/features/procurement/pages/TallyVendorReviewPage';
import { vendorActiveTab } from '@/features/procurement/vendorTabs';
import { vendorLedgerWords } from '@/features/procurement/documentWords';
import { DEFAULT_VENDOR_VIEW, VENDOR_CLASSIFICATIONS, classificationLabel, type VendorClassification } from '@/features/procurement/vendorClassification';
import {
    UNCLASSIFIED_FILTER_VALUE,
    VENDOR_DEFAULT_SORT,
    VENDOR_LIST_SPEC,
    VENDOR_SORT_FIELDS,
    type VendorListParams,
    vendorListSort,
} from '@/features/procurement/vendorList';
import { TABLE_STICKY } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

// The New Vendor form does NOT ask for a code. The server mints "V-0001" and
// steps the sequence on, so there is nothing here for a person to guess at —
// which is what produced the live master's hand-typed `V-DEMO-KPXL`. Editing
// an existing vendor still shows its code, because by then there is a real
// value to correct.
const vendorSchema = z.object({
    name: z.string().min(1, 'Name is required').max(255),
    email: z.string().email('Enter a valid email').optional().or(z.literal('')),
    phone: z.string().optional(),
    gstin: z
        .string()
        .regex(/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/, 'Enter a valid 15-character GSTIN')
        .optional()
        .or(z.literal('')),
    state_code: z.string().regex(/^[0-9]{2}$/, 'Enter a 2-digit GST state code').optional().or(z.literal('')),
    // DEC-20260902-026: zero, one or many of the five procurement
    // categories. No `.default()` — react-hook-form's resolver wants the
    // input and output shapes to agree, so the [] default is set explicitly
    // wherever the form is (re)opened, same as every other field here.
    classifications: z.array(z.enum(['resin', 'packaging', 'consumables_spares_tooling', 'service', 'other'])),
    // The vendor's ledger name in Tally, EXACTLY as Tally spells it — the
    // party a staged Purchase Order voucher names (Phase 6). Typed by
    // Accounts; the ERP never reads it from Tally. Empty = not mapped, and
    // a PO for this vendor is refused staging ('party_unmapped').
    tally_ledger_name: z.string().trim().max(255).optional().or(z.literal('')),
});

const editVendorSchema = vendorSchema.extend({
    code: z.string().min(1, 'Code is required').max(32),
});

type VendorFormValues = z.infer<typeof vendorSchema>;
type EditVendorFormValues = z.infer<typeof editVendorSchema>;

/**
 * THE ONE VENDOR MASTER (DEC-20260825-003).
 *
 * Vendor used to be two sibling menu items — "Vendors" and "Tally Vendor
 * Review" — which read as two vendor masters to maintain. There is one:
 * this page, on the Configuration Lifecycle Contract (DEC-20260817-002),
 * the same shape Customers and Warehouses use. Tally PROPOSES; this master
 * decides. So the review is now a TAB of the master it feeds, not a screen
 * beside it.
 *
 * FC-06 IS UNCHANGED AND IS THE REASON FOR THE GATE BELOW. Supplier
 * identity is Owner/Accounts only, so the review tab is offered on exactly
 * the predicate the nav entry used — hasModuleAccess(user, 'finance') — and
 * the API keeps refusing everyone else regardless (module:finance on
 * tally/vendor-review/*, untouched). A procurement-only login is not shown
 * the tab, and because the pane is never rendered for them the review query
 * is never fired either.
 */
export default function VendorsPage() {
    const user = useAuthStore((state) => state.user);
    // TWO SEPARATE GATES, because the two tabs are two different modules and
    // the page must not offer either login a tab its API will refuse. The
    // master list is module:procurement; the review is module:finance
    // (FC-06). A login holding one sees exactly the one it held before this
    // change, with no tab bar; only a login holding both sees a choice.
    const canSeeMaster = hasModuleAccess(user, 'procurement');
    const canReviewTally = hasModuleAccess(user, 'finance');
    // Deep links that used to land on /procurement/tally-vendor-review are
    // redirected here with ?tab=tally-review, so an old bookmark still
    // reaches the thing it named.
    const [searchParams, setSearchParams] = useSearchParams();
    const activeTab = vendorActiveTab(searchParams.get('tab'), { canSeeMaster, canReviewTally });

    const [modalOpen, setModalOpen] = useState(false);
    const [editingVendor, setEditingVendor] = useState<Vendor | null>(null);
    const queryClient = useQueryClient();

    // Server-side paging, the Customers table's shape. The page number is
    // part of the query key; the key still STARTS with the prefix the
    // invalidate below uses, so creating a vendor refreshes this table and
    // the pickers' ['procurement','vendors','all'] alike.
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);
    // The typed term, and the one actually sent. Kept apart so the list is not
    // re-fetched on every keystroke: the box updates immediately, the query
    // moves when the person submits or clears it.
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');

    // DEC-20260902-026: the classification filter, kept in the URL
    // (useListParams, vendorList.ts). Unset on the URL = the default view
    // (the three material classes); the pseudo-value `__unclassified` is
    // split off before it reaches the API as `unclassified=1`. The column
    // SORT (03-Sep-2026) lives beside it and is the server's too: the
    // master is paged, so a sorter over the loaded page would order 50 of
    // 628 rows and call it the order of the master.
    const { params: listParams, setParams: setListParams } = useListParams<VendorListParams>(VENDOR_LIST_SPEC);
    const selectedClassification = listParams.classification ?? DEFAULT_VENDOR_VIEW;
    const classificationFilter = selectedClassification.filter(
        (value): value is VendorClassification => value !== UNCLASSIFIED_FILTER_VALUE,
    ) as VendorClassification[];
    const unclassifiedFilter = selectedClassification.includes(UNCLASSIFIED_FILTER_VALUE);
    const sort = vendorListSort(listParams.sort);

    const { data, isLoading, isPending, isError, error, refetch } = useQuery({
        queryKey: ['procurement', 'vendors', page, perPage, search, classificationFilter, unclassifiedFilter, sort],
        queryFn: () => listVendors(page, perPage, search, classificationFilter, unclassifiedFilter, sort),
        placeholderData: (previous) => previous,
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['procurement', 'vendors'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<VendorFormValues>({
        resolver: zodResolver(vendorSchema),
        defaultValues: { name: '', email: '', phone: '', gstin: '', state_code: '', classifications: [], tally_ledger_name: '' },
    });

    const mutation = useMutation({
        mutationFn: createVendor,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
        // The edit mutation below has surfaced its refusals all along and this
        // one did not, so a duplicate code or a GSTIN the server rejects left
        // the modal sitting open with nothing said. Same treatment, same
        // wording: the server's own sentence, never genericised.
        onError: (error: any) =>
            Modal.error({
                title: 'Could not create vendor',
                content: error?.response?.data?.message ?? 'Unknown error',
            }),
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<EditVendorFormValues>({ resolver: zodResolver(editVendorSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & EditVendorFormValues) => updateVendor(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingVendor(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update vendor', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const master = (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Vendors</Typography.Title>
                <Space>
                    {/*
                      628 vendors arrived from the Tally ledger import in one
                      run, and before this the only way to a supplier was
                      thirteen screens of paging. Searching the SERVER, not the
                      loaded page: filtering 50 of 628 in the browser would
                      answer "no such vendor" for one that plainly exists.
                      Submitting resets to page 1, or the term would be applied
                      to whatever page number happened to be showing.
                    */}
                    <Input.Search
                        allowClear
                        placeholder="Name or code"
                        style={{ width: 260 }}
                        value={searchInput}
                        onChange={(event) => setSearchInput(event.target.value)}
                        onSearch={(value) => {
                            setPage(1);
                            setSearch(value.trim());
                        }}
                    />
                    <Button type="primary" onClick={() => setModalOpen(true)}>New Vendor</Button>
                </Space>
            </Space>

            {/*
              DEC-20260902-026: Resin, Packaging and Consumables/Spares/
              Tooling by default; Service, Other and Unclassified sit behind
              this explicit choice. Kept in the URL (useListParams), so a
              refresh or a pasted link keeps the chosen view.
            */}
            <Space style={{ marginBottom: 12 }}>
                <Select
                    mode="multiple"
                    allowClear
                    style={{ minWidth: 320 }}
                    placeholder="Classification"
                    value={selectedClassification}
                    options={[
                        ...VENDOR_CLASSIFICATIONS,
                        { value: UNCLASSIFIED_FILTER_VALUE, label: 'Unclassified' },
                    ]}
                    onChange={(value: string[]) => {
                        setPage(1);
                        setListParams({ classification: value.length > 0 ? value : undefined });
                    }}
                />
            </Space>

            <Table<Vendor>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                // SORTED BY THE SERVER: sortOrder-controlled, re-queries the
                // whole master from page 1.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setPage(1);
                    setListParams({ sort: sortParamFromSorter(sorter, VENDOR_SORT_FIELDS, VENDOR_DEFAULT_SORT) });
                }}
                locale={{
                    emptyText: (
                        <ListEmpty
                            state={{ isPending, isError, error, refetch }}
                            entity="vendors"
                            empty={
                                // I3 / DEC-20260902-026: every vendor is
                                // UNCLASSIFIED until a person reviews them, so
                                // on day one the three-class default matches
                                // NOTHING and this screen would falsely claim
                                // the master is empty. No sentence — just the
                                // existing label plus a button that widens
                                // the filter, exactly like Select's own
                                // Unclassified option does.
                                !unclassifiedFilter ? (
                                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={search ? 'No vendors match this search.' : 'No vendors yet.'}>
                                        <Button
                                            size="small"
                                            onClick={() => {
                                                setPage(1);
                                                setListParams({ classification: [...selectedClassification, UNCLASSIFIED_FILTER_VALUE] });
                                            }}
                                        >
                                            Show unclassified
                                        </Button>
                                    </Empty>
                                ) : (
                                    search ? 'No vendors match this search.' : 'No vendors yet.'
                                )
                            }
                        />
                    ),
                }}
                pagination={{
                    current: page,
                    pageSize: perPage,
                    // The server's count, not the page's length — otherwise
                    // the pager would claim the list ends at the first screen.
                    total: data?.meta?.total ?? data?.data?.length ?? 0,
                    showSizeChanger: true,
                    pageSizeOptions: [20, 50, 100, 200],
                    showTotal: (total, range) => `${range[0]}-${range[1]} of ${total} vendors`,
                    onChange: (nextPage, nextSize) => {
                        setPage(nextPage);
                        setPerPage(nextSize);
                    },
                }}
                columns={[
                    {
                        title: 'Code',
                        dataIndex: 'code',
                        key: 'code',
                        sorter: true,
                        sortOrder: columnSortOrder('code', listParams.sort, VENDOR_DEFAULT_SORT),
                    },
                    {
                        title: 'Name',
                        dataIndex: 'name',
                        key: 'name',
                        sorter: true,
                        sortOrder: columnSortOrder('name', listParams.sort, VENDOR_DEFAULT_SORT),
                    },
                    {
                        title: 'Classification',
                        render: (_, row) =>
                            (row.classifications ?? []).length > 0
                                ? (row.classifications ?? []).map(classificationLabel).join(', ')
                                : 'Unclassified',
                    },
                    { title: 'Email', dataIndex: 'email' },
                    { title: 'Phone', dataIndex: 'phone' },
                    { title: 'GSTIN', dataIndex: 'gstin' },
                    {
                        title: 'State',
                        dataIndex: 'state_code',
                        key: 'state_code',
                        sorter: true,
                        sortOrder: columnSortOrder('state_code', listParams.sort, VENDOR_DEFAULT_SORT),
                        render: (code: string | null, row: Vendor) => (code ? `${code} — ${row.state_name ?? 'Unknown code'}` : ''),
                    },
                    {
                        title: 'Tally ledger',
                        // vendorLedgerWords: most imported vendors carry a
                        // ledger name identical to their own name, and
                        // printing the same string twice per row taught
                        // readers to skip the column — hiding the one row
                        // where the two genuinely differ. A vendor with no
                        // ledger name cannot be staged for Tally, and this
                        // column is where that gets fixed.
                        render: (_, row) => {
                            const words = vendorLedgerWords(row);
                            return words.kind === 'differs' ? (
                                words.text
                            ) : (
                                <Typography.Text type="secondary">{words.text}</Typography.Text>
                            );
                        },
                    },
                    {
                        // THE PROVENANCE OF WHAT WAS IMPORTED. A vendor whose
                        // details came from a Tally ledger says so, and says
                        // when that ledger was last read — the same claim the
                        // review screen and the purchase-order rate panel
                        // make, from the same column. A vendor somebody typed
                        // in carries no such claim, and must not appear to.
                        title: 'Source',
                        key: 'tally_source',
                        render: (_, row) => (row.tally_source
                            ? (
                                <Space direction="vertical" size={0}>
                                    <Tag color="blue" style={{ marginInlineEnd: 0 }}>Tally</Tag>
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        {row.tally_source.synced_at !== null
                                            ? `synced ${new Date(row.tally_source.synced_at).toLocaleString()}`
                                            : 'not yet synced'}
                                    </Typography.Text>
                                </Space>
                            )
                            : <Typography.Text type="secondary">entered here</Typography.Text>),
                    },
                    {
                        title: 'Status',
                        dataIndex: 'is_active',
                        key: 'is_active',
                        sorter: true,
                        sortOrder: columnSortOrder('is_active', listParams.sort, VENDOR_DEFAULT_SORT),
                        render: (_: boolean, row) => <ConfigurationStatusTag entity="vendor" row={row} />,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => {
                            const edit = () => {
                                setEditingVendor(row);
                                resetEdit({
                                    code: row.code,
                                    name: row.name,
                                    email: row.email ?? '',
                                    phone: row.phone ?? '',
                                    gstin: row.gstin ?? '',
                                    state_code: row.state_code ?? '',
                                    classifications: row.classifications ?? [],
                                    tally_ledger_name: row.tally_ledger_name ?? '',
                                });
                            };

                            return (
                                <ConfigurationActionsCell
                                    entity="vendor"
                                    id={row.id}
                                    can={row.can}
                                    recordName={`${row.code} — ${row.name}`}
                                    onEdit={edit}
                                />
                            );
                        },
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Vendor"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Email" validateStatus={errors.email ? 'error' : ''} help={errors.email?.message}>
                        <Controller name="email" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Phone">
                        <Controller name="phone" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="GSTIN" validateStatus={errors.gstin ? 'error' : ''} help={errors.gstin?.message}>
                        <Controller name="gstin" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="GST State Code"
                        validateStatus={errors.state_code ? 'error' : ''}
                        help={errors.state_code?.message}
                    >
                        <Controller name="state_code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Classification">
                        <Controller
                            name="classifications"
                            control={control}
                            render={({ field }) => <Select {...field} mode="multiple" options={VENDOR_CLASSIFICATIONS} allowClear />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Tally ledger name"
                        extra="Exactly as the ledger is named in Tally. Leave empty if not mapped — a Purchase Order for this vendor is then not staged for Tally."
                        validateStatus={errors.tally_ledger_name ? 'error' : ''}
                        help={errors.tally_ledger_name?.message}
                    >
                        <Controller name="tally_ledger_name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Edit "${editingVendor?.name}"`}
                open={editingVendor !== null}
                onCancel={() => setEditingVendor(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingVendor) editMutation.mutate({ id: editingVendor.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={editErrors.code ? 'error' : ''} help={editErrors.code?.message}>
                        <Controller name="code" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={editErrors.name ? 'error' : ''} help={editErrors.name?.message}>
                        <Controller name="name" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Email" validateStatus={editErrors.email ? 'error' : ''} help={editErrors.email?.message}>
                        <Controller name="email" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Phone">
                        <Controller name="phone" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="GSTIN" validateStatus={editErrors.gstin ? 'error' : ''} help={editErrors.gstin?.message}>
                        <Controller name="gstin" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="GST State Code"
                        validateStatus={editErrors.state_code ? 'error' : ''}
                        help={editErrors.state_code?.message}
                    >
                        <Controller name="state_code" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Classification">
                        <Controller
                            name="classifications"
                            control={editControl}
                            render={({ field }) => <Select {...field} mode="multiple" options={VENDOR_CLASSIFICATIONS} allowClear />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Tally ledger name"
                        extra="Exactly as the ledger is named in Tally. Leave empty if not mapped — a Purchase Order for this vendor is then not staged for Tally."
                        validateStatus={editErrors.tally_ledger_name ? 'error' : ''}
                        help={editErrors.tally_ledger_name?.message}
                    >
                        <Controller name="tally_ledger_name" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );

    // One tab is not a tab bar: a login that reaches only one of the two
    // gets that surface bare, exactly as it did when these were two menu
    // entries.
    if (!canReviewTally) return master;
    if (!canSeeMaster) return <TallyVendorReviewPage />;

    return (
        <Tabs
            activeKey={activeTab ?? 'master'}
            onChange={(key) => setSearchParams(key === 'tally-review' ? { tab: 'tally-review' } : {}, { replace: true })}
            items={[
                { key: 'master', label: 'Vendors', children: master },
                {
                    key: 'tally-review',
                    label: 'Tally review',
                    children: <TallyVendorReviewPage embedded />,
                },
            ]}
        />
    );
}
