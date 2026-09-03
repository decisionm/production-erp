import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import BarcodeDisplay from '@/components/barcode/BarcodeDisplay';
import { createAsset, listAssets, updateAsset } from '@/features/maintenance/api';
import {
    ASSET_DEFAULT_SORT,
    ASSET_LIST_SPEC,
    ASSET_SORT_FIELDS,
    type AssetListParams,
    assetServerFilters,
    assetsQueryKey,
} from '@/features/maintenance/assetList';
import type { Asset, AssetStatus } from '@/features/maintenance/types';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const assetSchema = z.object({
    code: z.string().min(1, 'Code is required').max(32),
    name: z.string().min(1, 'Name is required').max(255),
    category: z.string().optional(),
    location: z.string().optional(),
    purchase_date: z.string().optional(),
    purchase_cost: z.number().min(0).optional(),
});
type AssetFormValues = z.infer<typeof assetSchema>;

const editAssetSchema = assetSchema.extend({
    status: z.enum(['active', 'under_maintenance', 'retired']).optional(),
});
type EditAssetFormValues = z.infer<typeof editAssetSchema>;

const statusColor: Record<AssetStatus, string> = {
    active: 'green',
    under_maintenance: 'orange',
    retired: 'default',
};

const statusOptions: { value: AssetStatus; label: string }[] = [
    { value: 'active', label: 'Active' },
    { value: 'under_maintenance', label: 'Under Maintenance' },
    { value: 'retired', label: 'Retired' },
];

export default function AssetsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingAsset, setEditingAsset] = useState<Asset | null>(null);
    const [barcodeAsset, setBarcodeAsset] = useState<Asset | null>(null);
    const queryClient = useQueryClient();

    // THE REGISTER'S VIEW IS ITS URL (useListParams): sort, page and page
    // size, all answered by the SERVER over the whole register.
    const { params, setParams, setPage } = useListParams<AssetListParams>(ASSET_LIST_SPEC);
    const filters = useMemo(() => assetServerFilters(params), [params]);
    const { data, isLoading } = useQuery({
        queryKey: assetsQueryKey(filters),
        queryFn: () => listAssets(filters),
        placeholderData: (previous) => previous,
    });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<AssetFormValues>({
        resolver: zodResolver(assetSchema),
        defaultValues: { code: '', name: '' },
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['maintenance', 'assets'] });

    const createMutation = useMutation({
        mutationFn: createAsset,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset({ code: '', name: '' });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create asset', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const statusMutation = useMutation({
        mutationFn: ({ id, status }: { id: number; status: AssetStatus }) => updateAsset(id, { status }),
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not update asset status', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<EditAssetFormValues>({ resolver: zodResolver(editAssetSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & EditAssetFormValues) => updateAsset(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingAsset(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update asset', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Assets</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Asset</Button>
            </Space>

            <Table<Asset>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                // SORTED BY THE SERVER: every sorter is sortOrder-controlled
                // and re-queries; the register is paginated, so sorting the
                // loaded page would misorder the whole result set.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, ASSET_SORT_FIELDS, ASSET_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'assets')}
                columns={[
                    {
                        title: 'Code',
                        dataIndex: 'code',
                        key: 'code',
                        sorter: true,
                        sortOrder: columnSortOrder('code', params.sort, ASSET_DEFAULT_SORT),
                    },
                    {
                        title: 'Name',
                        dataIndex: 'name',
                        key: 'name',
                        sorter: true,
                        sortOrder: columnSortOrder('name', params.sort, ASSET_DEFAULT_SORT),
                    },
                    {
                        title: 'Category',
                        dataIndex: 'category',
                        key: 'category',
                        sorter: true,
                        sortOrder: columnSortOrder('category', params.sort, ASSET_DEFAULT_SORT),
                    },
                    {
                        title: 'Location',
                        dataIndex: 'location',
                        key: 'location',
                        sorter: true,
                        sortOrder: columnSortOrder('location', params.sort, ASSET_DEFAULT_SORT),
                    },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        key: 'status',
                        sorter: true,
                        sortOrder: columnSortOrder('status', params.sort, ASSET_DEFAULT_SORT),
                        render: (status: AssetStatus, row) => (
                            <Select
                                value={status}
                                options={statusOptions}
                                size="small"
                                variant="borderless"
                                style={{ width: 160 }}
                                onChange={(value) => statusMutation.mutate({ id: row.id, status: value })}
                                labelRender={() => <Tag color={statusColor[status]}>{status}</Tag>}
                            />
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button
                                    size="small"
                                    onClick={() => {
                                        setEditingAsset(row);
                                        resetEdit({
                                            code: row.code,
                                            name: row.name,
                                            category: row.category ?? '',
                                            location: row.location ?? '',
                                            purchase_date: row.purchase_date ?? undefined,
                                            purchase_cost: row.purchase_cost ? Number(row.purchase_cost) : undefined,
                                            status: row.status,
                                        });
                                    }}
                                >
                                    Edit
                                </Button>
                                <Button size="small" onClick={() => setBarcodeAsset(row)}>
                                    Barcode
                                </Button>
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Asset"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={errors.code ? 'error' : ''} help={errors.code?.message}>
                        <Controller name="code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Category">
                        <Controller name="category" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Location">
                        <Controller name="location" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Purchase Date">
                        <Controller
                            name="purchase_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Purchase Cost">
                        <Controller
                            name="purchase_cost"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Edit "${editingAsset?.name}"`}
                open={editingAsset !== null}
                onCancel={() => setEditingAsset(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingAsset) editMutation.mutate({ id: editingAsset.id, ...values });
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
                    <Form.Item label="Category">
                        <Controller name="category" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Location">
                        <Controller name="location" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Purchase Date">
                        <Controller
                            name="purchase_date"
                            control={editControl}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    value={field.value ? dayjs(field.value) : undefined}
                                    onChange={(_, dateString) => field.onChange((dateString as string) || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Purchase Cost">
                        <Controller
                            name="purchase_cost"
                            control={editControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Status">
                        <Controller
                            name="status"
                            control={editControl}
                            render={({ field }) => <Select {...field} options={statusOptions} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Drawer
                title={`Barcode — ${barcodeAsset?.code}`}
                open={barcodeAsset !== null}
                onClose={() => setBarcodeAsset(null)}
                width="min(100vw, 420px)"
                destroyOnHidden
            >
                {barcodeAsset && <BarcodeDisplay code={barcodeAsset.code} label={barcodeAsset.name} />}
            </Drawer>
        </>
    );
}
