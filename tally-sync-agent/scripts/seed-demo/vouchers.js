// Sample vouchers from the spec's §8, modelled as plain "Journal" vouchers
// (pure ledger dr/cr) rather than Purchase/Sales/Credit Note voucher types.
// Journal never requires inventory allocations in an inventory-integrated
// company; Purchase/Sales/Credit Note might (untested — this Tally company's
// voucher-type inventory requirements weren't worth guessing at after the
// BOM/stock-group lessons above). Money amounts are exact; where the spec's
// own shorthand didn't balance (see (c)), the debit side is corrected here
// and noted.
//
// (d) Production and (e) Regrind Recovery are deliberately NOT included —
// those need a real Stock Journal / Manufacturing Journal XML shape, which
// is the same unvalidated gap already flagged in ../../README.md's "what
// still needs a real Tally instance" section. Don't guess that blind too.
//
// IMPORTANT — Tally Educational Mode date restriction (confirmed live):
// this Tally instance only accepts voucher dates on the 1st, 2nd, or last
// day of a month. Every other day fails with a misleading "Voucher date is
// missing" error that has nothing to do with the DATE tag's actual content
// — it took a long isolation session to figure out this was a licensing
// restriction, not a data or timing bug. All dates below are remapped to
// 07-01 / 07-02 / 07-31 accordingly; a real (non-Educational) Tally license
// removes this constraint entirely.

