import { Form, Input, Select, Space, Tag, Tooltip } from 'antd';
import { type Control, Controller, useWatch } from 'react-hook-form';
import { CATEGORY_UNCLASSIFIED, type ItemFormValues } from '@/features/inventory/itemForm';
import {
    CATEGORY_OPTIONS,
    categoryLabel,
    confidenceNote,
    itemDisplayName,
} from '@/features/inventory/itemIdentity';
import type { Item, ItemCategoryValue, SuggestionConfidence } from '@/features/inventory/types';
import { itemLabel } from '@/lib/itemLabel';

/**
 * THE FOUR IDENTITY FIELDS on the item edit form.
 *
 * `name` is not among them and is not touched here: it is the Tally wire key,
 * Tally owns it on a linked item, and `display_name` exists so a floor-readable
 * label never costs a rename that would break posting.
 *
 * The category suggestion is DERIVED FROM THE ITEM'S TALLY STOCK GROUP and is
 * SHOWN, never applied — not pre-filled, and not one click away either. The
 * mapping itself is the owner's (DEC-20260827-001), but WHICH items get it
 * written is still a person's call: a button here would have let a storekeeper
 * apply it in bulk from an edit modal (Codex, 12766d3), while
 * `inventory:classify-items` does it deliberately and dry-run first. Reading
 * the group and choosing the category yourself is the whole difference, so the
 * picker is the only way in. A group the decision left unmapped, and an item
 * in no group, arrive here as `null` and this component shows nothing for them.
 */
export function ItemIdentityFields({
    control,
    baseItems,
    suggestedCategory,
    suggestionConfidence,
}: {
    control: Control<ItemFormValues>;
    /** Base products only — a variant is never the parent of another variant. */
    baseItems: Item[];
    suggestedCategory: ItemCategoryValue | null | undefined;
    suggestionConfidence: SuggestionConfidence | null | undefined;
}) {
    const category = useWatch({ control, name: 'category' });

    /*
     * The shared picker format, given the ERP label to work with. Composed
     * rather than hand-built: `itemLabel` drops the SKU when it merely
     * repeats the name, which is this catalogue's NORMAL case — the masters
     * pull seeds the SKU from the Tally name, so a hand-built
     * `${sku} — ${name}` renders "1 Litre Pet Bottle - Ovel — 1 Litre Pet
     * Bottle - Ovel" on most rows. That duplication is the whole reason
     * lib/itemLabel exists.
     */
    const baseOptions = baseItems.map((item) => ({
        value: item.id,
        label: itemLabel({ sku: item.sku, name: itemDisplayName(item) }),
    }));

    // A token a newer server knows and this build does not still has to be
    // selectable back — the Select would otherwise silently drop it on save.
    const categoryOptions: { value: string; label: string }[] = [
        { value: CATEGORY_UNCLASSIFIED, label: 'Unclassified' },
        ...CATEGORY_OPTIONS,
    ];
    if (
        typeof category === 'string'
        && category !== CATEGORY_UNCLASSIFIED
        && !categoryOptions.some((option) => option.value === category)
    ) {
        categoryOptions.push({ value: category, label: categoryLabel(category) });
    }

    const suggestion = suggestedCategory ?? null;
    const showSuggestion = suggestion !== null && suggestion !== category;
    const note = confidenceNote(suggestionConfidence);

    return (
        <>
            <Form.Item
                label="Display name"
                tooltip="What this product is called in the ERP. The Tally name above stays as it is — every voucher line carries it."
            >
                <Controller
                    name="display_name"
                    control={control}
                    render={({ field }) => <Input {...field} value={field.value ?? ''} />}
                />
            </Form.Item>

            <Form.Item
                label="Pack variant of"
                tooltip="DEC-20260821-001 — a pouch pack and a tray pack are separate item masters, each with its own Tally item, related to one base product."
            >
                <Controller
                    name="variant_of_item_id"
                    control={control}
                    render={({ field }) => (
                        <Select
                            value={field.value ?? undefined}
                            onChange={(value) => field.onChange(value ?? null)}
                            onBlur={field.onBlur}
                            options={baseOptions}
                            showSearch
                            allowClear
                            placeholder="Base product"
                            filterOption={(input, option) =>
                                (option?.label ?? '').toLowerCase().includes(input.toLowerCase())}
                        />
                    )}
                />
            </Form.Item>

            <Form.Item label="Variant label">
                <Controller
                    name="variant_label"
                    control={control}
                    render={({ field }) => (
                        <Input {...field} value={field.value ?? ''} placeholder="840/box pouch" />
                    )}
                />
            </Form.Item>

            <Form.Item
                label="Category"
                tooltip="This records what the item IS (DEC-20260827-001). It does not change what any document will accept — that rule is Q59, still open."
            >
                <Controller
                    name="category"
                    control={control}
                    render={({ field }) => (
                        <Select
                            value={field.value ?? undefined}
                            onChange={(value) => field.onChange(value)}
                            onBlur={field.onBlur}
                            options={categoryOptions}
                            placeholder="Unclassified"
                        />
                    )}
                />
                {showSuggestion ? (
                    <Space size={4} style={{ marginTop: 6 }}>
                        <Tag bordered={false}>Tally group</Tag>
                        <Tag bordered={false}>{categoryLabel(suggestion)}</Tag>
                        {/* A judgement call has to say so, or it reads as a
                            finding. `firm` says nothing — a suggestion is
                            already only a suggestion. */}
                        {note === null ? null : (
                            <Tooltip title={note}>
                                <Tag color="gold" bordered={false} style={{ marginInlineEnd: 0 }}>low</Tag>
                            </Tooltip>
                        )}
                    </Space>
                ) : null}
            </Form.Item>
        </>
    );
}

export default ItemIdentityFields;
