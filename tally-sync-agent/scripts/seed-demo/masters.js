// Master data for the "XYZ Polymers Pvt Ltd" single-stage ISBM PET bottle
// demo, transcribed from the spec. Rates/quantities are the spec's own
// indicative figures — replace with real process data before relying on
// this for anything beyond exercising the sync agent.

const ledgers = [
    // Capital Account
    { name: "Share Capital / Partners' Capital", parent: 'Capital Account' },
    { name: 'Reserves & Surplus', parent: 'Reserves & Surplus' },
    { name: 'Drawings', parent: 'Capital Account' },

    // Loans (Liability)
    { name: 'Term Loan – ISBM Machine-01', parent: 'Secured Loans' },
    { name: 'Term Loan – ISBM Machine-02', parent: 'Secured Loans' },
    { name: 'Cash Credit A/c – SBI', parent: 'Secured Loans' },
    { name: 'Bank OD A/c', parent: 'Secured Loans' },
    { name: "Buyer's Credit / LC Acceptance", parent: 'Secured Loans' },
    { name: 'Unsecured Loan from Director', parent: 'Unsecured Loans' },

    // Current Liabilities — suppliers
    { name: 'Reliance Industries Ltd', parent: 'Sundry Creditors' },
    { name: 'IVL Dhunseri', parent: 'Sundry Creditors' },
    { name: 'JBF Industries', parent: 'Sundry Creditors' },
    { name: 'M/s Cap & Closure Suppliers', parent: 'Sundry Creditors' },
    { name: 'M/s Label Printers', parent: 'Sundry Creditors' },
    { name: 'M/s Carton & Packing Suppliers', parent: 'Sundry Creditors' },
    { name: 'Overseas Supplier', parent: 'Sundry Creditors' },
    { name: 'CHA / Clearing Agent', parent: 'Sundry Creditors' },
    { name: 'Transporter / Freight Payable', parent: 'Sundry Creditors' },
    { name: 'Mould Manufacturer', parent: 'Sundry Creditors' },

    // Duties & Taxes
    { name: 'Output CGST', parent: 'Duties & Taxes' },
    { name: 'Output SGST', parent: 'Duties & Taxes' },
    { name: 'Output IGST', parent: 'Duties & Taxes' },
    { name: 'Input CGST', parent: 'Duties & Taxes' },
    { name: 'Input SGST', parent: 'Duties & Taxes' },
    { name: 'Input IGST', parent: 'Duties & Taxes' },
    { name: 'IGST on Imports (Customs)', parent: 'Duties & Taxes' },
    { name: 'IGST on Imports (Input)', parent: 'Duties & Taxes' },
    { name: 'GST RCM Payable – CGST', parent: 'Duties & Taxes' },
    { name: 'GST RCM Payable – SGST', parent: 'Duties & Taxes' },
    { name: 'TDS Payable – 194Q', parent: 'Duties & Taxes' },
    { name: 'TDS Payable – 194C', parent: 'Duties & Taxes' },
    { name: 'TDS Payable – 194I(a)', parent: 'Duties & Taxes' },
    { name: 'TDS Payable – 194I(b)', parent: 'Duties & Taxes' },
    { name: 'TDS Payable – 194J', parent: 'Duties & Taxes' },
    { name: 'TDS Payable – 192', parent: 'Duties & Taxes' },
    { name: 'TCS Payable – 206C(1) Scrap', parent: 'Duties & Taxes' },
    { name: 'TCS Payable – 206C(1H)', parent: 'Duties & Taxes' },

    // Current Liabilities — payroll/statutory/other payables
    { name: 'PF Payable', parent: 'Current Liabilities' },
    { name: 'ESI Payable', parent: 'Current Liabilities' },
    { name: 'PT Payable', parent: 'Current Liabilities' },
    { name: 'EB Payable', parent: 'Current Liabilities' },
    { name: 'Customs Duty Payable', parent: 'Current Liabilities' },
    { name: 'Advance Received from Customers', parent: 'Current Liabilities' },
    { name: 'Mould Advance from Customers', parent: 'Current Liabilities' },

    // Provisions
    { name: 'Wages Payable', parent: 'Provisions' },
    { name: 'Power Bill Payable', parent: 'Provisions' },
    { name: 'Audit Fee Payable', parent: 'Provisions' },

    // Fixed Assets
    { name: 'ISBM Machine-01', parent: 'Fixed Assets' },
    { name: 'ISBM Machine-02', parent: 'Fixed Assets' },
    { name: 'ISBM Machine-03', parent: 'Fixed Assets' },
    { name: 'Mould Set – 200 ml', parent: 'Fixed Assets' },
    { name: 'Mould Set – 500 ml', parent: 'Fixed Assets' },
    { name: 'Mould Set 8-Cavity 1 L', parent: 'Fixed Assets' },
    { name: 'Mould Set – 2 Litre', parent: 'Fixed Assets' },
    { name: 'Mould Set – 500 g Jar', parent: 'Fixed Assets' },
    { name: 'Hot Runner System & Zone Controllers', parent: 'Fixed Assets' },
    { name: 'Resin Dryer & Hopper', parent: 'Fixed Assets' },
    { name: 'Hopper Loader / Vacuum Conveying System', parent: 'Fixed Assets' },
    { name: 'Air Compressor HP', parent: 'Fixed Assets' },
    { name: 'Air Compressor LP', parent: 'Fixed Assets' },
    { name: 'Air Receiver, Dryer & Filters', parent: 'Fixed Assets' },
    { name: 'Chiller & Cooling Tower', parent: 'Fixed Assets' },
    { name: 'Mould Temperature Controller (MTC)', parent: 'Fixed Assets' },
    { name: 'Scrap Grinder / Granulator', parent: 'Fixed Assets' },
    { name: 'DG Set', parent: 'Fixed Assets' },
    { name: 'Transformer & Electrical Installation', parent: 'Fixed Assets' },
    { name: 'Factory Building & Shed', parent: 'Fixed Assets' },
    { name: 'Lab Equipment', parent: 'Fixed Assets' },
    { name: 'Forklift / Hand Pallet Truck', parent: 'Fixed Assets' },
    { name: 'Accumulated Depreciation', parent: 'Fixed Assets' },

    // Current Assets
    { name: 'Aqua Bottlers Pvt Ltd', parent: 'Sundry Debtors' },
    { name: 'Plastic Recyclers & Co', parent: 'Sundry Debtors' },
    { name: 'Cash-in-Hand', parent: 'Cash-in-Hand' },
    { name: 'SBI Current A/c – 1234', parent: 'Bank Accounts' },
    { name: 'Advance to Suppliers', parent: 'Loans & Advances (Asset)' },
    { name: 'Staff Advance / Imprest', parent: 'Loans & Advances (Asset)' },
    { name: 'TDS Receivable', parent: 'Loans & Advances (Asset)' },
    { name: 'GST Electronic Cash Ledger', parent: 'Loans & Advances (Asset)' },
    { name: 'Prepaid Insurance / AMC', parent: 'Loans & Advances (Asset)' },
    { name: 'Advance Customs Duty / Import Clearing', parent: 'Loans & Advances (Asset)' },
    { name: 'Electricity Deposit (EB)', parent: 'Deposits (Asset)' },
    { name: 'Security Deposit – Factory Rent', parent: 'Deposits (Asset)' },

    // Sales Accounts
    { name: 'Sale of PET Bottles – Local (18%)', parent: 'Sales Accounts' },
    { name: 'Sale of PET Bottles – Interstate (18%)', parent: 'Sales Accounts' },
    { name: 'Sale of Jars & Wide-Mouth Containers', parent: 'Sales Accounts' },
    { name: 'Sale of Caps & Closures', parent: 'Sales Accounts' },
    { name: 'Mould Development Charges Recovered', parent: 'Sales Accounts' },
    { name: 'Sale of Scrap – PET Lumps & Runners', parent: 'Sales Accounts' },
    { name: 'Sale of Scrap – Others', parent: 'Sales Accounts' },
    { name: 'Freight & Packing Charges Recovered', parent: 'Sales Accounts' },
    { name: 'Sales Return', parent: 'Sales Accounts' },

    // Purchase Accounts
    { name: 'Purchase – PET Resin (Bottle Grade)', parent: 'Purchase Accounts' },
    { name: 'Purchase – Imported Resin', parent: 'Purchase Accounts' },
    { name: 'Purchase – Master Batch / Colourant', parent: 'Purchase Accounts' },
    { name: 'Purchase – Caps & Closures', parent: 'Purchase Accounts' },
    { name: 'Purchase – Labels & Shrink Sleeves', parent: 'Purchase Accounts' },
    { name: 'Purchase – Cartons & Corrugated Boxes', parent: 'Purchase Accounts' },
    { name: 'Purchase – LDPE Bags / Stretch Film / Strapping', parent: 'Purchase Accounts' },
    { name: 'Purchase Return', parent: 'Purchase Accounts' },

    // Direct Expenses
    ...[
        'Power & Fuel – Factory',
        'Diesel – DG Set',
        'Machine Operator Wages',
        'Contract Labour Charges',
        'Overtime & Incentive – Production',
        'Mould Set Maintenance & Polishing',
        'Hot Runner / Nozzle Tip Repairs',
        'Repairs & Maintenance – ISBM Machines',
        'Repairs & Maintenance – Compressor & Chiller',
        'Repairs & Maintenance – Dryer & Desiccant Replacement',
        'Compressor AMC',
        'Spares & Consumables',
        'Mould Release & Silicone',
        'Water Charges & Chilling',
        'Freight Inward',
        'Loading & Unloading',
        'Factory Rent',
        'Factory Insurance',
        'Lab & Testing Charges',
        'FSSAI Licence & Renewal',
        'EPR / Plastic Waste Compliance Charges',
        'Pollution Board Consent Fees',
        'Housekeeping Consumables',
    ].map((name) => ({ name, parent: 'Direct Expenses' })),

    // Indirect Expenses
    ...[
        'Salaries – Admin',
        "Director's Remuneration",
        'Office Rent',
        'Telephone & Internet',
        'Printing & Stationery',
        'Travelling & Conveyance',
        'Sales Commission',
        'Freight Outward',
        'Audit Fees',
        'Professional & Legal',
        'Bank Charges & LC Charges',
        'Interest on Term Loan',
        'Interest on CC',
        'Depreciation',
        'Mould Amortisation',
        'Business Promotion',
        'Rates & Taxes',
        'GST Interest & Late Fee',
        'Round Off',
    ].map((name) => ({ name, parent: 'Indirect Expenses' })),

    // Indirect Incomes
    ...['Interest Income', 'Discount Received', 'Duty Drawback / RoDTEP', 'Insurance Claim Received', 'Profit on Sale of Asset'].map(
        (name) => ({ name, parent: 'Indirect Incomes' }),
    ),
];

