import type { CSSProperties } from 'react';
import { Tag, Tooltip } from 'antd';
import { ActiveStatusTag } from './ActiveStatusTag';
import { configurationEntity, lifecycleStateOf, type ConfigurationEntityKey } from './entities';

/**
 * The status cell for a configuration master, read through the master's OWN
 * column — the screen-side counterpart of `App\Support\Configuration\
 * ActiveFlag`.
 *
 * Two words carry the contract, product-wide: **Active** and **Retired**, both
 * rendered by `ActiveStatusTag` so all 26 screens say the same thing. This
 * component exists for the third case that `ActiveFlag` is careful about and a
 * boolean tag cannot express: a master whose state column is a status enum has
 * a case that is NEITHER — a mould `under_repair`, a `draft` machine exception.
 * Printing "Retired" over a mould that is only being fixed would be a lie the
 * floor acts on, and printing "Active" over it would be a worse one.
 *
 * So the middle case is shown in the factory's own word, in a tone of its own,
 * with a tooltip saying what it means for picking the record. And a row that
 * carries no state at all renders NOTHING — never "Retired" by default.
 *
 * Nothing here is eligibility. Which acts a row offers is the server's `can`
 * block, rendered by `ConfigurationRowActions`.
 */
export function ConfigurationStatusTag({
    entity,
    row,
    withTooltip = true,
    style,
}: {
    entity: ConfigurationEntityKey;
    row: unknown;
    withTooltip?: boolean;
    style?: CSSProperties;
}) {
    const state = lifecycleStateOf(configurationEntity(entity), row);

    if (state.state === 'active' || state.state === 'retired') {
        return <ActiveStatusTag active={state.state === 'active'} withTooltip={withTooltip} style={style} />;
    }

    if (state.state === 'unknown') {
        // No column on the row, or a case nobody declared. A verbatim word if
        // there is one, silence if there is not — an invented verdict about a
        // factory record is the one thing this must not print.
        return state.label === '' ? null : <Tag style={style}>{state.label}</Tag>;
    }

    const tag = (
        <Tag color="warning" style={style}>
            {state.label}
        </Tag>
    );
    return withTooltip ? <Tooltip title={state.description}>{tag}</Tooltip> : tag;
}
