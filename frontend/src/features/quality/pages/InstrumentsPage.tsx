import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tag, Tooltip, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { CONFIGURATION_STATUS_WORDS } from '@/components/configuration/configurationWords';
import { createMeasuringInstrument, listMeasuringInstruments, recordCalibration } from '@/features/quality/api';
import {
    INSTRUMENT_DEFAULT_SORT,
    INSTRUMENT_LIST,
    INSTRUMENT_SORT_FIELDS,
    type InstrumentListParams,
    instrumentListRequest,
    instrumentsDueOnly,
} from '@/features/quality/qualityLists';
import type { CalibrationRecord, CalibrationResult, MeasuringInstrument, MeasuringInstrumentStatus } from '@/features/quality/types';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

/**
 * The register's URL keys beyond page / per_page (INSTRUMENT_LIST,
 * qualityLists.ts): `due=1` is the switch, `sort` a column. Module-level:
 * useListParams memoises on it.
 */
const INSTRUMENT_LIST_SPEC = INSTRUMENT_LIST.spec;

const instrumentSchema = z.object({
    code: z.string().min(1, 'Code is required').max(32),
    name: z.string().min(1, 'Name is required').max(255),
    location: z.string().optional(),
    calibration_frequency_days: z.number().min(1, 'Frequency must be at least 1 day'),
    next_calibration_due: z.string({ error: 'Next calibration due date is required' }),
});
type InstrumentFormValues = z.infer<typeof instrumentSchema>;

const calibrationSchema = z.object({
    calibrated_date: z.string({ error: 'Calibration date is required' }),
    certificate_number: z.string().optional(),
    result: z.enum(['pass', 'fail', 'adjusted'], { error: 'Result is required' }),
    performed_by: z.string().optional(),
    notes: z.string().optional(),
});
type CalibrationFormValues = z.infer<typeof calibrationSchema>;

const statusColor: Record<MeasuringInstrumentStatus, string> = {
    active: 'green',
    retired: 'default',
};

const resultColor: Record<CalibrationResult, string> = {
    pass: 'green',
    fail: 'red',
    adjusted: 'orange',
};

const resultOptions: { value: CalibrationResult; label: string }[] = [
    { value: 'pass', label: 'Pass' },
    { value: 'fail', label: 'Fail' },
    { value: 'adjusted', label: 'Adjusted' },
];

