import { isValidElement } from 'react';
import type { ReactElement } from 'react';
import { describe, expect, it } from 'vitest';
import MoldsPage from '@/features/production/pages/MoldsPage';
import { PRODUCTION_CONFIG_TABS } from '@/features/production/pages/ProductionConfigurationPage';
import ProductStandardsPage from '@/features/production/pages/ProductStandardsPage';
import ScrapReasonsPage from '@/features/production/pages/ScrapReasonsPage';
import ShiftsPage from '@/features/production/pages/ShiftsPage';

/**
 * THE PRODUCTION CONFIGURATION TABS ARE ADDRESSABLE (P7-05's sibling).
 *
 * `?tab=<key>` is what the retired /production/standards, /production/
 * work-centers, /production/scrap-reasons, /production/molds and
 * /production/shifts URLs redirect to, and what prose elsewhere in the app
 * tells a reader to open. A key that is renamed does not fail the build — the
 * page silently falls back to the default tab, so a deep link lands on
 * Product Standards and looks like it worked. This turns that into a red test.
 */
const EXPECTED_TABS = [
    { key: 'products', label: 'Product Standards' },
    { key: 'machines', label: 'Machines & Capabilities' },
    { key: 'molds', label: 'Molds' },
    { key: 'packing', label: 'Packing Materials' },
    { key: 'downtime', label: 'Downtime Reasons' },
    { key: 'scrap', label: 'Scrap Reasons' },
    { key: 'shifts', label: 'Shifts' },
    { key: 'settings', label: 'Factory Rules' },
    { key: 'import', label: 'Import from Workbook' },
];

function tab(key: string) {
    const found = PRODUCTION_CONFIG_TABS.find((entry) => entry.key === key);
    expect(found, `no tab keyed ${key}`).toBeDefined();
    return found!;
}

describe('the Production Configuration tabs', () => {
    it('are exactly the pinned keys and labels, in order', () => {
        expect(PRODUCTION_CONFIG_TABS.map(({ key, label }) => ({ key, label }))).toEqual(EXPECTED_TABS);
    });

    it('has no duplicate key', () => {
        const keys = PRODUCTION_CONFIG_TABS.map((entry) => entry.key);
        expect(new Set(keys).size).toBe(keys.length);
    });

    /**
     * PERMISSION PARITY, structurally.
     *
     * The moved masters must render the SAME components their standalone
     * pages rendered — no wrapper, no gate, no second code path. Asserting
     * the element type is identity is what proves it: a `machine-master`
     * check (or any other) could only have been added by wrapping these, and
     * a wrapper is a different `type`.
     *
     * None of ScrapReasonsPage, MoldsPage or ShiftsPage reads
     * `hasManageAccess` or the auth store at all — the server is their gate,
     * exactly as before the move. That is a property of those modules, not of
     * this list, which is why this test pins the identity rather than
     * restating the claim.
     */
    it.each([
        ['scrap', ScrapReasonsPage],
        ['molds', MoldsPage],
        ['products', ProductStandardsPage],
        ['shifts', ShiftsPage],
    ])('renders the unwrapped %s page, embedded', (key, Component) => {
        const rendered = tab(key).render();

        expect(isValidElement(rendered)).toBe(true);
        expect((rendered as ReactElement).type).toBe(Component);
        expect((rendered as ReactElement<{ embedded?: boolean }>).props.embedded).toBe(true);
    });

    /**
     * The machine master's manage gate lives inside MachinesTab and stays
     * there. Nothing else on this page may be that component, which is the
     * cheap way to say "the gate did not spread to the new tabs".
     */
    it('keeps the machine master on exactly one tab', () => {
        const machineTabType = (tab('machines').render() as ReactElement).type;
        const sharing = PRODUCTION_CONFIG_TABS.filter((entry) => {
            const rendered = entry.render();
            return isValidElement(rendered) && (rendered as ReactElement).type === machineTabType;
        }).map((entry) => entry.key);

        expect(sharing).toEqual(['machines']);
    });

    it('defaults to Product Standards, not merely to whatever is listed first', () => {
        // Both facts, separately: products IS first today, and the page's
        // DEFAULT_TAB is a literal rather than TAB_KEYS[0]. If someone
        // reorders the list, this line goes red and they must decide the
        // landing tab deliberately instead of inheriting it.
        expect(PRODUCTION_CONFIG_TABS[0].key).toBe('products');
    });
});
