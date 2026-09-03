import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { rangeFor } from './attendanceRange';
import type { AttendanceMine } from './types';

/**
 * MY OWN MONTH, AND WHAT A LOGIN WITHOUT HRMS RIGHTS SEES.
 *
 * The page's first card is the reader's own attendance and needs no HRMS
 * permission — the read behind it answers only for whoever is asking. The
 * two cards under it are everybody else's, and a login without the manage
 * permission must not be shown them at all.
 */

vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } } })),
        post: vi.fn(),
    },
}));

/** A packer: a real login, no HRMS rights of any kind. */
const packer = { id: 9, name: 'Anand', email: 'anand@example.com', is_active: true, roles: [], permissions: [] };

vi.mock('@/features/auth/store', () => ({
    useAuthStore: (selector: (state: { user: unknown; setUser: () => void }) => unknown) =>
        selector({ user: packer, setUser: () => undefined }),
}));

import MyAttendanceCard from './components/MyAttendanceCard';
import AttendancePage from './pages/AttendancePage';
import MyAttendancePage from './pages/MyAttendancePage';

const range = { from: '2026-07-01', to: '2026-07-31' };

const mine = (over: Partial<AttendanceMine> = {}): AttendanceMine => ({
    employee: { id: 9, employee_code: 'SPP-01', name: 'Anand', department: 'Production Department', designation: 'Packing Staff' },
    from: range.from,
    to: range.to,
    days: [
        {
            id: null,
            date: '2026-07-01',
            status: 'present',
            check_in: '2026-07-01T00:30:00+00:00',
            check_out: '2026-07-01T08:30:00+00:00',
            notes: null,
            source: 'import',
            needs_review: false,
            provisional: true,
        },
    ],
    summary: {
        present: 18, absent: 2, half_day: 1, on_leave: 0, recorded: 21,
        week_off: 4, needs_review: 3, from_import: 25, mismatches: 6,
    },
    ...over,
});

function render(node: React.ReactElement, seed: (client: QueryClient) => void): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    seed(client);

    return renderToString(
        <QueryClientProvider client={client}>
            <MemoryRouter>{node}</MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('MyAttendanceCard', () => {
    it('shows my own month, its counts and the shape of it', () => {
        const html = render(<MyAttendanceCard range={range} />, (client) => {
            client.setQueryData(['hrms', 'attendance', 'me', range.from, range.to], mine());
        });

        expect(html).toContain('My attendance');
        expect(html).toContain('Anand');
        expect(html).toContain('1 Jul');
        // The counts, including the two the punch report is answerable for.
        expect(html).toContain('Needs review');
        expect(html).toContain('Mismatches');
        // And the graph, which is what the owner asked to see beside them.
        expect(html).toContain('<svg');
        expect(html).toContain('the month in days');
    });

    it('says plainly when a login has no employee record behind it', () => {
        const html = render(<MyAttendanceCard range={range} />, (client) => {
            client.setQueryData(['hrms', 'attendance', 'me', range.from, range.to], mine({ employee: null, days: [] }));
        });

        expect(html).toContain('not linked to an employee record');
        // Blank, not a guessed month: no totals are shown for nobody.
        expect(html).not.toContain('Days recorded');
    });
});

describe('My Attendance, on its own page', () => {
    it('is where a login without HRMS rights reads its own month', () => {
        // The page opens on THIS month rather than on the HRMS page's range,
        // so the fixture is seeded under the range it will actually ask for.
        const own = rangeFor('this_month');
        const html = render(<MyAttendancePage />, (client) => {
            client.setQueryData(['hrms', 'attendance', 'me', own.from, own.to], mine({ from: own.from, to: own.to }));
        });

        expect(html).toContain('My Attendance');
        expect(html).toContain('Anand');
    });
});

describe('the HRMS Attendance page for a login with no HRMS rights', () => {
    it('shows nobody at all — not even the reader', () => {
        const html = render(<AttendancePage />, (client) => {
            client.setQueryData(['hrms', 'attendance', 'me', range.from, range.to], mine());
        });

        // Your own month is NOT duplicated here; it has its own page.
        expect(html).not.toContain('My attendance');
        // And the four management reads are not merely refused by the
        // server — they are not on the page.
        expect(html).not.toContain('One person');
        expect(html).not.toContain('By department');
        expect(html).not.toContain('Insights');
        expect(html).not.toContain('All marks');
    });
});
