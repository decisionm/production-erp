import { DAY_STATE_COLORS, DAY_STATE_LABELS, dayLabel } from '@/features/hrms/attendanceReview';
import type { DayState } from '@/features/hrms/types';

/**
 * ONE PERSON'S MONTH, one square a day, in date order.
 *
 * This is the whole reason the person view exists: thirty-one squares say
 * in one glance what thirty-one table rows never did. The only warm square
 * is a day still needing an answer, so a month with work left reads as
 * orange dots on a quiet ground, and a finished one has none.
 */
export default function MonthStrip({
    days,
    onPick,
}: {
    days: { date: string; state: DayState }[];
    onPick?: (date: string) => void;
}) {
    if (days.length === 0) return null;

    return (
        <div style={{ display: 'flex', gap: 2, flexWrap: 'wrap' }} role="img" aria-label={`${days.length} days`}>
            {days.map((day) => {
                const label = `${dayLabel(day.date)} — ${DAY_STATE_LABELS[day.state]}`;

                return (
                    <span
                        key={day.date}
                        title={label}
                        aria-label={label}
                        onClick={onPick ? () => onPick(day.date) : undefined}
                        style={{
                            width: 14,
                            height: 18,
                            borderRadius: 2,
                            background: DAY_STATE_COLORS[day.state],
                            // The work is the only square that is raised off
                            // the ground; everything decided lies flat.
                            outline: day.state === 'needs_fix' ? '1px solid #b45309' : 'none',
                            cursor: onPick ? 'pointer' : 'default',
                        }}
                    />
                );
            })}
        </div>
    );
}

/** The strip's key, so the colours are never a thing you have to learn. */
export function MonthStripLegend() {
    const states: DayState[] = ['needs_fix', 'present', 'half_day', 'absent', 'on_leave', 'week_off'];

    return (
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
            {states.map((state) => (
                <span key={state} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, color: '#595959' }}>
                    <span
                        style={{
                            width: 12,
                            height: 12,
                            borderRadius: 2,
                            background: DAY_STATE_COLORS[state],
                            outline: state === 'needs_fix' ? '1px solid #b45309' : 'none',
                        }}
                    />
                    {DAY_STATE_LABELS[state]}
                </span>
            ))}
        </div>
    );
}
