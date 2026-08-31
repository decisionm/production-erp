import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
import { createVendor, listVendors, updateVendor } from '@/features/procurement/api';
import type { Vendor } from '@/features/procurement/types';
import { ListEmpty } from '@/lib/ListEmpty';
import { vendorLedgerWords } from '@/features/procurement/documentWords';

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

export default function VendorsPage() {
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

    const { data, isLoading, isPending, isError, error, refetch } = useQuery({
        queryKey: ['procurement', 'vendors', page, perPage, search],
        queryFn: () => listVendors(page, perPage, search),
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['procurement', 'vendors'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<VendorFormValues>({
        resolver: zodResolver(vendorSchema),
        defaultValues: { name: '', email: '', phone: '', gstin: '', state_code: '', tally_ledger_name: '' },
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

    return (
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

            <Table<Vendor>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                locale={{
                    emptyText: (
                        <ListEmpty
                            state={{ isPending, isError, error, refetch }}
                            entity="vendors"
                            empty={search ? 'No vendors match this search.' : 'No vendors yet.'}
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
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Email', dataIndex: 'email' },
                    { title: 'Phone', dataIndex: 'phone' },
                    { title: 'GSTIN', dataIndex: 'gstin' },
                    { title: 'State', dataIndex: 'state_code' },
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
}
