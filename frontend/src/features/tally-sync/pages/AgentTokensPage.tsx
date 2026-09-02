import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Form, Input, Modal, Popconfirm, Space, Table, Tag, Typography, message } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createAgentToken, listAgentTokens, revokeAgentToken } from '@/features/tally-sync/api';
import type { AgentToken } from '@/features/tally-sync/types';
import { columnSorter } from '@/lib/clientSort';
import { TABLE_STICKY } from '@/lib/tableProps';

const tokenSchema = z.object({
    name: z.string().min(1, 'Name is required').max(100),
});
type TokenFormValues = z.infer<typeof tokenSchema>;

export default function AgentTokensPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [issuedToken, setIssuedToken] = useState<string | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['tally-sync', 'agent-tokens'], queryFn: listAgentTokens });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<TokenFormValues>({
        resolver: zodResolver(tokenSchema),
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tally-sync', 'agent-tokens'] });

    const createMutation = useMutation({
        mutationFn: (values: TokenFormValues) => createAgentToken(values.name),
        onSuccess: (result) => {
            invalidate();
            setModalOpen(false);
            reset();
            setIssuedToken(result.plain_text_token);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not issue token', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const revokeMutation = useMutation({
        mutationFn: revokeAgentToken,
        onSuccess: () => {
            invalidate();
            message.success('Token revoked');
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not revoke token', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Agent Tokens</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>Generate Token</Button>
            </Space>
            <Typography.Paragraph type="secondary">
                Credentials for the local Tally Sync Agent (the Windows tray app) to authenticate with this API.
                Give each machine/installation its own named token so any one of them can be revoked
                independently — see <code>tally-sync-agent/README.md</code> for where this goes in the agent's
                Settings.
            </Typography.Paragraph>

            <Table<AgentToken>
                scroll={{ x: 'max-content' }}
                sticky={TABLE_STICKY}
                rowKey="id"
                loading={isLoading}
                // A handful of tokens, all in the browser: honest client sorts.
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Name', dataIndex: 'name', sorter: columnSorter((row: AgentToken) => row.name, 'text') },
                    {
                        title: 'Abilities',
                        dataIndex: 'abilities',
                        render: (abilities: string[]) => abilities.map((a) => <Tag key={a}>{a}</Tag>),
                    },
                    {
                        // "Never" is an empty value and sorts last either way.
                        title: 'Last Used',
                        dataIndex: 'last_used_at',
                        sorter: columnSorter((row: AgentToken) => row.last_used_at, 'date'),
                        render: (v: string | null) => (v ? v.slice(0, 19).replace('T', ' ') : 'Never'),
                    },
                    {
                        title: 'Created',
                        dataIndex: 'created_at',
                        sorter: columnSorter((row: AgentToken) => row.created_at, 'date'),
                        render: (v: string) => v.slice(0, 10),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Popconfirm
                                title="Revoke this token?"
                                description="Any agent using it will stop being able to sync immediately."
                                onConfirm={() => revokeMutation.mutate(row.id)}
                                okText="Revoke"
                                okButtonProps={{ danger: true }}
                            >
                                <Button size="small" danger loading={revokeMutation.isPending}>
                                    Revoke
                                </Button>
                            </Popconfirm>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="Generate Agent Token"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Name"
                        validateStatus={errors.name ? 'error' : ''}
                        help={errors.name?.message}
                    >
                        <Controller
                            name="name"
                            control={control}
                            render={({ field }) => <Input {...field} placeholder="e.g. Agent - Puducherry Line 1" />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                closable={false}
                title="Token Generated"
                open={issuedToken !== null}
                footer={
                    <Button type="primary" onClick={() => setIssuedToken(null)}>
                        I've copied it
                    </Button>
                }
            >
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="This is shown once and can't be retrieved again"
                    description="Copy it into the agent's Settings window now. If you lose it, revoke this token and generate a new one."
                />
                <Typography.Paragraph
                    copyable={{ text: issuedToken ?? '' }}
                    style={{
                        fontFamily: 'monospace',
                        background: '#f5f5f5',
                        padding: 12,
                        borderRadius: 4,
                        wordBreak: 'break-all',
                    }}
                >
                    {issuedToken}
                </Typography.Paragraph>
            </Modal>
        </>
    );
}
