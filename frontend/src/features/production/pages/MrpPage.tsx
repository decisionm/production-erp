import { useMutation, useQuery } from '@tanstack/react-query';
import { Button, Form, InputNumber, Modal, Select, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { listItems } from '@/features/inventory/api';
import { getMrpNetRequirements } from '@/features/production/api';
import type { MrpNetRequirement } from '@/features/production/types';
import { itemLabel } from '@/lib/itemLabel';

export default function MrpPage() {
    const [itemId, setItemId] = useState<number | undefined>();
    const [quantity, setQuantity] = useState<number | undefined>();
    const [results, setResults] = useState<MrpNetRequirement[] | null>(null);

    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const itemOptions = items?.data.map((i) => ({ value: i.id, label: itemLabel(i) })) ?? [];

    const mutation = useMutation({
        mutationFn: ({ itemId, quantity }: { itemId: number; quantity: number }) => getMrpNetRequirements(itemId, quantity),
        onSuccess: (data) => setResults(data),
        onError: (error: any) => {
            Modal.error({ title: 'Could not calculate requirements', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Typography.Title level={3}>MRP — Net Material Requirements</Typography.Title>
            <Typography.Paragraph type="secondary">
                Explodes the item&apos;s bill of materials (recursively through any sub-assemblies) for the given
                quantity, and nets it against current on-hand stock. Only purchasable/raw components — items with
                no BOM of their own — are shown, since manufactured sub-assemblies are produced via their own work
                orders rather than procured directly.
            </Typography.Paragraph>

            <Form layout="inline" style={{ marginBottom: 16 }}>
                <Form.Item label="Item">
                    <Select
                        value={itemId}
                        onChange={setItemId}
                        options={itemOptions}
                        showSearch
                        optionFilterProp="label"
                        style={{ width: 280 }}
                    />
                </Form.Item>
                <Form.Item label="Quantity">
                    <InputNumber value={quantity} onChange={(v) => setQuantity(v ?? undefined)} min={0} />
                </Form.Item>
                <Form.Item>
                    <Space>
                        <Button
                            type="primary"
                            disabled={!itemId || !quantity}
                            loading={mutation.isPending}
                            onClick={() => itemId && quantity && mutation.mutate({ itemId, quantity })}
                        >
                            Calculate
                        </Button>
                    </Space>
                </Form.Item>
            </Form>

            {results && (
                <Table<MrpNetRequirement>
                    scroll={{ x: 'max-content' }}
                    rowKey="item_id"
                    dataSource={results}
                    pagination={false}
                    columns={[
                        { title: 'SKU', dataIndex: 'sku' },
                        { title: 'Name', dataIndex: 'name' },
                        { title: 'Gross Required', dataIndex: 'gross_required' },
                        { title: 'On Hand', dataIndex: 'on_hand' },
                        { title: 'Net Required', dataIndex: 'net_required' },
                    ]}
                />
            )}
        </>
    );
}
