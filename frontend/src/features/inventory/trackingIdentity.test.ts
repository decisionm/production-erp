import { describe, expect, it } from 'vitest';
import { identityRefusal } from './trackingIdentity';

describe('identityRefusal', () => {
    it('requires a batch on a batch-tracked item', () => {
        expect(identityRefusal('batch', {})).toEqual({ field: 'batch_id', message: 'Batch is required.' });
        expect(identityRefusal('batch', { batch_id: 4 })).toBeNull();
    });

    it('requires a serial number on a serial-tracked item', () => {
        expect(identityRefusal('serial', {})?.field).toBe('serial_number_id');
        expect(identityRefusal('serial', { serial_number_id: 9 })).toBeNull();
    });

    it('refuses the other kind of identity on a tracked item', () => {
        expect(identityRefusal('batch', { batch_id: 4, serial_number_id: 9 })?.field).toBe('serial_number_id');
        expect(identityRefusal('serial', { serial_number_id: 9, batch_id: 4 })?.field).toBe('batch_id');
    });

    it('leaves an untracked item exactly as it was — quantity only', () => {
        expect(identityRefusal('none', {})).toBeNull();
        expect(identityRefusal('none', { batch_id: 4 })?.field).toBe('batch_id');
        expect(identityRefusal('none', { serial_number_id: 9 })?.field).toBe('serial_number_id');
    });

    it('treats a cleared picker as absent, not as a value', () => {
        // antd's allowClear hands back undefined; a form reset can leave the
        // key present. Both have to read as "nothing chosen" or a batch-tracked
        // item would submit with an empty batch and take the server's 422.
        expect(identityRefusal('batch', { batch_id: undefined })?.field).toBe('batch_id');
        expect(identityRefusal('none', { batch_id: undefined })).toBeNull();
    });
});
