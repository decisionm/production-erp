import { DownloadOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Card,
    Col,
    DatePicker,
    Empty,
    Form,
    Input,
    InputNumber,
    Row,
    Select,
    Space,
    Spin,
    Switch,
    Table,
    Tag,
    Typography,
} from 'antd';
import { useState } from 'react';
import { exportErrorSentence, listExportKinds, listExportRuns, runExport } from '@/features/exports/api';
import {
    controlFor,
    fieldLabel,
    filtersSummary,
    groupByModule,
    moduleLabel,
    optionsOf,
    runOutcome,
    serialiseFilters,
} from '@/features/exports/filters';
import type { ExportFilterField, ExportFilterValues, ExportKind, ExportRun } from '@/features/exports/types';
import { columnSorter, filterOptions, onFilterBy } from '@/lib/clientSort';
import { downloadBlob } from '@/lib/csv';
import { TABLE_STICKY } from '@/lib/tableProps';

/** The Outcome column's three states, as the filter menu names them. */
const OUTCOME_LABELS: Record<string, string> = { completed: 'Completed', refused: 'Refused', incomplete: 'Not completed' };

/**
 * The Download / Export Center (MASTER-PLAN Phase 4.5).
 *
 * Every card here is a SERVER export: the same query the list or report
 * screen runs, with the same filters, for the same reader — never the rows
 * a browser happens to have on screen. The page hard-codes no kind and no
 * sentence: the cards are whatever GET /exports lists for this login, each
 * form is drawn from the kind's filter schema, a blocked kind (CEC until
 * the owner's sample document exists) is shown disabled with the server's
 * reason word for word, and a refusal (over the row cap, blocked, no
 * permission, an invalid filter) shows the server's sentence, not one
 * composed here.
 */

const RUNS_QUERY_KEY = ['exports', 'runs'] as const;

/** One filter field, drawn from its schema entry — control chosen by controlFor(). */
function FilterField({ field, disabled }: { field: ExportFilterField; disabled: boolean }) {
    const control = controlFor(field);
    const label = fieldLabel(field.name);
    const rules = field.required ? [{ required: true, message: `${label} is required.` }] : [];

    if (control === 'boolean') {
        return (
            <Form.Item name={field.name} label={label} valuePropName="checked" rules={rules}>
                <Switch disabled={disabled} />
            </Form.Item>
        );
    }

    return (
        <Form.Item name={field.name} label={label} rules={rules}>
            {control === 'date' ? (
                <DatePicker style={{ width: '100%' }} format="YYYY-MM-DD" disabled={disabled} />
            ) : control === 'integer' ? (
                <InputNumber style={{ width: '100%' }} precision={0} disabled={disabled} />
            ) : control === 'number' ? (
                <InputNumber style={{ width: '100%' }} disabled={disabled} />
            ) : control === 'select' ? (
                <Select
                    style={{ width: '100%' }}
                    allowClear
                    showSearch
                    optionFilterProp="label"
                    mode={field.multiple ? 'multiple' : undefined}
                    options={optionsOf(field)}
                    disabled={disabled}
                />
            ) : field.multiple ? (
                // A free-text field that takes several values (an `array`
                // rule over strings, e.g. voucher_type): typed tags, one per
                // value, so the array goes out as an array.
                <Select style={{ width: '100%' }} mode="tags" tokenSeparators={[',']} open={false} disabled={disabled} />
            ) : (
                <Input allowClear disabled={disabled} />
            )}
        </Form.Item>
    );
}

function ExportKindCard({ kind }: { kind: ExportKind }) {
    const queryClient = useQueryClient();
    const [form] = Form.useForm<ExportFilterValues>();
    const [refusal, setRefusal] = useState<string | null>(null);
    const [savedAs, setSavedAs] = useState<string | null>(null);
    const blocked = kind.status === 'blocked';

    const download = useMutation({
        mutationFn: (values: ExportFilterValues) => runExport(kind.key, serialiseFilters(kind.filters, values)),
        onMutate: () => {
            setRefusal(null);
            setSavedAs(null);
        },
        onSuccess: (file) => {
            downloadBlob(file.filename, file.blob);
            setSavedAs(file.filename);
        },
        onError: async (error) => {
            setRefusal(await exportErrorSentence(error));
        },
        // Every POST writes a run — refusals included — so the table below
        // is stale after either outcome.
        onSettled: () => queryClient.invalidateQueries({ queryKey: RUNS_QUERY_KEY }),
    });

    return (
        <Card
            size="small"
            title={kind.label}
            extra={
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    up to {kind.row_cap.toLocaleString('en-IN')} rows
                </Typography.Text>
            }
            style={{ height: '100%' }}
        >
            <Space direction="vertical" size={8} style={{ width: '100%' }}>
                {blocked && (
                    // The server's reason, verbatim — a blocked slot is shown
                    // honestly disabled, never hidden and never guessed at.
                    <Alert type="warning" showIcon message={kind.blocked_reason ?? 'This download is blocked.'} />
                )}
                <Form<ExportFilterValues>
                    form={form}
                    layout="vertical"
                    size="small"
                    disabled={blocked || download.isPending}
                    onFinish={(values) => download.mutate(values)}
                >
                    {kind.filters.length === 0 ? (
                        <Typography.Text type="secondary">No filters — the whole set.</Typography.Text>
                    ) : (
                        <Row gutter={[8, 0]}>
                            {kind.filters.map((field) => (
                                <Col key={field.name} xs={24} sm={12}>
                                    <FilterField field={field} disabled={blocked} />
                                </Col>
                            ))}
                        </Row>
                    )}
                    <Form.Item style={{ marginBottom: 0 }}>
                        <Button
                            type="primary"
                            htmlType="submit"
                            icon={<DownloadOutlined />}
                            disabled={blocked}
                            loading={download.isPending}
                        >
                            Download
                        </Button>
                    </Form.Item>
                </Form>
                {refusal && <Alert type="error" showIcon message={refusal} />}
                {savedAs && !refusal && (
                    <Typography.Text type="success" style={{ fontSize: 12 }}>
                        Saved as {savedAs}
                    </Typography.Text>
                )}
            </Space>
        </Card>
    );
}