const vouchers = [
    {
        label: '(a) Resin purchase — 20 MT @ Rs 92/Kg, local',
        date: '2026-07-01',
        number: 'JV-DEMO-001',
        narration: 'Resin purchase from Reliance Industries Ltd — 20,000 Kg @ Rs 92/Kg, Batch RIL-0.80-2607',
        lines: [
            { ledger: 'Purchase – PET Resin (Bottle Grade)', debit: '1840000', credit: '0' },
            { ledger: 'Input CGST', debit: '165600', credit: '0' },
            { ledger: 'Input SGST', debit: '165600', credit: '0' },
            { ledger: 'Reliance Industries Ltd', debit: '0', credit: '2171200', billRef: 'RIL-INV-001' },
        ],
    },
    {
        label: '(b) Freight inward loaded into resin cost',
        date: '2026-07-01',
        number: 'JV-DEMO-002',
        narration: 'Freight inward on resin purchase — simplified as a direct expense journal rather than modelled via "Track Additional Costs of Purchase" (that mechanic apportions into the stock item\'s rate, which needs its own live-validated XML shape)',
        lines: [
            { ledger: 'Freight Inward', debit: '28000', credit: '0' },
            { ledger: 'Transporter / Freight Payable', debit: '0', credit: '28000' },
        ],
    },
    {
        label: '(c) Imported resin purchase',
        date: '2026-07-02',
        number: 'JV-DEMO-003',
        narration:
            'Imported resin — assessable value Rs 15,00,000 + BCD Rs 1,50,000 + CHA/Freight Rs 75,000, all loaded into item cost (Dr corrected to Rs 17,25,000 to balance — the spec\'s shorthand only debited the assessable value)',
        lines: [
            { ledger: 'Purchase – Imported Resin', debit: '1725000', credit: '0' },
            { ledger: 'Overseas Supplier', debit: '0', credit: '1500000' },
            { ledger: 'Customs Duty Payable', debit: '0', credit: '150000' },
            { ledger: 'CHA / Clearing Agent', debit: '0', credit: '75000' },
        ],
    },
    {
        label: '(c2) IGST on import — credit from Bill of Entry',
        date: '2026-07-02',
        number: 'JV-DEMO-004',
        narration: 'IGST credit on imported resin per Bill of Entry',
        lines: [
            { ledger: 'IGST on Imports (Input)', debit: '292500', credit: '0' },
            { ledger: 'Customs Duty Payable', debit: '0', credit: '292500' },
        ],
    },
    {
        label: '(f) Sales invoice — 1,00,000 bottles of 1 L @ Rs 8.20',
        date: '2026-07-02',
        number: 'JV-DEMO-005',
        narration: 'Sale of 1,00,000 pcs PET Bottle 1 Litre to Aqua Bottlers Pvt Ltd @ Rs 8.20 + freight recovery',
        lines: [
            { ledger: 'Aqua Bottlers Pvt Ltd', debit: '988840', credit: '0', billRef: 'INV-4412' },
            { ledger: 'Sale of PET Bottles – Local (18%)', debit: '0', credit: '820000' },
            { ledger: 'Freight & Packing Charges Recovered', debit: '0', credit: '18000' },
            { ledger: 'Output CGST', debit: '0', credit: '75420' },
            { ledger: 'Output SGST', debit: '0', credit: '75420' },
        ],
    },
    {
        label: '(g) TDS u/s 194Q on resin purchases',
        date: '2026-07-01',
        number: 'JV-DEMO-006',
        narration: 'TDS u/s 194Q deducted on resin purchases from Reliance Industries Ltd, on excess above threshold',
        lines: [
            { ledger: 'Reliance Industries Ltd', debit: '1840', credit: '0' },
            { ledger: 'TDS Payable – 194Q', debit: '0', credit: '1840' },
        ],
    },
    {
        label: '(h) Electricity bill',
        date: '2026-07-02',
        number: 'JV-DEMO-007',
        narration: 'Electricity bill — apportion machine-wise via Machine cost centre once cost centres are enabled (GST-exempt, no input credit)',
        lines: [
            { ledger: 'Power & Fuel – Factory', debit: '720000', credit: '0' },
            { ledger: 'EB Payable', debit: '0', credit: '720000' },
        ],
    },
    {
        label: '(i) Scrap sale with TCS',
        date: '2026-07-02',
        number: 'JV-DEMO-008',
        narration: 'Sale of 2,000 Kg PET Lumps scrap to Plastic Recyclers & Co @ Rs 42/Kg, TCS u/s 206C(1) collected at invoicing',
        lines: [
            { ledger: 'Plastic Recyclers & Co', debit: '100111', credit: '0', billRef: 'SCRAP-INV-01' },
            { ledger: 'Sale of Scrap – PET Lumps & Runners', debit: '0', credit: '84000' },
            { ledger: 'Output CGST', debit: '0', credit: '7560' },
            { ledger: 'Output SGST', debit: '0', credit: '7560' },
            { ledger: 'TCS Payable – 206C(1) Scrap', debit: '0', credit: '991' },
        ],
    },
    {
        label: '(j) Mould set purchase',
        date: '2026-07-01',
        number: 'JV-DEMO-009',
        narration: '8-Cavity 1L mould set purchase from Mould Manufacturer',
        lines: [
            { ledger: 'Mould Set 8-Cavity 1 L', debit: '2200000', credit: '0' },
            { ledger: 'Input CGST', debit: '198000', credit: '0' },
            { ledger: 'Input SGST', debit: '198000', credit: '0' },
            { ledger: 'Mould Manufacturer', debit: '0', credit: '2596000' },
        ],
    },
    {
        label: '(j2) Mould amortisation (monthly, on shots done)',
        date: '2026-07-31',
        number: 'JV-DEMO-010',
        narration: 'Monthly mould amortisation — 3,00,000 shots done of 40,00,000 shot life',
        lines: [
            { ledger: 'Mould Amortisation', debit: '165000', credit: '0' },
            { ledger: 'Mould Set 8-Cavity 1 L', debit: '0', credit: '165000' },
        ],
    },
    {
        label: '(k) Customer rejection credit note',
        date: '2026-07-31',
        number: 'CN-DEMO-001',
        voucherTypeName: 'Credit Note',
        vchType: 'Credit Note',
        narration: '5,000 pcs rejected — neck ovality / underweight, Batch BT-260722-01, against Aqua Bottlers Pvt Ltd invoice INV-4412',
        lines: [
            { ledger: 'Sales Return', debit: '41000', credit: '0' },
            { ledger: 'Output CGST', debit: '3690', credit: '0' },
            { ledger: 'Output SGST', debit: '3690', credit: '0' },
            { ledger: 'Aqua Bottlers Pvt Ltd', debit: '0', credit: '48380' },
        ],
    },
    {
        label: '(l) Customer receipt with TDS deducted by them',
        date: '2026-07-31',
        number: 'RCT-DEMO-001',
        voucherTypeName: 'Receipt',
        vchType: 'Receipt',
        narration: 'Receipt from Aqua Bottlers against INV-4412, net of 194Q TDS deducted by them',
        lines: [
            { ledger: 'SBI Current A/c – 1234', debit: '987852', credit: '0' },
            { ledger: 'TDS Receivable', debit: '988', credit: '0' },
            { ledger: 'Aqua Bottlers Pvt Ltd', debit: '0', credit: '988840' },
        ],
    },
    {
        label: '(m) Month-end depreciation',
        date: '2026-07-31',
        number: 'JV-DEMO-011',
        narration: 'Month-end depreciation across ISBM machines, compressor, dryer, chiller, building',
        lines: [
            { ledger: 'Depreciation', debit: '380000', credit: '0' },
            { ledger: 'ISBM Machine-01', debit: '0', credit: '120000' },
            { ledger: 'ISBM Machine-02', debit: '0', credit: '120000' },
            { ledger: 'Air Compressor HP', debit: '0', credit: '55000' },
            { ledger: 'Resin Dryer & Hopper', debit: '0', credit: '35000' },
            { ledger: 'Chiller & Cooling Tower', debit: '0', credit: '30000' },
            { ledger: 'Factory Building & Shed', debit: '0', credit: '20000' },
        ],
    },
];

module.exports = { vouchers };
