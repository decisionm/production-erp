import { theme } from 'antd';
import { barScale, tallyBars, type AttendanceBar } from '@/features/hrms/attendanceChart';
import type { AttendanceTally } from '@/features/hrms/types';

const W = 640;
const H = 200;
const PAD = { top: 16, right: 12, bottom: 34, left: 34 };

/**
 * A MONTH AS SEVEN BARS.
 *
 * Inline SVG on purpose, following the Ask ERP answer chart: this is seven
 * numbers, which is not a reason to pull a plotting library into the
 * bundle. Every colour comes from the theme's own tokens rather than a
 * literal, because a chart drawn in hard-coded light greys is the exact
 * shape of the dark-mode defect this app has already shipped twice.
 *
 * The count is printed above each bar. A bar chart nobody can read a figure
 * off is decoration, and this one is read by people deciding somebody's pay.
 */
export default function AttendanceChart({ summary, title }: { summary: AttendanceTally; title: string }) {
    const { token } = theme.useToken();
    const bars = tallyBars(summary);
    const { max, ticks } = barScale(bars);

    const innerW = W - PAD.left - PAD.right;
    const innerH = H - PAD.top - PAD.bottom;
    const yFor = (value: number) => PAD.top + innerH - (value / max) * innerH;
    const slot = innerW / bars.length;

    const fill: Record<AttendanceBar['tone'], string> = {
        present: token.colorSuccess,
        half: token.colorWarning,
        absent: token.colorError,
        leave: token.colorInfo,
        off: token.colorTextQuaternary,
        review: token.colorWarningActive,
        mismatch: token.colorErrorBorderHover,
    };

    return (
        <svg
            viewBox={`0 0 ${W} ${H}`}
            role="img"
            aria-label={title}
            style={{ width: '100%', maxWidth: W, display: 'block' }}
        >
            {ticks.map((tick) => (
                <g key={tick}>
                    <line x1={PAD.left} x2={W - PAD.right} y1={yFor(tick)} y2={yFor(tick)} stroke={token.colorSplit} />
                    <text x={PAD.left - 6} y={yFor(tick) + 4} textAnchor="end" fontSize={10} fill={token.colorTextTertiary}>
                        {Math.round(tick)}
                    </text>
                </g>
            ))}

            {bars.map((bar, index) => (
                <g key={bar.key}>
                    <rect
                        x={PAD.left + index * slot + slot * 0.18}
                        y={yFor(bar.value)}
                        width={slot * 0.64}
                        height={PAD.top + innerH - yFor(bar.value)}
                        fill={fill[bar.tone]}
                        rx={2}
                    >
                        <title>{`${bar.label}: ${bar.value}`}</title>
                    </rect>
                    <text
                        x={PAD.left + index * slot + slot / 2}
                        y={yFor(bar.value) - 5}
                        textAnchor="middle"
                        fontSize={11}
                        fill={token.colorText}
                    >
                        {bar.value}
                    </text>
                    <text
                        x={PAD.left + index * slot + slot / 2}
                        y={H - PAD.bottom + 16}
                        textAnchor="middle"
                        fontSize={11}
                        fill={token.colorTextSecondary}
                    >
                        {bar.label}
                    </text>
                </g>
            ))}
        </svg>
    );
}
