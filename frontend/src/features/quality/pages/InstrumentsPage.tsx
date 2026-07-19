import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createMeasuringInstrument, listMeasuringInstruments, recordCalibration } from '@/features/quality/api';
import type { CalibrationRecord, CalibrationResult, MeasuringInstrument, MeasuringInstrumentStatus } from '@/features/quality/types';

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
    const [dueOnly, setDueOnly] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ['quality', 'instruments', dueOnly],
        queryFn: () => listMeasuringInstruments(dueOnly),
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
                        <Switch checked={dueOnly} onChange={setDueOnly} />
                    </Space>
                    <Button type="primary" onClick={() => setModalOpen(true)}>New Instrument</Button>
                </Space>
            </Space>

            <Table<MeasuringInstrument>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Location', dataIndex: 'location' },
                    { title: 'Frequency (days)', dataIndex: 'calibration_frequency_days' },
                    { title: 'Last Calibrated', dataIndex: 'last_calibrated_date' },
                    { title: 'Next Due', dataIndex: 'next_calibration_due' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: MeasuringInstrumentStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
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
