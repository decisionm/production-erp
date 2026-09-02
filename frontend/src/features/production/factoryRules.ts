/**
 * The pure half of the Factory Rules tab — what a typed value must look
 * like before it may be saved, and how a stored value reads on screen.
 *
 * The server stores every setting as text and casts on read
 * (FactorySetting::typedValue), so the client's job is to refuse a draft the
 * cast would silently mangle: "8.5" for an integer, "yes" for a boolean, a
 * JSON map with a trailing comma. Refused here, with a reason under the
 * field, rather than saved and mis-read by the floor.
 *
 * No React, no axios — pinned by factoryRules.test.ts.
 */
import type { FactorySetting } from './types';

export type RuleDataType = 'string' | 'integer' | 'decimal' | 'boolean' | 'json';

export type RuleValueCheck = { ok: true; value: string | null } | { ok: false; reason: string };

const DATA_TYPES: readonly RuleDataType[] = ['string', 'integer', 'decimal', 'boolean', 'json'];

/** The server's `data_type`, or `string` for anything it did not name. */
export function ruleDataType(setting: Pick<FactorySetting, 'data_type'>): RuleDataType {
    return (DATA_TYPES as readonly string[]).includes(setting.data_type) ? (setting.data_type as RuleDataType) : 'string';
}

/**
 * A draft → the exact text to store, or the reason it may not be stored.
 * An empty string is "no value" for text and JSON (the server keeps null);
 * a number or a yes/no cannot be empty.
 */
export function checkRuleValue(dataType: RuleDataType, draft: string): RuleValueCheck {
    const text = draft.trim();

    switch (dataType) {
        case 'boolean':
            if (text === 'true' || text === 'false') return { ok: true, value: text };

            return { ok: false, reason: 'Choose Yes or No.' };
        case 'integer':
            if (/^-?\d+$/.test(text)) return { ok: true, value: text };

            return { ok: false, reason: 'Whole number only.' };
        case 'decimal':
            if (/^-?\d+(\.\d+)?$/.test(text)) return { ok: true, value: text };

            return { ok: false, reason: 'Number only, e.g. 12 or 0.25.' };
        case 'json': {
            if (text === '') return { ok: true, value: null };
            try {
                JSON.parse(text);

                return { ok: true, value: text };
            } catch {
                return { ok: false, reason: 'Not valid JSON.' };
            }
        }
        default:
            return { ok: true, value: text === '' ? null : text };
    }
}

/** What the stored text reads as when nothing is being edited. */
export function ruleDisplayValue(setting: Pick<FactorySetting, 'data_type' | 'value'>): string {
    const value = setting.value ?? '';
    if (ruleDataType(setting) === 'boolean') {
        if (value === 'true') return 'Yes';
        if (value === 'false') return 'No';
    }

    return value === '' ? '—' : value;
}

/** A draft equal to what is stored is not a change and must not enable Save. */
export function ruleDraftChanged(setting: Pick<FactorySetting, 'data_type' | 'value'>, draft: string): boolean {
    const check = checkRuleValue(ruleDataType(setting), draft);
    if (!check.ok) return draft.trim() !== (setting.value ?? '');

    return check.value !== (setting.value ?? null);
}

/** The words the row wears for whether any code reads it. */
export function ruleAppliedLabel(applied: boolean): { text: string; tone: 'success' | 'default' } {
    return applied ? { text: 'In use', tone: 'success' } : { text: 'Not in use', tone: 'default' };
}
