import { DownloadOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, Col, Row, Select, Space, Spin, Typography, message } from 'antd';
import { useEffect, useState } from 'react';
import {
    getTallySettings,
    updateLedgerMappings,
    updateTallyCompany,
} from '@/features/tally-sync/api';

export default function TallySettingsPage() {
    const queryClient = useQueryClient();
    const { data, isLoading } = useQuery({ queryKey: ['tally-sync', 'settings'], queryFn: getTallySettings });

    // Local edit state, seeded from the server once loaded.
    const [company, setCompany] = useState<string | null>(null);
    const [mappings, setMappings] = useState<Record<string, string | null>>({});

    useEffect(() => {
        if (data) {
            setCompany(data.company);
            setMappings(data.mappings);
        }
    }, [data]);

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tally-sync', 'settings'] });

    const companyMutation = useMutation({
        mutationFn: () => updateTallyCompany(company),
        onSuccess: () => {
            invalidate();
            message.success('Tally company saved');
        },
        onError: (error: any) => message.error(error?.response?.data?.message ?? 'Could not save company'),
    });

    const mappingsMutation = useMutation({
        mutationFn: () => updateLedgerMappings(mappings),
        onSuccess: () => {
            invalidate();
            message.success('Ledger mappings saved');
        },
        onError: (error: any) => message.error(error?.response?.data?.message ?? 'Could not save mappings'),
    });

    if (isLoading || !data) {
        return <Spin style={{ display: 'block', marginTop: 64 }} />;
    }

    const companyOptions = data.companies.map((c) => ({ value: c, label: c }));

    // Group the (long) ledger list by its Tally group for a clearer dropdown;
    // showSearch still filters across all groups.
    const ledgersByGroup = new Map<string, { value: string; label: string }[]>();
    for (const ledger of data.ledgers) {
        const bucket = ledgersByGroup.get(ledger.group) ?? [];
        bucket.push({ value: ledger.name, label: ledger.name });
        ledgersByGroup.set(ledger.group, bucket);
    }
    const ledgerOptions = [...ledgersByGroup.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([group, options]) => ({ label: group, options }));

    return (
        <Space direction="vertical" size="large" style={{ width: '100%', maxWidth: 720 }}>
            <Typography.Title level={3} style={{ margin: 0 }}>Tally Settings</Typography.Title>

            <Card title="Local Sync Agent">
                <Typography.Paragraph type="secondary">
                    Install this small Windows app on the machine that runs Tally (or one on the same
                    network that can reach it). It bridges Tally to this ERP — pulling masters in and
                    posting vouchers out. After installing, open its Settings and paste an agent token
                    (generate one under <b>Agent Tokens</b>).
                </Typography.Paragraph>
                {data.agent ? (
                    <Space direction="vertical" size={4}>
                        {/* `download`, and deliberately NOT target="_blank".
                            The installer is served as application/x-executable
                            with no Content-Disposition, so a new-tab navigation
                            left Chrome opening a blank tab and doing nothing —
                            the button looked broken while the file was sitting
                            there perfectly reachable. `download` asks the
                            browser to save rather than navigate, which it
                            honours because this is the same origin as the app.
                            Chrome takes the saved name from the URL path and
                            ignores the `?v=` cache-buster, so the file lands as
                            tally-sync-agent-setup-<version>.exe. */}
                        <Button
                            type="primary"
                            icon={<DownloadOutlined />}
                            href={data.agent.url}
                            download
                        >
                            Download Tally Sync Agent (Windows)
                        </Button>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            If nothing happens, Chrome may have blocked it — check Downloads and choose{' '}
                            <b>Keep</b>, or right-click the button and pick <b>Save link as…</b>
                        </Typography.Text>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            {(data.agent.size / (1024 * 1024)).toFixed(1)} MB
                            {data.agent.built_at ? ` · built ${data.agent.built_at.slice(0, 10)}` : ''}
                        </Typography.Text>
                    </Space>
                ) : (
                    <Alert
                        type="info"
                        showIcon
                        message="Installer not built yet"
                        description="The agent installer is published by the Build Tally Sync Agent workflow — run it once (Actions → Run workflow), or it builds automatically when the agent's code changes."
                    />
                )}
            </Card>

            <Card title="Tally Company">
                <Typography.Paragraph type="secondary">
                    Which company in the on-site Tally this instance syncs with. The list is populated when the
                    local agent reports the companies it finds — run the agent's masters pull if it's empty. The
                    chosen company must be loaded in the Tally UI for syncing to work.
                </Typography.Paragraph>
                <Space>
                    <Select
                        showSearch
                        allowClear
                        style={{ width: 360 }}
                        placeholder="Select the Tally company to sync"
                        value={company ?? undefined}
                        options={companyOptions}
                        onChange={(value) => setCompany(value ?? null)}
                        notFoundContent="No companies reported by the agent yet"
                    />
                    <Button type="primary" loading={companyMutation.isPending} onClick={() => companyMutation.mutate()}>
                        Save
                    </Button>
                </Space>
            </Card>

            <Card title="Ledger Mappings">
                <Typography.Paragraph type="secondary">
                    Map each posting role to the exact Tally ledger name this client uses. The voucher sync posts
                    to these ledgers instead of any hardcoded name, so a differently-set-up client is handled here,
                    not in code. Options come from the ledgers pulled from Tally.
                </Typography.Paragraph>

                {data.ledgers.length === 0 && (
                    <Alert
                        type="info"
                        showIcon
                        style={{ marginBottom: 16 }}
                        message="No ledgers pulled yet"
                        description="Run the agent's masters pull first so the ledger list is available to map against."
                    />
                )}

                <Space direction="vertical" size="middle" style={{ width: '100%' }}>
                    {data.roles.map((role) => (
                        <Row key={role.value} align="middle" gutter={12}>
                            <Col span={8}>
                                <Typography.Text strong>{role.label}</Typography.Text>
                            </Col>
                            <Col span={16}>
                                <Select
                                    showSearch
                                    allowClear
                                    optionFilterProp="label"
                                    style={{ width: '100%' }}
                                    placeholder="Unmapped"
                                    value={mappings[role.value] ?? undefined}
                                    options={ledgerOptions}
                                    onChange={(value) =>
                                        setMappings((prev) => ({ ...prev, [role.value]: value ?? null }))
                                    }
                                />
                            </Col>
                        </Row>
                    ))}
                </Space>

                <div style={{ marginTop: 20 }}>
                    <Button type="primary" loading={mappingsMutation.isPending} onClick={() => mappingsMutation.mutate()}>
                        Save Mappings
                    </Button>
                </div>
            </Card>
        </Space>
    );
}
