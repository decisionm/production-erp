import { useEffect, useState } from 'react';
import { Alert, Button, Input, List, Modal, Typography } from 'antd';
import { showApiError } from '@/lib/showApiError';
import {
    ARCHIVE_INSTEAD_LABEL,
    CONFIGURATION_ACTION_REFUSED,
    REASON_LABEL,
    REASON_REQUIRED,
    blockingLine,
    canOfferArchive,
    configurationInUse,
    deleteConfirmBody,
    deleteModalTitle,
    inUseHeadline,
} from './configurationWords';
import type { ConfigurationAbilities, ConfigurationInUse } from './types';

/**
 * The ONE delete confirm for configuration masters.
 *
 * The flow the contract asks for:
 *
 *  1. **Confirm.** The abilities of an index row carry `delete: null`
 *     (undetermined — a full dependency sweep is 8–30 COUNTs per row), so the
 *     modal asks `show` first when the page gives it a way to.
 *  2. **The server decides.** The DELETE is attempted; the backend re-runs the
 *     dependency report inside its transaction under a lock. Nothing is
 *     force-deleted and no parent delete cascades — a count above zero, or a
 *     verdict that use could not be proven, is a REFUSAL, never a cleanup.
 *  3. **On a `configuration_in_use` 422**, the blocking reasons are rendered
 *     as a LIST WITH COUNTS (from the payload, not parsed out of prose), and
 *     "Archive instead" is offered when archiving would actually do something.
 *  4. **Any other failure** goes to the shared `showApiError`, which keeps the
 *     field keys.
 */