const stockGroups = [
    // NOTE on the "... Group" suffixes below: the plain spec names ("Raw
    // Materials", "Packing Material", etc.) got stuck in a corrupted/pending
    // state in this Tally company from an earlier failed import attempt (a
    // bad ISADDABLE tag) — they don't show up in "List of Stock Groups" but
    // Tally still refuses to (re)create them at root level, always erroring
    // "Stock Group 'Primary' does not exist!" regardless of restart. These
    // renamed variants are confirmed clean. Sub-group names below are kept
    // exactly as the spec (their corrupted stubs heal automatically once
    // given a valid, already-existing parent — no rename needed for them).
    { name: 'Raw Materials Group', parent: 'Primary' },
    { name: 'PET Resin', parent: 'Raw Materials Group' },
    { name: 'Master Batch & Additives', parent: 'Raw Materials Group' },
    { name: 'Regrind (In-house)', parent: 'Raw Materials Group' },
    { name: 'Bought-Out Components Group', parent: 'Primary' },
    { name: 'Caps & Closures', parent: 'Bought-Out Components Group' },
    { name: 'Handles', parent: 'Bought-Out Components Group' },
    { name: 'Packing Material Group', parent: 'Primary' },
    { name: 'Labels & Sleeves', parent: 'Packing Material Group' },
    { name: 'Cartons', parent: 'Packing Material Group' },
    { name: 'LDPE / Film / Strapping', parent: 'Packing Material Group' },
    { name: 'Finished Goods - Bottles', parent: 'Primary' },
    { name: 'Scrap & Waste Group', parent: 'Primary' },
    { name: 'Spares & Consumables Group', parent: 'Primary' },
];

