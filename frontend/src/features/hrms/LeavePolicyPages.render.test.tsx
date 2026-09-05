import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { Paginated } from '@/lib/types';
import type { LeaveBalance, LeaveType } from './types';

/**
 * THE TWO SCREENS PHASE 1 CHANGED.
 *
 * A leave type now carries how much it adds each MONTH, and a balance says
 * how much of itself was CARRIED IN rather than granted here. Both are the
 * whole point of the phase and both are a column somebody reads off a
 * screen, so both are pinned here: a column that quietly stops rendering
 * is the failure this file exists to catch.
 *
 * Zero is drawn as a dash in both, deliberately — among columns of real
 * figures a 0.00 reads as a balance rather than as an absence.
 */

vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } } })),
        post: vi.fn(),
        put: vi.fn(),
    },
}));

import LeaveBalancesPage from './pages/LeaveBalancesPage';
import LeaveTypesPage from './pages/LeaveTypesPage';

function paged<T>(rows: T[], total: number): Paginated<T> {
    return { data: rows, meta: { current_page: 1, per_page: 20, total, last_page: Math.max(1, Math.ceil(total / 20)) } };
}

function leaveType(over: Partial<LeaveType> = {}): LeaveType {
    return {
        id: 1,
        code: 'CL',
        name: 'Casual Leave',
        default_annual_days: '7.00',
        monthly_accrual_days: '1.00',
        is_active: true,
        created_at: '2026-01-01T00:00:00+05:30',
        ...over,
    };
}

function balance(over: Partial<LeaveBalance> = {}): LeaveBalance {
    return {
        id: 1,
        employee: { id: 9, name: 'Anand' },
        leave_type: leaveType(),
        year: 2026,
        opening_days: '47.50',
        allocated_days: '49.50',
        accrued_days: '2.00',
        used_days: '1.50',
        remaining_days: '48.00',
        ...over,
    };
}

function render(Page: () => ReactElement, key: string, seeded: Paginated<unknown>): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    client.setQueryData(['hrms', key, 'list', {}], seeded);

    return renderToString(
        <QueryClientProvider client={client}>
            <MemoryRouter initialEntries={[`/hrms/${key}`]}>
                <Page />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('LeaveTypesPage', () => {
    it('shows what each type adds every month', () => {
        const html = render(LeaveTypesPage, 'leave-types', paged([leaveType()], 1));

        expect(html).toContain('Monthly Increment');
        expect(html).toContain('1.00');
    });

    it('draws a type that does not accrue monthly as a dash, not a zero', () => {
        const html = render(
            LeaveTypesPage,
            'leave-types',
            paged([leaveType({ code: 'EL', name: 'Earned Leave', monthly_accrual_days: '0.00' })], 1),
        );

        expect(html).toContain('Earned Leave');
        expect(html).toContain('—');
    });
});

describe('LeaveBalancesPage', () => {
    it('separates what was carried in from what the ERP granted', () => {
        const html = render(LeaveBalancesPage, 'leave-balances', paged([balance()], 1));

        expect(html).toContain('Opening');
        expect(html).toContain('Accrued');
        expect(html).toContain('47.50');
        expect(html).toContain('48.00');
    });

    it('draws nothing carried in as a dash, not a zero', () => {
        const html = render(
            LeaveBalancesPage,
            'leave-balances',
            paged([balance({ opening_days: '0.00', allocated_days: '7.00', accrued_days: '7.00', remaining_days: '7.00' })], 1),
        );

        expect(html).toContain('—');
    });
});
