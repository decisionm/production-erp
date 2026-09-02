import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import FactoryRulesTab from './FactoryRulesTab';
import type { FactorySetting } from '@/features/production/types';

/**
 * THE FACTORY RULES TAB SAYS WHAT EACH ROW DOES, IN ITS OWN TYPE.
 *
 * A server render with a seeded cache: no request is made, so what the
 * page shows is a function of the rows alone. Pinned here: a yes/no rule
 * is a Yes/No control and not a text box; a row nothing reads wears "Not
 * in use"; the last change names who and why; a reader without manage sees
 * values, never controls.
 */

let permissions: string[] = ['production.view', 'production.manage'];

vi.mock('@/features/auth/store', () => ({
    useAuthStore: (selector: (state: unknown) => unknown) =>
        selector({
            user: { id: 7, name: 'Fixture Manager', email: 'fixture@example.test', is_active: true, permissions },
            setUser: vi.fn(),
        }),
}));

const rows: FactorySetting[] = [
    {
        id: 1,
        key: 'masterbatch_colour_map',
        value: '{"Amber": 121}',
        typed_value: { Amber: 121 },
        data_type: 'json',
        scope: 'production',
        label: 'Masterbatch by colour',
        description: 'Which masterbatch each bottle colour uses.',
        confirmation_status: null,
        applied: true,
        is_active: true,
        effective_from: null,
        change_reason: 'Owner named Master Batch Amber',
        changed_by: 'Fixture Manager',
        updated_at: '2026-08-06T10:15:00+05:30',
    },
    {
        id: 2,
        key: 'REQUIRE_OVERRIDE_REASON',
        value: 'true',
        typed_value: true,
        data_type: 'boolean',
        scope: 'production',
        label: 'Require a reason for every override',
        description: 'Every cycle, cavity, pieces or rejection override must carry a reason.',
        confirmation_status: 'Recommended',
        applied: false,
        is_active: true,
        effective_from: null,
        change_reason: null,
        changed_by: null,
        updated_at: '2026-07-29T10:00:00+05:30',
    },
    {
        id: 3,
        key: 'GLOBAL_CYCLE_TIME_MIN',
        value: '8',
        typed_value: '8',
        data_type: 'decimal',
        scope: 'production',
        label: 'Global minimum cycle time (s)',
        description: null,
        confirmation_status: 'Discussion Confirmed',
        applied: false,
        is_active: true,
        effective_from: null,
        change_reason: null,
        changed_by: null,
        updated_at: null,
    },
];

function render(): string {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    queryClient.setQueryData(['production', 'factory-settings'], { data: rows });
    queryClient.setQueryData(['production', 'factory-warehouse-settings'], {
        finished_goods_warehouse_id: 10,
        finished_goods_resolved_warehouse_id: 10,
        raw_material_warehouse_id: 10,
        raw_material_resolved_warehouse_id: 10,
        packing_material_warehouse_id: null,
        packing_material_resolved_warehouse_id: null,
    });
    queryClient.setQueryData(['inventory', 'warehouses', 'all'], {
        data: [{ id: 10, code: 'STORE', name: 'Store', is_active: true }],
    });

    return renderToString(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter initialEntries={['/production/configuration?tab=settings']}>
                <FactoryRulesTab />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('FactoryRulesTab', () => {
    it('edits a yes/no rule with a Yes/No control and a number with a number field', () => {
        permissions = ['production.view', 'production.manage'];
        const html = render();

        expect(html).toContain('Require a reason for every override');
        expect(html).toMatch(/ant-segmented[\s\S]*>Yes<[\s\S]*>No</);
        expect(html).toContain('ant-input-number');
        expect(html).toContain('aria-label="Global minimum cycle time (s)"');
    });

    it('marks the rows nothing reads, and the one the floor resolves through', () => {
        permissions = ['production.view', 'production.manage'];
        const html = render();

        expect(html).toContain('In use');
        expect(html).toContain('Not in use');
        expect(html).toContain('Read by');
    });

    it('names who last changed a rule and why', () => {
        permissions = ['production.view', 'production.manage'];
        const html = render();

        expect(html).toContain('Fixture Manager');
        expect(html).toContain('Owner named Master Batch Amber');
        expect(html).toContain('Last changed');
    });

    it('shows a reader without manage the values and no controls', () => {
        permissions = ['production.view'];
        const html = render();

        expect(html).not.toContain('ant-segmented');
        expect(html).not.toContain('ant-input-number');
        expect(html).toContain('>Yes<');
        expect(html).toContain('>8<');
    });
});
