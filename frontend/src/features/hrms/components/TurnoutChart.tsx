import { theme } from 'antd';
import dayjs from 'dayjs';
import { turnoutPoints, turnoutScale } from '@/features/hrms/attendanceChart';
import type { AttendanceTurnoutDay } from '@/features/hrms/types';

const W = 900;
const H = 220;
const PAD = { top: 16, right: 12, bottom: 30, left: 34 };

/**
 * WHO WAS ON THE FLOOR, DAY BY DAY.
 *
 * The totals on the cards above cannot tell a steady month from a fortnight
 * with half the floor missing, and only one of those needs somebody to do
 * something about it. This is the one picture that can.
 *
 * A day nobody has reviewed is stacked ON TOP in a different colour rather
 * than being left out or counted as an absence — a short bar must mean
 * people were missing, not that the office has not finished reading the
 * punch report.
 *
 * Dates are labelled every fifth day: thirty-one labels across a month is
 * an unreadable smear, and the tooltip on each bar carries the exact day.
 */
export default function TurnoutChart({ days }: { days: AttendanceTurnoutDay[] }) {
    const { token } = theme.useToken();
    const points = turnoutPoints(days);
    const { max, ticks } = turnoutScale(points);

    if (points.length === 0) return null;

    const innerW = W - PAD.left - PAD.right;
    const innerH = H - PAD.top - PAD.bottom;
    const heightFor = (value: number) => (value / max) * innerH;
    const slot = innerW / points.length;
    const everyNth = Math.max(1, Math.ceil(points.length / 8));

    return (
        <svg
            viewBox={`0 0 ${W} ${H}`}
            role="img"
            aria-label="People on the floor, day by day"
            style={{ width: '100%', display: 'block' }}
        >
            {ticks.map((tick) => {
                const y = PAD.top + innerH - heightFor(tick);

                return (
                    <g key={tick}>
                        <line x1={PAD.left} x2={W - PAD.right} y1={y} y2={y} stroke={token.colorSplit} />
                        <text x={PAD.left - 6} y={y + 4} textAnchor="end" fontSize={10} fill={token.colorTextTertiary}>
                            {Math.round(tick)}
                        </text>
                    </g>
                );
            })}

            {points.map((point, index) => {
                const x = PAD.left + index * slot + slot * 0.15;
                const width = slot * 0.7;
                const worked = heightFor(point.worked);
                const review = heightFor(point.needsReview);
                const base = PAD.top + innerH;

                return (
                    <g key={point.date}>
                        <rect x={x} y={base - worked} width={width} height={worked} fill={token.colorSuccess}>
                            <title>{`${dayjs(point.date).format('ddd D MMM')}: ${point.worked} on the floor, ${point.absent} absent`}</title>
                        </rect>
                        {point.needsReview > 0 ? (
                            <rect
                                x={x}
                                y={base - worked - review}
                                width={width}
                                height={review}
                                fill={token.colorWarningBorderHover}
                            >
                                <title>{`${dayjs(point.date).format('ddd D MMM')}: ${point.needsReview} days nobody has answered`}</title>
                            </rect>
                        ) : null}
                        {index % everyNth === 0 ? (
                            <text
                                x={x + width / 2}
                                y={H - PAD.bottom + 14}
                                textAnchor="middle"
                                fontSize={10}
                                fill={token.colorTextSecondary}
                            >
                                {dayjs(point.date).format('D MMM')}
                            </text>
                        ) : null}
                    </g>
                );
            })}
        </svg>
    );
}
