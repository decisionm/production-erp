import { UploadOutlined } from '@ant-design/icons';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Button, Descriptions, Empty, Input, Modal, Space, Table, Tag, Typography, Upload } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { hasManageAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import { createAttendanceImport, listAttendanceImports } from '@/features/hrms/api';
import { ATTENDANCE_IMPORT_LIST_SPEC, noMatchLine, pageRangeLine } from '@/features/hrms/list';
import { type PunchWorkbook, punchImportPayload, readPunchWorkbook } from '@/features/hrms/punchReport';
import type { AttendanceImport, AttendanceImportListParams } from '@/features/hrms/types';
import { ListEmpty, ListReadAlert } from '@/lib/ListEmpty';
import { compactParams, narrowingKeys } from '@/lib/listParams';
import { showApiError } from '@/lib/showApiError';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { useListParams } from '@/lib/useListParams';

const statusColor: Record<AttendanceImport['status'], string> = { review: 'orange', applied: 'green' };

/**
 * HRMS › Attendance Import — the runs, newest first, and the Upload
 * button. The workbook is parsed HERE (punchReport.ts, SheetJS); what
 * goes to the server is rows. The confirm shows the period and the counts
 * the parse found, then POST, then the run's review page.
 */
export default function AttendanceImportsPage() {
    const navigate = useNavigate();
    const user = useAuthStore((state) => state.user);
    const mayWrite = hasManageAccess(user, 'hrms');

    const { params, setParams, setPage, reset } = useListParams<AttendanceImportListParams>(ATTENDANCE_IMPORT_LIST_SPEC);
    const listParams = useMemo(() => compactParams(params), [params]);
    const narrowed = narrowingKeys(params).length > 0;
    const [qDraft, setQDraft] = useState(params.q ?? '');
    useEffect(() => setQDraft(params.q ?? ''), [params.q]);

    const query = useQuery({
        queryKey: ['hrms', 'attendance-imports', 'list', listParams],
        queryFn: () => listAttendanceImports(listParams),
        placeholderData: (previous) => previous,
    });

    // The parse waiting for confirmation, with the file it came from.
    const [pending, setPending] = useState<{ fileName: string; parsed: PunchWorkbook } | null>(null);
    const [reading, setReading] = useState(false);

    const create = useMutation({
        mutationFn: createAttendanceImport,
        onSuccess: (run) => {
            setPending(null);
            navigate(`/hrms/attendance-imports/${run.id}`);
        },
        onError: (error) => showApiError(error, 'Could not import'),
    });

    const emptyText = params.q ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={noMatchLine('imports', params.q)}>
            <Button size="small" onClick={() => setParams({ q: undefined })}>
                Clear search
            </Button>
        </Empty>
    ) : (
        'No imports yet.'
    );

    const canConfirm = pending !== null && pending.parsed.period !== null && pending.parsed.employees.length > 0;

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }} wrap>
                <Typography.Title level={3} style={{ margin: 0 }}>Attendance Import</Typography.Title>
                <Space wrap>
                    <Input.Search
                        allowClear
                        placeholder="Period or file name"
                        style={{ width: 220 }}
                        value={qDraft}
                        onChange={(event) => setQDraft(event.target.value)}
                        onSearch={(value) => setParams({ q: value.trim() || undefined })}
                    />
                    {mayWrite ? (
                        <Upload
                            accept=".xlsx"
                            showUploadList={false}
                            beforeUpload={(file) => {
                                setReading(true);
                                readPunchWorkbook(file)
                                    .then((parsed) => setPending({ fileName: file.name, parsed }))
                                    .catch((error: unknown) =>
                                        Modal.error({ title: 'Could not read the file', content: error instanceof Error ? error.message : String(error) }),
                                    )
                                    .finally(() => setReading(false));

                                return false;
                            }}
                        >
                            <Button type="primary" icon={<UploadOutlined />} loading={reading}>
                                Upload punch report
                            </Button>
                        </Upload>
                    ) : null}
                </Space>
            </Space>

            <Space style={{ marginBottom: 8 }} wrap>
                <Typography.Text type="secondary">{pageRangeLine(query.data?.meta, 'imports')}</Typography.Text>
                {narrowed ? (
                    <Button size="small" onClick={reset}>
                        Clear
                    </Button>
                ) : null}
            </Space>

            <ListReadAlert state={query} entity="imports" />

            <Table<AttendanceImport>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={query.isFetching}
                dataSource={query.data?.data}
                pagination={serverPagination(query.data?.meta, setPage, 'imports')}
                locale={{ emptyText: <ListEmpty state={query} entity="imports" empty={emptyText} /> }}
                columns={[
                    {
                        title: 'Period',
                        render: (_, row) => (
                            <Link to={`/hrms/attendance-imports/${row.id}`}>
                                {row.period_from} – {row.period_to}
                            </Link>
                        ),
                    },
                    { title: 'File', dataIndex: 'file_name' },
                    { title: 'Employees', dataIndex: 'employee_count', align: 'right' },
                    { title: 'Days', dataIndex: 'day_count', align: 'right' },
                    { title: 'Issues', dataIndex: 'issue_count', align: 'right' },
                    { title: 'Open', dataIndex: 'open_count', align: 'right' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: AttendanceImport['status']) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Uploaded by', render: (_, row) => row.uploaded_by?.name },
                    { title: 'Uploaded', render: (_, row) => row.created_at.slice(0, 10) },
                ]}
            />

            <Modal
                maskClosable={false}
                title={pending?.fileName ?? 'Punch report'}
                open={pending !== null}
                onCancel={() => setPending(null)}
                okText="Import"
                okButtonProps={{ disabled: !canConfirm }}
                confirmLoading={create.isPending}
                onOk={() => {
                    if (pending) create.mutate(punchImportPayload(pending.parsed, pending.fileName));
                }}
                destroyOnHidden
            >
                {pending ? (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Period">
                                {pending.parsed.period ? `${pending.parsed.period.from} – ${pending.parsed.period.to}` : '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Employees">{pending.parsed.employees.length}</Descriptions.Item>
                            <Descriptions.Item label="Days">
                                {pending.parsed.employees.reduce((sum, employee) => sum + employee.days.length, 0)}
                            </Descriptions.Item>
                            <Descriptions.Item label="Warnings">{pending.parsed.warnings.length}</Descriptions.Item>
                        </Descriptions>
                        {pending.parsed.warnings.length > 0 ? (
                            <ul style={{ marginTop: 12, paddingLeft: 20 }}>
                                {pending.parsed.warnings.map((warning) => (
                                    <li key={warning}>
                                        <Typography.Text type="warning">{warning}</Typography.Text>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </>
                ) : null}
            </Modal>
        </>
    );
}
