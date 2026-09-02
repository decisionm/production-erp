import { chartPoints, chartScale } from '@/features/ask-erp/chart';
import type { AskResult } from '@/features/ask-erp/types';

const W = 640;
const H = 220;
const PAD = { top: 12, right: 12, bottom: 48, left: 56 };

/**
 * A bar or line for a two-column result. Inline SVG on purpose: the page
 * shows one small picture of at most sixty points, which is not a reason
 * to add a chart library to the bundle.
 */
export default function ResultChart({ result }: { result: AskResult }) {
    const points = chartPoints(result);
    if (points.length === 0 || !result.chart) return null;

    const { max, ticks } = chartScale(points);
    const innerW = W - PAD.left - PAD.right;
    const innerH = H - PAD.top - PAD.bottom;
    const yFor = (v: number) => PAD.top + innerH - (v / max) * innerH;
    const slot = innerW / points.length;
    const short = (label: string) => (label.length > 12 ? label.slice(0, 11) + '…' : label);

    return (
        <svg
            viewBox={`0 0 ${W} ${H}`}
            role="img"
            aria-label={`${result.chart.y} by ${result.chart.x}`}
            style={{ width: '100%', maxWidth: W, display: 'block' }}
        >
            {ticks.map((t) => (
                <g key={t}>
                    <line x1={PAD.left} x2={W - PAD.right} y1={yFor(t)} y2={yFor(t)} stroke="#e5e7eb" />
                    <text x={PAD.left - 6} y={yFor(t) + 4} textAnchor="end" fontSize={11} fill="#6b7280">
                        {Number.isInteger(t) ? t : t.toFixed(2)}
                    </text>
                </g>
            ))}
            {result.chart.type === 'bar' ? (
                points.map((p, i) => (
                    <rect
                        key={`${p.label}-${i}`}
                        x={PAD.left + i * slot + slot * 0.15}
                        y={yFor(p.value)}
                        width={slot * 0.7}
                        height={PAD.top + innerH - yFor(p.value)}
                        fill="#1677ff"
                    >
                        <title>{`${p.label}: ${p.value}`}</title>
                    </rect>
                ))
            ) : (
                <polyline
                    fill="none"
                    stroke="#1677ff"
                    strokeWidth={2}
                    points={points.map((p, i) => `${PAD.left + i * slot + slot / 2},${yFor(p.value)}`).join(' ')}
                />
            )}
            {points.map((p, i) => (
                <text
                    key={`l-${i}`}
                    x={PAD.left + i * slot + slot / 2}
                    y={H - PAD.bottom + 14}
                    textAnchor="middle"
                    fontSize={11}
                    fill="#374151"
                >
                    {short(p.label)}
                </text>
            ))}
        </svg>
    );
}
