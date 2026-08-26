import type { ItemTrackingType } from './types';

/**
 * THE SAME RULE THE SERVER NOW ENFORCES, SAID BEFORE THE REQUEST GOES.
 *
 * `items.tracking_type` used to be a label: the modals showed a Batch picker
 * for a batch-tracked item and the server accepted the form with the picker
 * left empty, so a lot the factory is meant to be able to trace was recorded
 * with no batch at all. The server refuses that now
 * (ValidatesTrackingIdentity), and a refusal that only arrives as a 422 is a
 * store user staring at a red box for a field the form told them was optional.
 *
 * Pure, and here rather than in the page, so the rule is testable and there is
 * exactly one copy of it on this side of the wire.
 */
export type IdentityField = 'batch_id' | 'serial_number_id';

export interface IdentityValues {
    batch_id?: number;
    serial_number_id?: number;
}

export interface IdentityRefusal {
    field: IdentityField;
    message: string;
}

export function identityRefusal(
    tracking: ItemTrackingType,
    values: IdentityValues,
): IdentityRefusal | null {
    const batch = values.batch_id ?? undefined;
    const serial = values.serial_number_id ?? undefined;

    if (tracking === 'batch') {
        if (serial !== undefined) {
            return { field: 'serial_number_id', message: 'Not used for a batch-tracked item.' };
        }
        return batch === undefined ? { field: 'batch_id', message: 'Batch is required.' } : null;
    }

    if (tracking === 'serial') {
        if (batch !== undefined) {
            return { field: 'batch_id', message: 'Not used for a serial-tracked item.' };
        }
        return serial === undefined
            ? { field: 'serial_number_id', message: 'Serial number is required.' }
            : null;
    }

    // Untracked: quantity only, exactly as it has always been.
    if (batch !== undefined) {
        return { field: 'batch_id', message: 'Not used for this item.' };
    }
    if (serial !== undefined) {
        return { field: 'serial_number_id', message: 'Not used for this item.' };
    }

    return null;
}
