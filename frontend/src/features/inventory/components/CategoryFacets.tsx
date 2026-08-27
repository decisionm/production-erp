import { Space, Typography } from 'antd';
import type { CategoryFacet, CategoryFacetKey } from '@/features/inventory/catalogue';

/**
 * THE WAY INTO THE CATALOGUE.
 *
 * Hundreds of items whose names differ by a colour word and a gram figure.
 * Reading a Category tag down all of them to find the packing material is
 * work; choosing "Packing" and being shown only those is not. So the category
 * stops being something to scan and becomes the door.
 *
 * THE COUNT IS THE CONTENT, and it is set larger than its own label for that
 * reason: a storekeeper deciding where to look is reading numbers, not words.
 * The unclassified count is also the only figure on this page that says how
 * much work is left, which is why it sits in the row rather than being the
 * residue of filtering everything else away. No live figure is quoted in this
 * file on purpose — it moves, and `items:summary` is where it is counted.
 *
 * Deliberately not a Segmented control or a row of Tags: both give every
 * option the same visual weight, and these options are not equal — one of
 * them holds most of the catalogue. The selected facet is marked by weight
 * and a rule beneath it, the way a tab is.
 *
 * ANNOUNCED AS ONE EXCLUSIVE CHOICE, because that is what it is. `aria-pressed`
 * would announce nine independent toggles, and choosing "All" would never say
 * that "Packing" had let go — a screen-reader user would have to infer the
 * exclusivity the sighted rule makes obvious.
 */
export function CategoryFacets({
    facets,
    active,
    onSelect,
}: {
    facets: CategoryFacet[];
    active: CategoryFacetKey;
    onSelect: (key: CategoryFacetKey) => void;
}) {
    return (
        <Space size={0} wrap role="radiogroup" aria-label="Filter by category" style={{ marginBottom: 16, rowGap: 4 }}>
            {facets.map((facet) => {
                const selected = facet.key === active;

                return (
                    <button
                        key={facet.key}
                        type="button"
                        className="category-facet"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => onSelect(facet.key)}
                        style={{
                            appearance: 'none',
                            background: 'transparent',
                            border: 0,
                            borderBottom: `2px solid ${selected ? 'var(--ant-color-primary, #1677ff)' : 'transparent'}`,
                            cursor: 'pointer',
                            padding: '4px 14px 6px',
                            textAlign: 'left',
                            font: 'inherit',
                            color: 'inherit',
                        }}
                    >
                        <Space direction="vertical" size={0}>
                            <Typography.Text
                                strong={selected}
                                type={selected ? undefined : 'secondary'}
                                style={{ fontSize: 12, letterSpacing: 0.2 }}
                            >
                                {facet.label}
                            </Typography.Text>
                            <Typography.Text
                                strong={selected}
                                type={facet.count === 0 ? 'secondary' : undefined}
                                style={{ fontSize: 18, fontVariantNumeric: 'tabular-nums', lineHeight: 1.1 }}
                            >
                                {facet.count}
                            </Typography.Text>
                        </Space>
                    </button>
                );
            })}
        </Space>
    );
}

export default CategoryFacets;
