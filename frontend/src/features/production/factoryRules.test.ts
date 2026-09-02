import { describe, expect, it } from 'vitest';
import { checkRuleValue, ruleAppliedLabel, ruleDataType, ruleDisplayValue, ruleDraftChanged } from './factoryRules';

describe('checkRuleValue', () => {
    it('accepts only yes/no for a boolean rule', () => {
        expect(checkRuleValue('boolean', 'true')).toEqual({ ok: true, value: 'true' });
        expect(checkRuleValue('boolean', 'yes')).toEqual({ ok: false, reason: 'Choose Yes or No.' });
        expect(checkRuleValue('boolean', '')).toEqual({ ok: false, reason: 'Choose Yes or No.' });
    });

    it('refuses a fraction where a whole number is stored', () => {
        expect(checkRuleValue('integer', ' 12 ')).toEqual({ ok: true, value: '12' });
        expect(checkRuleValue('integer', '8.5')).toEqual({ ok: false, reason: 'Whole number only.' });
    });

    it('keeps a decimal as the text typed, not a float', () => {
        expect(checkRuleValue('decimal', '0.25')).toEqual({ ok: true, value: '0.25' });
        expect(checkRuleValue('decimal', '0,25')).toEqual({ ok: false, reason: 'Number only, e.g. 12 or 0.25.' });
        expect(checkRuleValue('decimal', '')).toEqual({ ok: false, reason: 'Number only, e.g. 12 or 0.25.' });
    });

    it('parses JSON before letting it through and lets an empty map be cleared', () => {
        expect(checkRuleValue('json', '{"Amber": 121}')).toEqual({ ok: true, value: '{"Amber": 121}' });
        expect(checkRuleValue('json', '{"Amber": 121,}')).toEqual({ ok: false, reason: 'Not valid JSON.' });
        expect(checkRuleValue('json', '')).toEqual({ ok: true, value: null });
    });

    it('stores an empty text as no value', () => {
        expect(checkRuleValue('string', '  ')).toEqual({ ok: true, value: null });
        expect(checkRuleValue('string', ' abc ')).toEqual({ ok: true, value: 'abc' });
    });
});

describe('ruleDisplayValue and ruleDataType', () => {
    it('reads a boolean as Yes/No and an empty value as a dash', () => {
        expect(ruleDisplayValue({ data_type: 'boolean', value: 'true' })).toBe('Yes');
        expect(ruleDisplayValue({ data_type: 'boolean', value: 'false' })).toBe('No');
        expect(ruleDisplayValue({ data_type: 'decimal', value: null })).toBe('—');
        expect(ruleDisplayValue({ data_type: 'decimal', value: '0.01' })).toBe('0.01');
    });

    it('treats an unknown data type as text', () => {
        expect(ruleDataType({ data_type: 'mystery' })).toBe('string');
    });
});

describe('ruleDraftChanged', () => {
    it('is false when the draft equals what is stored, whitespace aside', () => {
        expect(ruleDraftChanged({ data_type: 'decimal', value: '8' }, ' 8 ')).toBe(false);
        expect(ruleDraftChanged({ data_type: 'decimal', value: '8' }, '9')).toBe(true);
    });

    it('counts an invalid draft as a change so the reason shows, but Save stays off', () => {
        expect(ruleDraftChanged({ data_type: 'integer', value: '8' }, '8.5')).toBe(true);
    });
});

describe('ruleAppliedLabel', () => {
    it('names the two states plainly', () => {
        expect(ruleAppliedLabel(true)).toEqual({ text: 'In use', tone: 'success' });
        expect(ruleAppliedLabel(false)).toEqual({ text: 'Not in use', tone: 'default' });
    });
});