const simpleUnits = [
    { symbol: 'Kg', formalName: 'Kilograms' },
    { symbol: 'Nos', formalName: 'Numbers' },
    { symbol: 'Pcs', formalName: 'Pieces' },
    { symbol: 'Ltr', formalName: 'Litres' },
    { symbol: 'Roll', formalName: 'Rolls' },
    { symbol: 'Set', formalName: 'Sets' },
];

const compoundUnits = [
    { symbol: 'MT', formalName: 'Metric Tons', baseUnit: 'Kg', conversion: 1000 },
    { symbol: 'Box', formalName: 'Boxes', baseUnit: 'Nos', conversion: 100 },
    { symbol: 'Bag', formalName: 'Bags', baseUnit: 'Nos', conversion: 1000 },
];

const godowns = ['RM Store', 'Packing Material Store', 'FG Store', 'Dispatch Bay', 'Scrap Yard'].map((name) => ({ name }));

const costCategories = [
    { name: 'Machine', centres: ['ISBM-01', 'ISBM-02', 'ISBM-03', 'Compressor & Utilities'] },
    { name: 'Product / SKU', centres: ['200 ml', '500 ml', '1 L', '2 L', '500 g Jar'] },
    { name: 'Shift', centres: ['Shift A', 'Shift B', 'Shift C'] },
    { name: 'Customer / Segment', centres: ['Water Bottlers', 'Beverage', 'Pharma', 'Cosmetics', 'Edible Oil'] },
];

