import type { CSSProperties } from 'react';
import { Tag, Tooltip } from 'antd';
import { statusWords } from './configurationWords';

/**
 * Active / Retired — the ONE status tag for every configuration master.
 *
 * It replaces ~20 hand-rolled variants that said different things about the
 * same fact ("In service" on the machines tab, a bare disabled Switch on
 * shifts, nothing at all on most masters). The word is always rendered, so
 * the state never depends on colour alone, and the tooltip says what the
 * state MEANS for picking the record.
 */
export function ActiveStatusTag({
    active,
    /** Off for dense tables where a tooltip on every row is noise. */
    withTooltip = true,
    style,
}: {
    active: boolean;
    withTooltip?: boolean;
    style?: CSSProperties;
}) {
    const words = statusWords(active);
    const tag = (
        <Tag color={words.tone === 'success' ? 'success' : undefined} style={style}>
            {words.label}
        </Tag>
    );
    return withTooltip ? <Tooltip title={words.description}>{tag}</Tooltip> : tag;
}
