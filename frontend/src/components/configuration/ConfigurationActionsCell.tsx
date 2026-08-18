import { type ReactNode, useState } from 'react';
import { Input, Modal, Space, Typography } from 'antd';
import { showApiError } from '@/lib/showApiError';
import { ConfigurationRowActions } from './ConfigurationRowActions';
import { DeleteConfigurationModal } from './DeleteConfigurationModal';
import {
    CONFIGURATION_ACTION_LABEL,
    REASON_LABEL,
    REASON_MODAL_TITLE,
    REASON_REQUIRED,
    REASON_REQUIRED_ACTIVATE,
} from './configurationWords';
import { configurationEndpoint, configurationEntity, type ConfigurationEntityKey } from './entities';
import { useConfigurationLifecycle } from './useConfigurationLifecycle';
import type { ConfigurationAbilities } from './types';

/**
 * ONE cell, wired once, for every configuration master's row actions.
 *
 * `Create → View → Edit → Activate/Deactivate → Safe Delete → Audit`
 * (DEC-20260817-002). The pieces already existed after Phase 7.6 — the row
 * actions that read `can`, the delete confirm that renders the refusal lists,
 * the hook that calls the three endpoints. What did not exist was anything
 * joining them, so each of the 26 master pages would have grown its own copy
 * of that join. This is the copy.
 *
 * Three rules it keeps, and they are the whole point:
 *
 *  1. **The server decides.** Every button's enabled state comes from `can`,
 *     straight off the row. Nothing here looks at `is_active`, counts a
 *     dependency, or checks a role — hard delete is Super Admin / Owner level
 *     and that verdict arrives as `can.delete`. A page that passed a wrongly
 *     computed flag could only ever overrule the server on the server's own
 *     question.
 *  2. **A screen may hide an act; it may never enable one.** Which acts are
 *     offered is decided by which handlers the page gives (`onEdit`), plus
 *     the two the mechanism always serves.
 *  3. **The delete pre-check only runs where a `show` exists.** Asking a
 *     module that serves no `show` would 404 and paint "could not check what
 *     uses this record" over every row. Those masters let the DELETE itself
 *     answer, which is the mechanism's authoritative path regardless.
 *
 * One hook instance per row, because each row's busy state and each row's
 * refusal belong to that row. That is legal precisely because this is a
 * component: the hook is never called inside a `.map()` callback.
 */
export function ConfigurationActionsCell({
    entity,
    id,
    can,
    recordName = null,
    parentId = null,
    onEdit,
    offer = {},
    size = 'small',
    extra,
    onChanged,
}: {
    entity: ConfigurationEntityKey;
    id: number | string;
    /** The row's `can` block, verbatim from the API. */
    can: ConfigurationAbilities | null | undefined;
    /** What the confirm calls this record — its code or name. */
    recordName?: string | null;
    /** Required for a nested resource (a packing option under its standard). */
    parentId?: number | string | null;
    onEdit?: () => void;
    /**
     * Acts this SCREEN chooses not to show. Never used to enable anything —
     * `{ delete: false }` on a screen that is not the place to delete from.
     */
    offer?: Partial<Record<'activate' | 'archive' | 'delete', boolean>>;
    size?: 'small' | 'middle' | 'large';
    /** The page's own row buttons (Approve, Copy to draft, Barcode…). */
    extra?: ReactNode;
    onChanged?: () => void;
}) {
    const spec = configurationEntity(entity);
    const [deleting, setDeleting] = useState(false);
    const [reasonFor, setReasonFor] = useState<'archive' | 'activate' | null>(null);
    const [reason, setReason] = useState('');

    const lifecycle = useConfigurationLifecycle({
        endpoint: configurationEndpoint(entity, parentId),
        invalidateKeys: spec.invalidateKeys,
        onArchived: onChanged,
        onActivated: onChanged,
        onDeleted: onChanged,
    });

    const askFor = (act: 'archive' | 'activate') => {
        setReason('');
        setReasonFor(act);
    };

    const submitReason = () => {
        if (reasonFor === null || reason.trim() === '') return;
        const mutation = reasonFor === 'archive' ? lifecycle.archive : lifecycle.activate;
        const failed = reasonFor === 'archive' ? 'Could not archive' : 'Could not reactivate';
        mutation
            .mutateAsync({ id, reason: reason.trim() })
            .then(() => setReasonFor(null))
            .catch((error: unknown) => showApiError(error, `${failed} this ${spec.label}`));
    };

    return (
        <>
            <Space size={4} wrap>
                <ConfigurationRowActions
                    can={can}
                    size={size}
                    busy={lifecycle.isBusy}
                    onEdit={onEdit}
                    onActivate={offer.activate === false ? undefined : () => askFor('activate')}
                    onArchive={offer.archive === false ? undefined : () => askFor('archive')}
                    onDelete={offer.delete === false ? undefined : () => setDeleting(true)}
                />
                {extra}
            </Space>

            <DeleteConfigurationModal
                open={deleting}
                entityLabel={spec.label}
                recordName={recordName}
                can={can}
                // Only where the module actually serves `show`; elsewhere the
                // DELETE is the authority and the modal skips the pre-check.
                loadAbilities={spec.hasShow ? () => lifecycle.loadAbilities(id) : undefined}
                onDelete={() => lifecycle.remove.mutateAsync({ id })}
                onArchive={
                    offer.archive === false
                        ? undefined
                        : (given: string) => lifecycle.archive.mutateAsync({ id, reason: given })
                }
                onClose={() => setDeleting(false)}
            />

            <Modal
                open={reasonFor !== null}
                maskClosable={false}
                destroyOnHidden
                title={reasonFor === null ? '' : REASON_MODAL_TITLE[reasonFor]}
                okText={reasonFor === 'activate' ? CONFIGURATION_ACTION_LABEL.activate : CONFIGURATION_ACTION_LABEL.archive}
                okButtonProps={{ disabled: reason.trim() === '' }}
                confirmLoading={lifecycle.isBusy}
                onOk={submitReason}
                onCancel={() => setReasonFor(null)}
            >
                <Typography.Paragraph type="secondary" style={{ marginBottom: 4 }}>
                    {reasonFor === 'activate'
                        ? 'The record is offered for new work again. Nothing already recorded against it changes.'
                        : `This ${spec.label} keeps its code and its history, and stops being offered for new work. It can be brought back.`}
                </Typography.Paragraph>
                <Typography.Text strong>{REASON_LABEL}</Typography.Text>
                <Input.TextArea
                    rows={3}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    placeholder={reasonFor === 'activate' ? REASON_REQUIRED_ACTIVATE : REASON_REQUIRED}
                />
            </Modal>
        </>
    );
}
