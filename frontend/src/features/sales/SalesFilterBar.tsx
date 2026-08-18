import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button, DatePicker, Input, InputNumber, Select, Space } from 'antd';
import dayjs from 'dayjs';
import { listAllItems } from '@/features/inventory/api';
import { listCustomers } from '@/features/sales/api';
import { hasActiveFilters, sortOptions, statusOptions } from '@/features/sales/filters';
import type { SalesDocumentKind, SalesListFilters } from '@/features/sales/types';
import { itemLabel } from '@/lib/itemLabel';

interface SalesFilterBarProps {
    kind: SalesDocumentKind;
    filters: SalesListFilters;
    onChange: (update: (prev: SalesListFilters) => SalesListFilters) => void;
}

/**
 * The filter bar the three Sales list pages share — customer, status (where
 * the document has one), the document's own date range, item, the parent
 * order (deliveries and invoices), free text, sort. Every control writes
 * straight into the page's filters, which live in the URL and ARE the query
 * key: the server does the narrowing, nothing here filters client-side.
 *
 * The date range is the DOCUMENT's date — order date, delivered date (the
 * factory's day, decided server-side), invoice date — not when the row was
 * created; that is what someone reconciling a day is asking about.
 */
export default function SalesFilterBar({ kind, filters, onChange }: SalesFilterBarProps) {
    // The pickers. Keyed the same as the pages' own customer / item queries
    // so TanStack serves both from one fetch.
    const { data: customers } = useQuery({
        queryKey: ['sales', 'customers', 'picker'],
        queryFn: () => listCustomers(1, 200),
    });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });

    const customerOptions = customers?.data.map((c) => ({ value: c.id, label: `${c.code} — ${c.name}` })) ?? [];
    const itemOptions = items?.data.map((item) => ({ value: item.id, label: itemLabel(item) })) ?? [];
    const statuses = statusOptions(kind);
    const showsOrderFilter = kind !== 'sales_order';

    // The search box's text as typed; it only becomes the `q` filter on
    // Enter / the search button, so a half-typed number does not fire a
    // request per keystroke. Re-seeded when the URL's q changes under it
    // (a pasted link, Clear filters).
    const [qDraft, setQDraft] = useState(filters.q ?? '');
    useEffect(() => {
        setQDraft(filters.q ?? '');
    }, [filters.q]);

    function set<K extends keyof SalesListFilters>(key: K, value: SalesListFilters[K]) {
        onChange((prev) => ({ ...prev, [key]: value }));
    }

    return (
        <Space wrap style={{ marginBottom: 12 }}>
            <Select<number>
                allowClear
                showSearch
                optionFilterProp="label"
                placeholder="Any customer"
                style={{ minWidth: 220 }}
                options={customerOptions}
                value={filters.customer_id}
                onChange={(value) => set('customer_id', value ?? undefined)}
            />
            {statuses.length > 0 && (
                <Select<string>
                    allowClear
                    placeholder="Any status"
                    style={{ minWidth: 170 }}
                    options={statuses}
                    value={filters.status}
                    onChange={(value) => set('status', value ?? undefined)}
                />
            )}
            <DatePicker.RangePicker
                allowEmpty={[true, true]}
                placeholder={[
                    kind === 'sales_order' ? 'Order date from' : kind === 'delivery' ? 'Delivered from' : 'Invoice date from',
                    'to',
                ]}
                value={[filters.from ? dayjs(filters.from) : null, filters.to ? dayjs(filters.to) : null]}
                onChange={(_, dateStrings) => {
                    const [from, to] = dateStrings;
                    onChange((prev) => ({ ...prev, from: from || undefined, to: to || undefined }));
                }}
            />
            <Select<number>
                allowClear
                showSearch
                optionFilterProp="label"
                placeholder="Any item"
                style={{ minWidth: 220 }}
                options={itemOptions}
                value={filters.item_id}
                onChange={(value) => set('item_id', value ?? undefined)}
            />
            {showsOrderFilter && (
                <InputNumber
                    min={1}
                    precision={0}
                    placeholder="Sales order #"
                    style={{ width: 140 }}
                    value={filters.sales_order_id ?? null}
                    onChange={(value) => set('sales_order_id', typeof value === 'number' && value > 0 ? value : undefined)}
                />
            )}
            <Input.Search
                allowClear
                placeholder={
                    kind === 'delivery' ? 'DN no., reference or customer' : `${kind === 'invoice' ? 'INV' : 'SO'} no. or customer`
                }
                style={{ width: 260 }}
                value={qDraft}
                onChange={(event) => setQDraft(event.target.value)}
                onSearch={(value) => set('q', value.trim() || undefined)}
            />
            <Select<string>
                placeholder="Newest first"
                style={{ minWidth: 170 }}
                options={sortOptions(kind)}
                value={filters.sort}
                allowClear
                onChange={(value) => set('sort', value ?? undefined)}
            />
            {hasActiveFilters(kind, filters) && (
                <Button size="small" onClick={() => onChange(() => ({}))}>
                    Clear filters
                </Button>
            )}
        </Space>
    );
}
