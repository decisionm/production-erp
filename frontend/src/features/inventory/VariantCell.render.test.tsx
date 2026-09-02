import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { VariantCell } from './components/VariantCell';
import type { Item, ItemRow } from './types';

const base = {
    id: 10,
    name: 'Synthetic base product',
    display_name: 'Synthetic base product',
    variant_of_item_id: null,
} as Item;

const variant = {
    id: 11,
    name: 'Synthetic one-pack product',
    display_name: 'Synthetic one-pack product',
    variant_of_item_id: 10,
    variant_label: '1 pack',
} as Item;

function renderCell(row: ItemRow, variants: Map<number, Item[]>): string {
    return renderToString(
        <MemoryRouter>
            <VariantCell
                row={row}
                itemsById={new Map([[base.id, base]])}
                variantsByBase={variants}
            />
        </MemoryRouter>,
    );
}

describe('VariantCell pack controls', () => {
    it('makes a base product pack list keyboard-openable', () => {
        const html = renderCell(base as ItemRow, new Map([[base.id, [variant]]]));

        expect(html).toContain('aria-label="Show 1 pack"');
        expect(html).toContain('<button');
    });

    it('makes a variant sibling list keyboard-openable', () => {
        const sibling = { ...variant, id: 12, variant_label: '2 pack' } as Item;
        const html = renderCell(variant as ItemRow, new Map([[base.id, [variant, sibling]]]));

        expect(html).toContain('aria-label="Show 1 other pack"');
        expect(html).toContain('<button');
    });
});
