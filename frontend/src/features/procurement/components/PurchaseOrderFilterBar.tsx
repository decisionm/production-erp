import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button, DatePicker, Input, Select, Space } from 'antd';
import dayjs from 'dayjs';
import { listAllItems } from '@/features/inventory/api';
import { listAllVendors } from '@/features/procurement/api';
import { hasActiveFilters, sortOptions, statusOptions } from '@/features/procurement/purchaseOrders';
import type { PurchaseOrderListFilters, PurchaseOrderStatus } from '@/features/procurement/types';
import { itemLabel } from '@/lib/itemLabel';

interface PurchaseOrderFilterBarProps {
    filters: PurchaseOrderListFilters;
    onChange: (update: (prev: PurchaseOrderListFilters) => PurchaseOrderListFilters) => void;
}

/**
 * The Purchase Orders list's filter bar — status (any of the five, several
 * at once), vendor, order-date range, item, free text, sort. Every control
 * writes straight into the page's filters, which live in the URL and ARE
 * the query key: the SERVER does the narrowing (ListPurchaseOrdersRequest),
 * nothing here filters client-side. Mirrors SalesFilterBar on purpose so
 * the two bars feel like one bar.
 *
 * The date range is the ORDER date — not when the row was created; that is
 * what someone reconciling a month's buying is asking about.
 */
export default function PurchaseOrderFilterBar({ filters, onChange }: PurchaseOrderFilterBarProps) {
    // The pickers. Keyed the same as the page's own vendor / item queries so
    // TanStack serves both from one fetch; the full lists, never the first
    // page (pickerFullList.test.ts).
    const { data: vendors } = useQuery({ queryKey: ['procurement', 'vendors', 'all'], queryFn: listAllVendors });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });

    const vendorOptions = vendors?.data.map((v) => ({ value: v.id, label: `${v.code} — ${v.name}` })) ?? [];
    const itemOptions = items?.data.map((item) => ({ value: item.id, label: itemLabel(item) })) ?? [];

    // The search box's text as typed; it only becomes the `q` filter on
    // Enter / the search button, so a half-typed number does not fire a
    // request per keystroke. Re-seeded when the URL's q changes under it.
    const [qDraft, setQDraft] = useState(filters.q ?? '');
    useEffect(() => {
        setQDraft(filters.q ?? '');
    }, [filters.q]);

    function set<K extends keyof PurchaseOrderListFilters>(key: K, value: PurchaseOrderListFilters[K]) {
        onChange((prev) => ({ ...prev, [key]: value }));
    }

    return (
        <Space wrap style={{ marginBottom: 12 }}>
            <Select<PurchaseOrderStatus[]>
                mode="multiple"
                allowClear
                placeholder="Any status"
                style={{ minWidth: 200 }}
                options={statusOptions()}
                value={filters.status ?? []}
                onChange={(value) => set('status', value.length > 0 ? value : undefined)}
                maxTagCount="responsive"
            />
            <Select<number>
                allowClear
                showSearch
                optionFilterProp="label"
                placeholder="Any vendor"
                style={{ minWidth: 220 }}
                options={vendorOptions}
                value={filters.vendor_id}
                onChange={(value) => set('vendor_id', value ?? undefined)}
            />
            <DatePicker.RangePicker
                allowEmpty={[true, true]}
                placeholder={['Order date from', 'to']}
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
            <Input.Search
                allowClear
                placeholder="PO no., Tally order no. or vendor"
                style={{ width: 260 }}
                value={qDraft}
                onChange={(event) => setQDraft(event.target.value)}
                onSearch={(value) => set('q', value.trim() || undefined)}
            />
            <Select<string>
                placeholder="Newest first"
                style={{ minWidth: 170 }}
                options={sortOptions()}
                value={filters.sort}
                allowClear
                onChange={(value) => set('sort', value ?? undefined)}
            />
            {hasActiveFilters(filters) && (
                <Button onClick={() => onChange(() => ({}))}>Clear filters</Button>
            )}
        </Space>
    );
}
