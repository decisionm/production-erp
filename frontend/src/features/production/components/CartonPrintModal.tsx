import { useMutation } from '@tanstack/react-query';
import { Alert, Button, Modal, Typography } from 'antd';
import { useEffect } from 'react';
import { generateCartons } from '@/features/production/api';
import FinishedCartonLabels from '@/features/production/components/FinishedCartonLabels';

interface CartonPrintModalProps {
    /** The completed batch to label, or null when the modal is closed. */
    entry: { id: number; batch_number: string | null } | null;
    onClose: () => void;
}

/**
 * THE SHIFT FLOOR'S CARTON LABEL PRINT (DEC-20260807-008): opens right when a
 * batch is completed — and from a button on any completed row — so the person
 * packing the boxes prints and sticks the labels there and then, instead of
 * waiting for the Approval Desk (whose screen remains as the reprint).
 *
 * Opening the modal mints the codes without a second tap: generation on the
 * server is completion-gated and idempotent, so reopening — today, tomorrow,
 * after a correction — returns the same permanent identities and never a
 * second label for the same physical box.
 */
export default function CartonPrintModal({ entry, onClose }: CartonPrintModalProps) {
    const { mutate, reset, data, error, isPending } = useMutation({
        mutationFn: (entryId: number) => generateCartons(entryId),
    });

    const entryId = entry?.id;
    useEffect(() => {
        if (entryId !== undefined) {
            mutate(entryId);
        } else {
            reset();
        }
    }, [entryId, mutate, reset]);

    const detail = (error as { response?: { data?: { message?: string } } } | null)?.response?.data?.message;

    return (
        <Modal
            title={`Carton labels — ${entry?.batch_number ?? (entry ? `Batch #${entry.id}` : '')}`}
            open={entry !== null}
            onCancel={onClose}
            footer={null}
            width={760}
        >
            {isPending && <Typography.Text type="secondary">Preparing the carton labels…</Typography.Text>}
            {!isPending && error != null && (
                <Alert
                    type="error"
                    showIcon
                    message="Could not produce carton labels"
                    description={detail ?? 'Unknown error'}
                    action={
                        entryId !== undefined && (
                            <Button size="small" onClick={() => mutate(entryId)}>
                                Try again
                            </Button>
                        )
                    }
                />
            )}
            {!isPending && error == null && data !== undefined && <FinishedCartonLabels cartons={data} />}
        </Modal>
    );
}
