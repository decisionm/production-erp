import type { Vendor } from './types';

export type VendorClassification = 'resin' | 'packaging' | 'consumables_spares_tooling' | 'service' | 'other';

export const VENDOR_CLASSIFICATIONS: { value: VendorClassification; label: string }[] = [
    { value: 'resin', label: 'Resin' },
    { value: 'packaging', label: 'Packaging' },
    { value: 'consumables_spares_tooling', label: 'Consumables, Spares and Tooling' },
    { value: 'service', label: 'Service' },
    { value: 'other', label: 'Other' },
];

/** DEC-20260902-026: the three shown by default; Service, Other and Unclassified sit behind an explicit filter. */
export const DEFAULT_VENDOR_VIEW: VendorClassification[] = ['resin', 'packaging', 'consumables_spares_tooling'];

export function classificationLabel(value: VendorClassification): string {
    return VENDOR_CLASSIFICATIONS.find((c) => c.value === value)?.label ?? value;
}

/** Classification controls the default view only; it never blocks selecting a vendor (showAll offers every active vendor). */
export function vendorPickerOptions(vendors: readonly Vendor[] | undefined | null, showAll: boolean): { value: number; label: string }[] {
    const out: { value: number; label: string }[] = [];
    for (const v of vendors ?? []) {
        if (!v.is_active) continue;
        const inDefault = (v.classifications ?? []).some((c) => DEFAULT_VENDOR_VIEW.includes(c));
        if (!inDefault && !showAll) continue;
        const suffix = (v.classifications ?? []).length === 0 ? ' · Unclassified' : '';
        out.push({ value: v.id, label: `${v.code} — ${v.name}${suffix}` });
    }
    return out;
}
