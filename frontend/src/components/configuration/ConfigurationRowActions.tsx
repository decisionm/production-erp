import { Button, Space, Tooltip } from 'antd';
import { configurationActions } from './configurationWords';
import type { ConfigurationAbilities, ConfigurationActionKey } from './types';

/**
 * The row's lifecycle buttons, rendered FROM THE SERVER'S `can` BLOCK.
 *
 * This component never re-derives eligibility. It does not look at
 * `is_active`, it does not count anything, it does not check the user's role
 * — hard delete is Super Admin / Owner level (DEC-20260817-002) and that is
 * decided server-side and arrives as `can.delete`. "UI-only disabling is
 * insufficient" cuts both ways: the UI must not enable what the server
 * disabled, and it must not invent a refusal the server did not make.
 *
 * Two independent questions, kept apart:
 *  - WHICH acts this screen offers → whether a handler was passed.
 *  - WHETHER an offered act is allowed → the `can` block, full stop.
 *
 * `can.delete === null` (an index row: undetermined, because a full sweep is
 * 8–30 COUNTs per row) leaves Delete pressable — pressing it is what asks.
 */
export function ConfigurationRowActions({
    can,
    onEdit,
    onActivate,
    onArchive,
    onDelete,
    size = 'small',
    busy = false,
}: {
    can: ConfigurationAbilities | null | undefined;
    onEdit?: () => void;
    onActivate?: () => void;
    onArchive?: () => void;
    onDelete?: () => void;
    size?: 'small' | 'middle' | 'large';
    busy?: boolean;
}) {
    const handlers: Record<ConfigurationActionKey, (() => void) | undefined> = {
        edit: onEdit,
        activate: onActivate,
        archive: onArchive,
        delete: onDelete,
    };

    const actions = configurationActions(can, {
        edit: onEdit !== undefined,
        activate: onActivate !== undefined,
        archive: onArchive !== undefined,
        delete: onDelete !== undefined,
    });

    if (actions.length === 0) return null;

    return (
        <Space size={4} wrap>
            {actions.map((action) => {
                const button = (
                    <Button
                        size={size}
                        type={action.key === 'edit' ? 'link' : 'text'}
                        danger={action.danger}
                        disabled={!action.enabled || busy}
                        onClick={handlers[action.key]}
                    >
                        {action.label}
                    </Button>
                );
                // A disabled antd Button swallows pointer events, so the
                // tooltip needs a wrapper to have anything to hang on — and a
                // disabled button with no reason shown is exactly the "why is
                // this greyed out?" complaint the contract is aimed at.
                return action.reason === null ? (
                    <span key={action.key}>{button}</span>
                ) : (
                    <Tooltip key={action.key} title={action.reason}>
                        <span>{button}</span>
                    </Tooltip>
                );
            })}
        </Space>
    );
}