export function DeleteConfigurationModal({
    open,
    entityLabel,
    recordName = null,
    isActive = true,
    can,
    loadAbilities,
    onDelete,
    onArchive,
    onClose,
}: {
    open: boolean;
    /** The page's word for the thing — "mould", "item", "machine". */
    entityLabel: string;
    recordName?: string | null;
    /** A retired record has nothing to archive; the alternative is hidden for it. */
    isActive?: boolean;
    /** The abilities the list already has. `delete: null` means undetermined. */
    can?: ConfigurationAbilities | null;
    /** Resolves the authoritative abilities (usually the hook's `loadAbilities`). */
    loadAbilities?: () => Promise<ConfigurationAbilities | null>;
    /** Performs the DELETE. Must REJECT with the axios error so the 422 can be read. */
    onDelete: () => Promise<unknown>;
    /** Performs the archive, with the reason the user gave. */
    onArchive?: (reason: string) => Promise<unknown>;
    onClose: () => void;
}) {
    const [resolved, setResolved] = useState<ConfigurationAbilities | null | undefined>(can);
    const [checking, setChecking] = useState(false);
    const [checkFailed, setCheckFailed] = useState(false);
    const [refusal, setRefusal] = useState<ConfigurationInUse | null>(null);
    const [askingReason, setAskingReason] = useState(false);
    const [reason, setReason] = useState('');
    const [pending, setPending] = useState(false);

    // Every open starts clean: a refusal left over from the previous row would
    // be a refusal shown against the wrong record.
    useEffect(() => {
        if (!open) return;
        setResolved(can);
        setRefusal(null);
        setAskingReason(false);
        setReason('');
        setCheckFailed(false);

        if (can?.delete === null || can?.delete === undefined) {
            if (loadAbilities === undefined) return;
            setChecking(true);
            loadAbilities()
                .then((abilities) => setResolved(abilities))
                // Not fatal: the DELETE itself is authoritative and refuses
                // safely. The reader is told the pre-check did not answer
                // rather than being shown a verdict nobody made.
                .catch(() => setCheckFailed(true))
                .finally(() => setChecking(false));
        }
        // `can` and `loadAbilities` are per-row values the page rebuilds on each
        // open; `open` is the edge that matters.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const knownBlocked = resolved?.delete === false;
    const offerArchive =
        onArchive !== undefined &&
        canOfferArchive({
            // After a refusal the server says what the alternative is. BEFORE
            // one there is no payload to read, so the reversible act is offered
            // on the strength of `can.archive` alone — which is still the
            // server's word, never the record re-inspected here.
            alternative: refusal?.alternative ?? 'archive',
            can: resolved,
            isActive,
        });

    const attemptDelete = () => {
        setPending(true);
        onDelete()
            .then(() => onClose())
            .catch((error: unknown) => {
                const inUse = configurationInUse(error);
                // THE discriminator. An in-use refusal renders its counts here;
                // anything else is a plain failure and goes to the shared modal.
                if (inUse !== null) setRefusal(inUse);
                else showApiError(error, `Could not delete this ${entityLabel}`);
            })
            .finally(() => setPending(false));
    };

    const submitArchive = () => {
        if (onArchive === undefined || reason.trim() === '') return;
        setPending(true);
        onArchive(reason.trim())
            .then(() => onClose())
            .catch((error: unknown) => showApiError(error, `Could not archive this ${entityLabel}`))
            .finally(() => setPending(false));
    };

    const footer = () => {
        if (askingReason) {
            return [
                <Button key="back" onClick={() => setAskingReason(false)} disabled={pending}>
                    Back
                </Button>,
                <Button
                    key="archive"
                    type="primary"
                    loading={pending}
                    disabled={reason.trim() === ''}
                    onClick={submitArchive}
                >
                    Archive
                </Button>,
            ];
        }

        const close = (
            <Button key="close" onClick={onClose} disabled={pending}>
                {refusal === null ? 'Cancel' : 'Close'}
            </Button>
        );

        const archiveInstead = offerArchive ? (
            <Button key="archive-instead" type="primary" onClick={() => setAskingReason(true)} disabled={pending}>
                {ARCHIVE_INSTEAD_LABEL}
            </Button>
        ) : null;

        if (refusal !== null) return [close, archiveInstead];

        return [
            close,
            archiveInstead,
            <Button key="delete" danger type="primary" loading={pending || checking} onClick={attemptDelete}>
                {/* Pressing is what asks the server; when it has already said no,
                    the press is what fetches WHAT uses the record. */}
                {knownBlocked ? 'Show what uses it' : 'Delete'}
            </Button>,
        ];
    };

    return (
        <Modal
            open={open}
            maskClosable={false}
            title={deleteModalTitle(entityLabel, recordName)}
            onCancel={onClose}
            footer={footer()}
            destroyOnHidden
        >
            {refusal === null ? (
                <>
                    <Typography.Paragraph>{deleteConfirmBody(entityLabel, recordName)}</Typography.Paragraph>
                    {knownBlocked && (
                        <Alert type="warning" showIcon message={CONFIGURATION_ACTION_REFUSED.delete} />
                    )}
                    {checkFailed && (
                        <Alert
                            type="warning"
                            showIcon
                            message="Could not check what uses this record — the delete itself will still be checked by the server."
                        />
                    )}
                </>
            ) : (
                <>
                    <Alert type="error" showIcon message={inUseHeadline(refusal, entityLabel)} />
                    {refusal.blocking.length > 0 && (
                        <List
                            size="small"
                            style={{ marginTop: 12 }}
                            dataSource={refusal.blocking}
                            renderItem={(item) => (
                                <List.Item>
                                    {/* The count is the point — "used by something" is not an
                                        answer a supervisor can act on. It is rendered ONCE, by
                                        the helper that owns the words: a second element showing
                                        `item.count` beside it would print "12 stock movements 12".
                                        A countless fail-closed verdict renders as its label alone. */}
                                    <Typography.Text>{blockingLine(item)}</Typography.Text>
                                </List.Item>
                            )}
                        />
                    )}
                </>
            )}

            {askingReason && (
                <div style={{ marginTop: 12 }}>
                    <Typography.Text strong>{REASON_LABEL}</Typography.Text>
                    <Input.TextArea
                        rows={3}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder={REASON_REQUIRED}
                    />
                </div>
            )}
        </Modal>
    );
}
