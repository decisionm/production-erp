import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { z } from 'zod';
import { listAllItems } from '@/features/inventory/api';
import { createBom, listBoms } from '@/features/production/api';
import {
    buildStartBatchReturnUrl,
    hasStartBatchResume,
    parseStartBatchResume,
    type StartBatchResumeDraft,
} from '@/features/production/startBatchResume';
import type { Bom } from '@/features/production/types';

const lineSchema = z.object({
    component_item_id: z.number({ error: 'Component is required' }),
    quantity_per: z.number().gt(0, 'Quantity must be greater than 0'),
});

const bomSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    name: z.string().min(1, 'Name is required').max(255),
    version: z.string().optional(),
    lines: z.array(lineSchema).min(1, 'At least one component is required'),
});
type BomFormValues = z.infer<typeof bomSchema>;

const emptyBomForm = (): BomFormValues => ({
    item_id: undefined as unknown as number,
    name: '',
    version: '',
    lines: [{ component_item_id: undefined as unknown as number, quantity_per: undefined as unknown as number }],
});

export default function BomsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailBom, setDetailBom] = useState<Bom | null>(null);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [resumeDraft, setResumeDraft] = useState<StartBatchResumeDraft | null>(null);
    const [resumeContextError, setResumeContextError] = useState<string | null>(null);
    const processedConfigureQueryRef = useRef<string | null>(null);
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const resumeQuery = searchParams.toString();
    const { configureFlowRequested, incomingResumeDraft } = useMemo(() => {
        const params = new URLSearchParams(resumeQuery);
        const requested = hasStartBatchResume(params, 'configure');
        const parsed = requested ? parseStartBatchResume(params) : null;
        return {
            configureFlowRequested: requested,
            incomingResumeDraft: parsed?.phase === 'configure' ? parsed.draft : null,
        };
    }, [resumeQuery]);

    const { data, isLoading } = useQuery({ queryKey: ['production', 'boms'], queryFn: () => listBoms() });
    // Every item, not the default first page of 20. A BOM picker that reaches
    // 20 of the factory's ~650 products cannot build a recipe for the other
    // 630, and the prefill below would silently find nothing and open a blank
    // form. The key must stay distinct from ['inventory','items'] — that entry
    // holds the 20-row page, and sharing the key would serve it here depending
    // on which page the supervisor happened to open first.
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    const itemOptions =
        items?.data
            .filter((item) => item.is_active)
            .map((item) => ({ value: item.id, label: `${item.sku} — ${item.name}` })) ?? [];

    const { clearErrors, control, handleSubmit, reset, setError, formState: { errors } } = useForm<BomFormValues>({
        resolver: zodResolver(bomSchema),
        defaultValues: emptyBomForm(),
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'lines' });

    const resetNewBomForm = () => {
        setSubmitError(null);
        clearErrors();
        reset(emptyBomForm());
    };

    const openNewBom = () => {
        resetNewBomForm();
        setResumeDraft(null);
        setResumeContextError(null);
        setSearchParams({}, { replace: true });
        setModalOpen(true);
    };

    const returnToShiftProduction = (
        draft: StartBatchResumeDraft,
        result: 'created' | 'cancelled',
    ) => {
        // The destination, context and outcome all come from the shared
        // allowlisted encoder. Arbitrary return URLs are never accepted.
        navigate(buildStartBatchReturnUrl(draft, result), { replace: true });
    };

    const closeNewBom = (result: 'cancelled' | null = null) => {
        const draftToResume = resumeDraft;
        setModalOpen(false);
        resetNewBomForm();
        setResumeDraft(null);
        if (draftToResume && result) {
            returnToShiftProduction(draftToResume, result);
        }
    };

    useEffect(() => {
        if (!configureFlowRequested) {
            processedConfigureQueryRef.current = null;
            return;
        }
        if (processedConfigureQueryRef.current === resumeQuery) return;

        if (!incomingResumeDraft) {
            processedConfigureQueryRef.current = resumeQuery;
            setResumeContextError(
                'The saved Start Batch context is incomplete or invalid. Return to Shift Production and reopen Configure recipe.',
            );
            return;
        }

        // Wait for all items — prefilling an id the Select cannot render would
        // show a bare number where a product name belongs.
        if (!items) return;
        processedConfigureQueryRef.current = resumeQuery;
        const item = items.data.find(
            (candidate) => candidate.id === incomingResumeDraft.item_id && candidate.is_active,
        );
        setResumeDraft(incomingResumeDraft);

        if (!item) {
            setResumeContextError(
                'The selected product is no longer available. Return to Shift Production and choose an active product before configuring its recipe.',
            );
            return;
        }

        reset({
            item_id: item.id,
            name: `${item.name} recipe`,
            version: '',
            lines: [{ component_item_id: undefined, quantity_per: undefined }],
        });
        setSubmitError(null);
        setResumeContextError(null);
        setModalOpen(true);
    }, [configureFlowRequested, incomingResumeDraft, items, reset, resumeQuery]);

    const mutation = useMutation({
        mutationFn: createBom,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'boms'] });
            const draftToResume = resumeDraft;
            setModalOpen(false);
            resetNewBomForm();
            setResumeDraft(null);
            if (draftToResume) {
                returnToShiftProduction(draftToResume, 'created');
            }
        },
        onError: (error: any) => {
            const serverErrors = error?.response?.data?.errors as Record<string, string[] | string> | undefined;
            let mappedFieldError = false;

            Object.entries(serverErrors ?? {}).forEach(([path, messages]) => {
                const message = Array.isArray(messages) ? messages[0] : messages;
                if (!message) return;

                if (path === 'item_id' || path === 'name' || path === 'version') {
                    setError(path, { type: 'server', message });
                    mappedFieldError = true;
                    return;
                }

                const lineField = path.match(/^lines\.(\d+)\.(component_item_id|quantity_per)$/);
                if (lineField) {
                    const index = Number(lineField[1]);
                    const fieldName = lineField[2] as 'component_item_id' | 'quantity_per';
                    setError(`lines.${index}.${fieldName}`, { type: 'server', message });
                    mappedFieldError = true;
                    return;
                }

                if (path === 'lines') {
                    setError('lines', { type: 'server', message });
                    mappedFieldError = true;
                }
            });

            const responseMessage = error?.response?.data?.message;
            setSubmitError(
                mappedFieldError
                    ? 'Please correct the highlighted recipe fields and try again. Your entered values have been kept.'
                    : (responseMessage ?? 'The recipe could not be saved. Your entered values have been kept; please try again.'),
            );
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Bills of Material</Typography.Title>
                <Button type="primary" onClick={openNewBom}>New BOM</Button>
            </Space>

            {resumeContextError && (
                <Alert
                    type="error"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={resumeContextError}
                    action={
                        <Button
                            size="small"
                            onClick={() => {
                                if (resumeDraft) {
                                    returnToShiftProduction(resumeDraft, 'cancelled');
                                } else {
                                    navigate('/production/shift-production');
                                }
                            }}
                        >
                            Return to Shift Production
                        </Button>
                    }
                />
            )}

            <Table<Bom>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Version', dataIndex: 'version' },
                    { title: 'Components', render: (_, row) => row.lines.map((l) => l.component.sku).join(', ') },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        render: (active: boolean) => <Tag color={active ? 'green' : 'default'}>{active ? 'active' : 'inactive'}</Tag>,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button size="small" onClick={() => setDetailBom(row)}>
                                View
                            </Button>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title={resumeDraft ? 'Configure product recipe' : 'New BOM'}
                open={modalOpen}
                closable={!mutation.isPending}
                onCancel={() => {
                    if (!mutation.isPending) {
                        closeNewBom(resumeDraft ? 'cancelled' : null);
                    }
                }}
                onOk={handleSubmit((values) => {
                    setSubmitError(null);
                    clearErrors();
                    mutation.mutate(values);
                })}
                confirmLoading={mutation.isPending}
                cancelButtonProps={{ disabled: mutation.isPending }}
                destroyOnHidden
                width={700}
            >
                <Form layout="vertical">
                    {submitError && (
                        <Alert
                            type="error"
                            showIcon
                            style={{ marginBottom: 16 }}
                            message={submitError}
                        />
                    )}
                    <Form.Item label="Item" validateStatus={errors.item_id ? 'error' : ''} help={errors.item_id?.message}>
                        <Controller
                            name="item_id"
                            control={control}
                            render={({ field }) => (
                                <Select
                                    {...field}
                                    options={itemOptions}
                                    showSearch
                                    optionFilterProp="label"
                                    disabled={resumeDraft !== null}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Version (optional, defaults to 1)"
                        validateStatus={errors.version ? 'error' : ''}
                        help={errors.version?.message}
                    >
                        <Controller name="version" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>

                    <Typography.Text strong>Components</Typography.Text>
                    {errors.lines?.message && (
                        <Typography.Paragraph type="danger" style={{ marginBottom: 0 }}>
                            {errors.lines.message}
                        </Typography.Paragraph>
                    )}
                    {fields.map((field, index) => (
                        <Space key={field.id} align="start" style={{ display: 'flex', marginTop: 8 }}>
                            <Form.Item
                                validateStatus={errors.lines?.[index]?.component_item_id ? 'error' : ''}
                                help={errors.lines?.[index]?.component_item_id?.message}
                                style={{ marginBottom: 0 }}
                            >
                                <Controller
                                    name={`lines.${index}.component_item_id`}
                                    control={control}
                                    render={({ field }) => (
                                        <Select
                                            {...field}
                                            options={itemOptions}
                                            showSearch
                                            optionFilterProp="label"
                                            style={{ width: 280 }}
                                            placeholder="Component"
                                        />
                                    )}
                                />
                            </Form.Item>
                            <Form.Item
                                validateStatus={errors.lines?.[index]?.quantity_per ? 'error' : ''}
                                help={errors.lines?.[index]?.quantity_per?.message}
                                style={{ marginBottom: 0 }}
                            >
                                <Controller
                                    name={`lines.${index}.quantity_per`}
                                    control={control}
                                    render={({ field }) => <InputNumber {...field} min={0} placeholder="Qty per unit" />}
                                />
                            </Form.Item>
                            <Button danger onClick={() => remove(index)} disabled={fields.length <= 1}>Remove</Button>
                        </Space>
                    ))}
                    <Button
                        type="dashed"
                        style={{ marginTop: 8 }}
                        onClick={() => append({ component_item_id: undefined as unknown as number, quantity_per: undefined as unknown as number })}
                    >
                        Add Component
                    </Button>
                </Form>
            </Modal>

            <Drawer
                title={`${detailBom?.name} (${detailBom?.item.sku})`}
                open={detailBom !== null}
                onClose={() => setDetailBom(null)}
                width={520}
                destroyOnHidden
            >
                {detailBom && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Item">
                                {detailBom.item.sku} — {detailBom.item.name}
                            </Descriptions.Item>
                            <Descriptions.Item label="Version">{detailBom.version}</Descriptions.Item>
                            <Descriptions.Item label="Active">
                                <Tag color={detailBom.is_active ? 'green' : 'default'}>
                                    {detailBom.is_active ? 'active' : 'inactive'}
                                </Tag>
                            </Descriptions.Item>
                        </Descriptions>

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            Components
                        </Typography.Title>
                        <Table
                            rowKey="id"
                            size="small"
                            pagination={false}
                            dataSource={detailBom.lines}
                            scroll={{ x: 'max-content' }}
                            columns={[
                                {
                                    title: 'Component',
                                    render: (_, line) => `${line.component.sku} — ${line.component.name}`,
                                },
                                { title: 'Quantity per Unit', dataIndex: 'quantity_per' },
                            ]}
                        />
                    </>
                )}
            </Drawer>
        </>
    );
}
