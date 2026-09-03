import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { Paginated } from '@/lib/types';
import type { AttendanceImportEmployee, AttendanceImportLine } from './types';

/**
 * ONE PERSON'S MONTH, OPENED INSIDE THEIR ROW — the thing that replaces
 * one modal per day. Their open days arrive as a single list with the
 * likely answer already chosen, and ONE button saves the lot. A person the
 * employee master does not have is refused rather than guessed at.
 */

vi.mock('@/lib/api', () => ({
    api: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}));

import PersonDays from './components/PersonDays';

function person(overrides: Partial<AttendanceImportEmployee> = {}): AttendanceImportEmployee {
    return {
        employee_code: 'TST-01',
        employee_name: 'EMPLOYEE 01',
        employee_id: 11,
        known: true,
        department: 'Accounts Department',
        designation: 'Accountant',
        day_count: 5,
        open_count: 2,
        resolved_count: 0,
        clean_count: 3,
        days: [],
        ...overrides,
    };
}

function line(id: number, overrides: Partial<AttendanceImportLine> = {}): AttendanceImportLine {
    return {
        id,
        attendance_import_id: 7,
        employee_id: 11,
        employee_code: 'TST-01',
        employee_name: 'EMPLOYEE 01',
        date: '2026-07-02',
        raw_status: 'Absent',
        first_in: null,
        last_out: null,
        ot_minutes: 0,
        late_minutes: 0,
        early_minutes: 0,
        worked_minutes: 0,
        issue: 'no_punch',
        resolution: null,
        resolved_check_in: null,
        resolved_check_out: null,
        resolved_at: null,
        notes: null,
        applied_at: null,
        ...overrides,
    };
}

function render(subject: AttendanceImportEmployee, rows: AttendanceImportLine[], mayWrite = true): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    const page: Paginated<AttendanceImportLine> = {
        data: rows,
        meta: { current_page: 1, per_page: 100, total: rows.length, last_page: 1 },
    };
    client.setQueryData(['hrms', 'attendance-imports', 7, 'lines', 'person', subject.employee_code, 'open'], page);

    return renderToString(
        <QueryClientProvider client={client}>
            <MemoryRouter>
                <PersonDays importId={7} person={subject} mayWrite={mayWrite} />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('PersonDays', () => {
    it('offers one save for every day the person owes, without leaving the list', () => {
        const html = render(person(), [line(1), line(2, { date: '2026-07-03' })]);

        expect(html).toContain('Save 2 days');
        expect(html).toContain('Needs an answer (2)');
        expect(html).toContain('Whole month (5)');
        expect(html).toContain('Accounts Department · Accountant');
    });

    it('lists each day by name with what the clock recorded and the answer chosen for it', () => {
        const html = render(person(), [line(1), line(2, { date: '2026-07-03', issue: 'in_no_out', first_in: '09:58' })]);

        expect(html).toContain('Thu 2 Jul');
        expect(html).toContain('Fri 3 Jul');
        expect(html).toContain('No punch');
        expect(html).toContain('In 09:58, no out');
        // A day nobody punched opens on Absent; a half-recorded one on Present.
        expect(html).toContain('Absent');
        expect(html).toContain('Present');
    });

    it('says the person is finished rather than showing an empty table', () => {
        const html = render(person({ open_count: 0 }), []);

        expect(html).toContain('Nothing left to answer for this person.');
        expect(html).not.toContain('Save 0 days');
    });

    it('refuses to answer for somebody the employee master does not have', () => {
        const html = render(
            person({ employee_code: 'ZZZ-99', employee_name: 'NOBODY', employee_id: null, known: false, department: null }),
            [line(1, { employee_code: 'ZZZ-99', employee_id: null, issue: 'unknown_employee' })],
        );

        expect(html).toContain('has no employee record, so these days cannot be answered yet');
        expect(html).toContain('/hrms/employees?q=ZZZ-99');
        expect(html).not.toContain('Save 1 day');
    });

    it('offers a viewer the reading but no save', () => {
        const html = render(person(), [line(1)], false);

        expect(html).not.toContain('Save 2 days');
        expect(html).toContain('Thu 2 Jul');
    });
});