const voucherTypes = [
    { name: 'Purchase – Local', parent: 'Purchase' },
    { name: 'Purchase – Interstate', parent: 'Purchase' },
    { name: 'Purchase – Import', parent: 'Purchase' },
    { name: 'Sales – Bottles Local', parent: 'Sales' },
    { name: 'Sales – Bottles Interstate', parent: 'Sales' },
    { name: 'Bottle Production', parent: 'Stock Journal' },
    { name: 'Regrind Recovery', parent: 'Stock Journal' },
];

// Per-1000-bottle BOM figures from the spec's §6 table. `components` feeds
// stockItemXml's BOM block; the by-product (sprues/runners) is handled at
// production-voucher time, not in the item's static BOM.
const bottleSkus = [
    {
        name: 'PET Bottle 200 ml',
        altSymbol: 'Kg',
        altConversion: 0.0095, // Kg per Nos (9.5 g/1000)
        netWeightG: 9.5,
        hsn: '3923 30 10',
        gstRate: 18,
        components: [
            { item: 'PET Resin Bottle Grade IV 0.80', quantity: 9.79, unit: 'Kg' },
            { item: 'Master Batch – Blue', quantity: 0.02, unit: 'Kg' },
            { item: 'Cap 28 mm PCO', quantity: 1005, unit: 'Nos' },
            { item: 'Shrink Sleeve / BOPP Label', quantity: 1005, unit: 'Nos' },
            { item: 'LDPE Bag / Stretch Film', quantity: 0.3, unit: 'Kg' },
            { item: 'Corrugated Carton', quantity: 4, unit: 'Nos' },
        ],
    },
    {
        name: 'PET Bottle 500 ml',
        altSymbol: 'Kg',
        altConversion: 0.0145,
        netWeightG: 14.5,
        hsn: '3923 30 10',
        gstRate: 18,
        components: [
            { item: 'PET Resin Bottle Grade IV 0.80', quantity: 14.94, unit: 'Kg' },
            { item: 'Master Batch – Blue', quantity: 0.03, unit: 'Kg' },
            { item: 'Cap 28 mm PCO', quantity: 1005, unit: 'Nos' },
            { item: 'Shrink Sleeve / BOPP Label', quantity: 1005, unit: 'Nos' },
            { item: 'LDPE Bag / Stretch Film', quantity: 0.45, unit: 'Kg' },
            { item: 'Corrugated Carton', quantity: 4, unit: 'Nos' },
        ],
    },
    {
        name: 'PET Bottle 1 Litre',
        altSymbol: 'Kg',
        altConversion: 0.025,
        netWeightG: 25.0,
        hsn: '3923 30 10',
        gstRate: 18,
        components: [
            { item: 'PET Resin Bottle Grade IV 0.80', quantity: 25.75, unit: 'Kg' },
            { item: 'Master Batch – Blue', quantity: 0.05, unit: 'Kg' },
            { item: 'Cap 28 mm PCO', quantity: 1005, unit: 'Nos' },
            { item: 'Shrink Sleeve / BOPP Label', quantity: 1005, unit: 'Nos' },
            { item: 'LDPE Bag / Stretch Film', quantity: 0.7, unit: 'Kg' },
            { item: 'Corrugated Carton', quantity: 8, unit: 'Nos' },
        ],
    },
    {
        name: 'PET Bottle 2 Litre',
        altSymbol: 'Kg',
        altConversion: 0.042,
        netWeightG: 42.0,
        hsn: '3923 30 10',
        gstRate: 18,
        components: [
            { item: 'PET Resin Bottle Grade IV 0.80', quantity: 43.26, unit: 'Kg' },
            { item: 'Master Batch – Blue', quantity: 0.08, unit: 'Kg' },
            { item: 'Cap 28 mm PCO', quantity: 1005, unit: 'Nos' },
            { item: 'Shrink Sleeve / BOPP Label', quantity: 1005, unit: 'Nos' },
            { item: 'LDPE Bag / Stretch Film', quantity: 1.1, unit: 'Kg' },
            { item: 'Corrugated Carton', quantity: 12, unit: 'Nos' },
        ],
    },
    {
        name: 'PET Jar 500 g Wide-Mouth',
        altSymbol: 'Kg',
        altConversion: 0.032,
        netWeightG: 32.0,
        hsn: '3923 30 10',
        gstRate: 18,
        components: [
            { item: 'PET Resin Bottle Grade IV 0.80', quantity: 32.96, unit: 'Kg' },
            { item: 'Master Batch – Blue', quantity: 0.06, unit: 'Kg' },
            { item: 'Cap 28 mm PCO', quantity: 1005, unit: 'Nos' },
            { item: 'Shrink Sleeve / BOPP Label', quantity: 1005, unit: 'Nos' },
            { item: 'LDPE Bag / Stretch Film', quantity: 0.8, unit: 'Kg' },
            { item: 'Corrugated Carton', quantity: 6, unit: 'Nos' },
        ],
    },
];