export default function InstrumentsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [calibratingId, setCalibratingId] = useState<number | null>(null);
    const queryClient = useQueryClient();

    // THE URL IS THE LIST'S STATE — the due switch, sort, page and page size
    // — and the SERVER cuts the page: this table used to draw the server's
    // first twenty rows under pagination={false}, with nothing on screen to
    // say a twenty-first existed. The switch used to live in component
    // state and was lost on a refresh.
    const { params, setParams, setPage } = useListParams<InstrumentListParams>(INSTRUMENT_LIST_SPEC);
    const dueOnly = instrumentsDueOnly(params);
    const request = instrumentListRequest(params);
    const { data, isLoading } = useQuery({
        queryKey: ['quality', 'instruments', request],
        queryFn: () => listMeasuringInstruments(request),
        placeholderData: (previous) => previous,
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['quality', 'instruments'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<InstrumentFormValues>({
        resolver: zodResolver(instrumentSchema),
    });

    const createMutation = useMutation({
        mutationFn: createMeasuringInstrument,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create instrument', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: calControl,
        handleSubmit: handleCalSubmit,
        reset: resetCal,
        formState: { errors: calErrors },
    } = useForm<CalibrationFormValues>({ resolver: zodResolver(calibrationSchema) });

    const calibrateMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & CalibrationFormValues) => recordCalibration(id, payload),
        onSuccess: () => {
            invalidate();
            setCalibratingId(null);
            resetCal();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not record calibration', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Measuring Instruments</Typography.Title>
                <Space>
                    <Space>
                        <Typography.Text>Due for calibration only</Typography.Text>
                        <Switch checked={dueOnly} onChange={(checked) => setParams({ due: checked ? '1' : undefined })} />
                    </Space>
                    <Button type="primary" onClick={() => setModalOpen(true)}>New Instrument</Button>
                </Space>
            </Space>

            <Table<MeasuringInstrument>
                scroll={{ x: 'max-content' }}
                sticky={TABLE_STICKY}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                // SORTED BY THE SERVER: every sorter is sortOrder-controlled
                // and re-queries the whole register.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, INSTRUMENT_SORT_FIELDS, INSTRUMENT_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'instruments')}
                columns={[
                    {
                        title: 'Code',
                        key: 'code',
                        dataIndex: 'code',
                        sorter: true,
                        sortOrder: columnSortOrder('code', params.sort, INSTRUMENT_DEFAULT_SORT),
                    },
                    {
                        title: 'Name',
                        key: 'name',
                        dataIndex: 'name',
                        sorter: true,
                        sortOrder: columnSortOrder('name', params.sort, INSTRUMENT_DEFAULT_SORT),
                    },
                    {
                        title: 'Location',
                        key: 'location',
                        dataIndex: 'location',
                        sorter: true,
                        sortOrder: columnSortOrder('location', params.sort, INSTRUMENT_DEFAULT_SORT),
                    },
                    { title: 'Frequency (days)', dataIndex: 'calibration_frequency_days' },
                    {
                        // Nullable: a never-calibrated gauge sorts last either way (server-side).
                        title: 'Last Calibrated',
                        key: 'last_calibrated_date',
                        dataIndex: 'last_calibrated_date',
                        sorter: true,
                        sortOrder: columnSortOrder('last_calibrated_date', params.sort, INSTRUMENT_DEFAULT_SORT),
                    },
                    {
                        title: 'Next Due',
                        key: 'next_calibration_due',
                        dataIndex: 'next_calibration_due',
                        sorter: true,
                        sortOrder: columnSortOrder('next_calibration_due', params.sort, INSTRUMENT_DEFAULT_SORT),
                    },
                    {
                        title: 'Status',
                        key: 'status',
                        dataIndex: 'status',
                        sorter: true,
                        sortOrder: columnSortOrder('status', params.sort, INSTRUMENT_DEFAULT_SORT),
                        render: (status: MeasuringInstrumentStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    {
                        title: 'Actions',
                        // WS-B: `RecordCalibrationRequest` refuses a RETIRED
                        // gauge, so the button that would ask stops asking.
                        // The row stays visible with its calibration history —
                        // only the act of adding to it is closed.
                        render: (_, row) =>
                            row.status === 'retired' ? (
                                <Tooltip title={CONFIGURATION_STATUS_WORDS.retired.description}>
                                    <span>
                                        <Button size="small" disabled>Record Calibration</Button>
                                    </span>
                                </Tooltip>
                            ) : (
                                <Button size="small" onClick={() => setCalibratingId(row.id)}>Record Calibration</Button>
                            ),
                    },
                ]}
                expandable={{
                    expandedRowRender: (row) => (
                        <Table<CalibrationRecord>
                            scroll={{ x: 'max-content' }}
                            rowKey="id"
                            size="small"
                            dataSource={row.calibration_records}
                            pagination={false}
                            columns={[
                                { title: 'Date', dataIndex: 'calibrated_date' },
                                {
                                    title: 'Result',
                                    dataIndex: 'result',
                                    render: (result: CalibrationResult) => <Tag color={resultColor[result]}>{result}</Tag>,
                                },
                                { title: 'Certificate #', dataIndex: 'certificate_number' },
                                { title: 'Performed By', dataIndex: 'performed_by' },
                                { title: 'Notes', dataIndex: 'notes' },
                            ]}
                        />
                    ),
                }}
            />

            <Modal
                maskClosable={false}
                title="New Measuring Instrument"
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
                    <Form.Item label="Location">
                        <Controller name="location" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Calibration Frequency (days)"
                        validateStatus={errors.calibration_frequency_days ? 'error' : ''}
                        help={errors.calibration_frequency_days?.message}
                    >
                        <Controller
                            name="calibration_frequency_days"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={1} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Next Calibration Due"
                        validateStatus={errors.next_calibration_due ? 'error' : ''}
                        help={errors.next_calibration_due?.message}
                    >
                        <Controller
                            name="next_calibration_due"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title="Record Calibration"
                open={calibratingId !== null}
                onCancel={() => setCalibratingId(null)}
                onOk={handleCalSubmit((values) => {
                    if (calibratingId !== null) calibrateMutation.mutate({ id: calibratingId, ...values });
                })}
                confirmLoading={calibrateMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Calibration Date"
                        validateStatus={calErrors.calibrated_date ? 'error' : ''}
                        help={calErrors.calibrated_date?.message}
                    >
                        <Controller
                            name="calibrated_date"
                            control={calControl}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Result" validateStatus={calErrors.result ? 'error' : ''} help={calErrors.result?.message}>
                        <Controller
                            name="result"
                            control={calControl}
                            render={({ field }) => <Select {...field} options={resultOptions} />}
                        />
                    </Form.Item>
                    <Form.Item label="Certificate Number">
                        <Controller name="certificate_number" control={calControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Performed By">
                        <Controller name="performed_by" control={calControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Notes">
                        <Controller name="notes" control={calControl} render={({ field }) => <Input.TextArea {...field} rows={2} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
