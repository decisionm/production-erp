import { Space, Tag, Tooltip } from 'antd';
import {
    ANY_WARNING,
    badgeLabel,
    orderedWarningCounts,
    type WarningFilter,
    warningColor,
    warningTooltip,
} from '@/features/inventory/itemIdentity';
import type { ItemIdentityHealth } from '@/features/inventory/types';

/**
 * THE ITEM MASTER'S HEALTH, as counts you can click.
 *
 * A count and a word per badge, and the sentence behind each one lives in its
 * tooltip — the floor does not read a paragraph over a table. Clicking a badge
 * asks the SERVER for those rows (`?warning=`), which is the only way the
 * filter reaches an item nowhere near the page in front of you.
 *
 * A zero badge is not clickable: a filter that can only ever produce an empty
 * table is a dead control. The counts do NOT add up to the leading total —
 * duplicate name and outbound ambiguity describe overlapping sets by
 * construction — which is exactly why "flagged" is the server's own
 * `items_with_any_warning` and never a sum computed here.
 */
export function IdentityHealthStrip({
    health,
    active,
    onSelect,
}: {
    health: ItemIdentityHealth | undefined;
    active: WarningFilter;
    onSelect: (filter: WarningFilter) => void;
}) {
    const counts = orderedWarningCounts(health?.warnings);
    if (!health || counts.length === 0) return null;

    return (
        <Space size={[4, 8]} wrap style={{ marginBottom: 16 }}>
            <Badge
                label={`Flagged ${health.items_with_any_warning} / ${health.items}`}
                color="default"
                count={health.items_with_any_warning}
                active={active === ANY_WARNING}
                tip="Every item tripping any of the checks below."
                onSelect={() => onSelect(active === ANY_WARNING ? null : ANY_WARNING)}
                onClear={() => onSelect(null)}
            />
            {counts.map((entry) => (
                <Badge
                    key={entry.class}
                    label={`${badgeLabel(entry.class, entry.label)} ${entry.count}`}
                    color={warningColor(entry.class)}
                    count={entry.count}
                    active={active === entry.class}
                    tip={warningTooltip(entry.class)}
                    onSelect={() => onSelect(active === entry.class ? null : entry.class)}
                    onClear={() => onSelect(null)}
                />
            ))}
        </Space>
    );
}

function Badge({
    label,
    color,
    count,
    active,
    tip,
    onSelect,
    onClear,
}: {
    label: string;
    color: string;
    count: number;
    active: boolean;
    tip: string | null;
    onSelect: () => void;
    onClear: () => void;
}) {
    const clickable = count > 0;

    const tag = (
        <Tag
            color={clickable ? color : 'default'}
            closable={active}
            onClose={(event) => {
                event.preventDefault();
                onClear();
            }}
            onClick={clickable ? onSelect : undefined}
            style={{
                cursor: clickable ? 'pointer' : 'default',
                opacity: clickable ? 1 : 0.45,
                fontWeight: active ? 600 : undefined,
                borderWidth: active ? 2 : undefined,
                marginInlineEnd: 0,
            }}
        >
            {label}
        </Tag>
    );

    return tip === null ? <span>{tag}</span> : <Tooltip title={tip}><span>{tag}</span></Tooltip>;
}

export default IdentityHealthStrip;