// Non-bottle stock items: { name, group, unit, hsn, gstRate }
const otherStockItems = [
    { name: 'PET Resin Bottle Grade IV 0.80', group: 'PET Resin', unit: 'Kg', hsn: '3907 61', gstRate: 18 },
    { name: 'PET Resin Bottle Grade IV 0.84', group: 'PET Resin', unit: 'Kg', hsn: '3907 61', gstRate: 18 },
    { name: 'PET Regrind (In-house)', group: 'Regrind (In-house)', unit: 'Kg', hsn: '3915 90', gstRate: 18 },
    { name: 'Master Batch – Blue', group: 'Master Batch & Additives', unit: 'Kg', hsn: '3206', gstRate: 18 },
    { name: 'Master Batch – Green', group: 'Master Batch & Additives', unit: 'Kg', hsn: '3206', gstRate: 18 },
    { name: 'Master Batch – White', group: 'Master Batch & Additives', unit: 'Kg', hsn: '3206', gstRate: 18 },
    { name: 'Master Batch – Amber', group: 'Master Batch & Additives', unit: 'Kg', hsn: '3206', gstRate: 18 },
    { name: 'Cap 28 mm PCO', group: 'Caps & Closures', unit: 'Nos', hsn: '3923 50', gstRate: 18 },
    { name: 'Handle – 5 L Jar', group: 'Handles', unit: 'Nos', hsn: '3923 90', gstRate: 18 },
    { name: 'Shrink Sleeve / BOPP Label', group: 'Labels & Sleeves', unit: 'Nos', hsn: '3920', gstRate: 18 },
    { name: 'Corrugated Carton', group: 'Cartons', unit: 'Nos', hsn: '4819', gstRate: 18 },
    { name: 'LDPE Bag / Stretch Film', group: 'LDPE / Film / Strapping', unit: 'Kg', hsn: '3923', gstRate: 18 },
    { name: 'PET Sprues, Runners & Lumps', group: 'Scrap & Waste Group', unit: 'Kg', hsn: '3915 90', gstRate: 18 },
    { name: 'Rejected Bottles', group: 'Scrap & Waste Group', unit: 'Kg', hsn: '3915 90', gstRate: 18 },
];

module.exports = {
    ledgers,
    stockGroups,
    simpleUnits,
    compoundUnits,
    godowns,
    costCategories,
    voucherTypes,
    bottleSkus,
    otherStockItems,
};
