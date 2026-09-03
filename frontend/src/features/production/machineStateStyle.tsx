import { ExclamationCircleFilled, MinusCircleOutlined, PlayCircleFilled, RetweetOutlined, SwapOutlined } from '@ant-design/icons';
import type { ReactNode } from 'react';
import type { MachineFloorState } from '@/features/production/shiftFloorSummary';
import type { ThemeMode } from '@/theme/mode';

export interface MachineStateStyle {
    /** The rail colour — a 4px edge, where saturation reads as a signal. */
    accent: string;
    /**
     * The colour a WORD or a NUMERAL may take. Deliberately a different value
     * from the accent: the accents are tuned for borders and tags, and
     * #52c41a on white is ~2.3:1 — the loudest glyph on the strip was the one
     * failing WCAG AA. The rail keeps the accent so the colour still means the
     * same thing.
     */
    readable: string;
    /** The card's own tint for a state that colours its whole body. */
    wash: string;
    label: string;
    icon: ReactNode;
}

/**
 * One palette for the five machine states, used by BOTH a card's status rail
 * and the floor-status tiles, so a colour means one thing on that screen.
 *
 * A FUNCTION OF THE MODE, not a constant (03-Sep-2026, owner: the floor "is
 * not good in dark"). The washes are the reason it had to change: #f6ffed,
 * #fffbe6 and #fafafa are near-white BY CONSTRUCTION, so on a dark page they
 * stayed near-white and the cards glared. The dark column tints the same hue
 * at ~10% over the page ground instead, and lifts each readable so a word on
 * a dark card still clears contrast — machineStateStyle.test.ts computes those
 * ratios rather than trusting the eye. The light column is the one that
 * shipped, unchanged.
 */
export function stateStyle(mode: ThemeMode): Record<MachineFloorState, MachineStateStyle> {
    if (mode === 'dark') {
        return {
            down: { accent: '#e8604b', readable: '#ff9483', wash: 'rgba(232, 96, 75, 0.10)', label: 'Down', icon: <ExclamationCircleFilled /> },
            mold_change: { accent: '#e8a72b', readable: '#f0b849', wash: 'rgba(232, 167, 43, 0.10)', label: 'Mold change', icon: <SwapOutlined /> },
            // A run that belongs to another shift keeps its own muted gold, a
            // different tone from the mold-change amber it sits beside.
            running_other_shift: { accent: '#c98d1e', readable: '#e0ae57', wash: 'rgba(201, 141, 30, 0.12)', label: 'Not handed over', icon: <RetweetOutlined /> },
            running: { accent: '#35b06b', readable: '#6ed69b', wash: 'rgba(53, 176, 107, 0.10)', label: 'Running', icon: <PlayCircleFilled /> },
            idle: { accent: '#3a4667', readable: '#93a0bd', wash: 'rgba(175, 185, 208, 0.07)', label: 'Idle', icon: <MinusCircleOutlined /> },
        };
    }

    /*
     * Two readables are DEEPER than the ones that shipped, because the test
     * measured them and they missed the bar this field exists to clear:
     * #389e0d on white was 3.46:1 and #ad6800 was 4.41:1, both under WCAG AA
     * for normal-size text — and `readable` is spent on normal-size text (a
     * breakdown reason, the "Needs attention" line), not only on the big
     * numerals. The greens are now the dashboard's own running green, so one
     * colour means one thing across the two screens. The accents, which are
     * borders and tags rather than glyphs, are untouched.
     */
    return {
        down: { accent: '#ff4d4f', readable: '#cf1322', wash: '#fff1f0', label: 'Down', icon: <ExclamationCircleFilled /> },
        mold_change: { accent: '#faad14', readable: '#a35f00', wash: '#fffbe6', label: 'Mold change', icon: <SwapOutlined /> },
        running_other_shift: { accent: '#d48806', readable: '#a35f00', wash: '#fffbe6', label: 'Not handed over', icon: <RetweetOutlined /> },
        running: { accent: '#52c41a', readable: '#237804', wash: '#f6ffed', label: 'Running', icon: <PlayCircleFilled /> },
        idle: { accent: '#bfbfbf', readable: '#595959', wash: '#fafafa', label: 'Idle', icon: <MinusCircleOutlined /> },
    };
}
