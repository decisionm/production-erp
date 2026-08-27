import { Popover, Space, Tag, Typography } from 'antd';
import { Link } from 'react-router-dom';
import { itemDisplayName } from '@/features/inventory/itemIdentity';
import type { Item, ItemRow } from '@/features/inventory/types';

/**
 * THE PACK-VARIANT RELATION, on one row.
 *
 * DEC-20260821-001: a pouch pack and a tray pack that Tally holds as separate
 * stock items are SEPARATE ERP item masters, each mapped 1:1 to its own Tally
 * identity, related to one base product. Nothing about that relation merges
 * two masters or shares a mapping — so this cell links, and never rolls up.
 *
 * The base row shows how many variants hang off it and names them; a variant
 * row names its base. Both are links to the item's own page, because that is
 * the only place either question is actually answered.
 */
export function VariantCell({
    row,
    itemsById,
    variantsByBase,
}: {
    row: ItemRow;
    itemsById: Map<number, Item>;
    variantsByBase: Map<number, Item[]>;
}) {
    const baseId = row.variant_of_item_id;

    if (baseId !== null && baseId !== undefined) {
        // The identity read names the base itself; the items list is the
        // fallback for a row that came from the plain list.
        const base = row.variant_of ?? itemsById.get(baseId);
        const siblings = (variantsByBase.get(baseId) ?? []).filter((item) => item.id !== row.id);

        return (
            <Space size={4} wrap>
                {row.variant_label ? <Tag color="geekblue">{row.variant_label}</Tag> : null}
                <Link to={`/inventory/items/${baseId}`}>{base ? itemDisplayName(base) : `#${baseId}`}</Link>
                {siblings.length > 0 ? (
                    <Popover
                        placement="bottomLeft"
                        content={<VariantList items={siblings} />}
                        title="Other packs of this product"
                    >
                        <Tag style={{ cursor: 'pointer', marginInlineEnd: 0 }}>+{siblings.length}</Tag>
                    </Popover>
                ) : null}
            </Space>
        );
    }

    const variants = variantsByBase.get(row.id) ?? [];
    if (variants.length === 0) return <>—</>;

    return (
        <Popover
            placement="bottomLeft"
            content={<VariantList items={variants} />}
            title="Packs of this product"
        >
            <Tag color="geekblue" style={{ cursor: 'pointer', marginInlineEnd: 0 }}>
                {variants.length} {variants.length === 1 ? 'pack' : 'packs'}
            </Tag>
        </Popover>
    );
}

function VariantList({ items }: { items: Item[] }) {
    return (
        <Space direction="vertical" size={2} style={{ maxWidth: 320 }}>
            {items.map((item) => (
                <Space key={item.id} size={6}>
                    <Link to={`/inventory/items/${item.id}`}>{itemDisplayName(item)}</Link>
                    {item.variant_label ? (
                        <Typography.Text type="secondary">{item.variant_label}</Typography.Text>
                    ) : null}
                </Space>
            ))}
        </Space>
    );
}

export default VariantCell;