function RecentDownloads({ kinds }: { kinds: readonly ExportKind[] }) {
    const { data, isLoading, isError, error } = useQuery({ queryKey: RUNS_QUERY_KEY, queryFn: listExportRuns });
    const labelOf = (key: string) => kinds.find((kind) => kind.key === key)?.label ?? key;
    const runs = data ?? [];
    const outcomeOf = (run: ExportRun) => runOutcome(run).state;

    return (
        <Table<ExportRun>
            sticky={TABLE_STICKY}
            size="small"
            rowKey="id"
            loading={isLoading}
            // The whole run list is in the browser (an unpaged array), so the
            // sorters and filters are client-side over all of it.
            pagination={false}
            scroll={{ x: 'max-content' }}
            dataSource={runs}
            locale={{
                emptyText: isError
                    ? `Could not read your downloads: ${(error as { message?: string })?.message ?? 'unknown error'}`
                    : 'Nothing downloaded from this login yet.',
            }}
            columns={[
                {
                    title: 'Kind',
                    dataIndex: 'kind',
                    sorter: columnSorter((run) => labelOf(run.kind), 'text'),
                    filters: filterOptions(runs, (run) => run.kind, (key) => labelOf(String(key))),
                    onFilter: onFilterBy((run) => run.kind),
                    render: (key: string) => labelOf(key),
                },
                {
                    title: 'Filters',
                    dataIndex: 'filters',
                    render: (filters: Record<string, unknown>) => (
                        <Typography.Text style={{ fontSize: 12 }}>{filtersSummary(filters)}</Typography.Text>
                    ),
                },
                {
                    title: 'Rows',
                    dataIndex: 'row_count',
                    align: 'right',
                    sorter: columnSorter((run) => run.row_count, 'number'),
                    render: (n: number) => n.toLocaleString('en-IN'),
                },
                { title: 'File', dataIndex: 'file_name', render: (name: string) => <Typography.Text code>{name}</Typography.Text> },
                {
                    title: 'Outcome',
                    filters: filterOptions(runs, outcomeOf, (state) => OUTCOME_LABELS[String(state)] ?? String(state)),
                    onFilter: onFilterBy(outcomeOf),
                    render: (_, run) => {
                        const outcome = runOutcome(run);
                        const color = outcome.state === 'completed' ? 'green' : outcome.state === 'refused' ? 'red' : 'orange';

                        return <Tag color={color}>{outcome.text}</Tag>;
                    },
                },
                {
                    title: 'When',
                    dataIndex: 'created_at',
                    sorter: columnSorter((run) => run.created_at, 'date'),
                    // A server instant (created_at), so the browser's clock is
                    // the right one to read it on — see lib/datetime.ts.
                    render: (at: string | null) => (at ? new Date(at).toLocaleString() : '—'),
                },
            ]}
        />
    );
}

export default function ExportCenterPage() {
    const catalogue = useQuery({ queryKey: ['exports', 'catalogue'], queryFn: listExportKinds });
    const kinds = catalogue.data ?? [];
    const groups = groupByModule(kinds);

    return (
        <Space direction="vertical" size={16} style={{ width: '100%' }}>
            <div>
                <Typography.Title level={3} style={{ marginBottom: 4 }}>Downloads</Typography.Title>
                <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>
                    Every file here is built on the server from the same query the screen runs, with the filters you
                    set, for your login — the file carries no column your screen would not show you. Over a kind's row
                    cap the server refuses and says how many rows matched; narrow the range and ask again.
                </Typography.Paragraph>
            </div>

            {catalogue.isLoading ? (
                <Spin />
            ) : catalogue.isError ? (
                <Alert
                    type="error"
                    showIcon
                    message={`Could not read the download catalogue: ${(catalogue.error as { message?: string })?.message ?? 'unknown error'}`}
                />
            ) : groups.length === 0 ? (
                <Empty description="No downloads are offered to your login." />
            ) : (
                groups.map((group) => (
                    <div key={group.module}>
                        <Typography.Title level={5} style={{ marginTop: 0 }}>{moduleLabel(group.module)}</Typography.Title>
                        <Row gutter={[16, 16]}>
                            {group.kinds.map((kind) => (
                                <Col key={kind.key} xs={24} lg={12} xxl={8}>
                                    <ExportKindCard kind={kind} />
                                </Col>
                            ))}
                        </Row>
                    </div>
                ))
            )}

            <div>
                <Typography.Title level={5}>Recent downloads</Typography.Title>
                <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
                    Your own recent asks, newest first — refusals included, so what was tried is on record.
                </Typography.Paragraph>
                <RecentDownloads kinds={kinds} />
            </div>
        </Space>
    );
}
