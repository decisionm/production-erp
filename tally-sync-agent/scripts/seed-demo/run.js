const { postImport, COMPANY_NAME, TALLY_URL } = require('./xml');
const {
    ledgerXml,
    stockGroupXml,
    journalVoucherXml,
    simpleUnitXml,
    compoundUnitXml,
    godownXml,
    costCategoryXml,
    costCentreXml,
    voucherTypeXml,
    stockItemXml,
} = require('./builders');
const {
    ledgers,
    stockGroups,
    simpleUnits,
    compoundUnits,
    godowns,
    costCategories,
    voucherTypes,
    bottleSkus,
    otherStockItems,
} = require('./masters');
const { vouchers } = require('./vouchers');

async function seedUnits() {
    await postImport('Simple units', simpleUnits.map(simpleUnitXml).join('\n'));
    // Tally rejects a compound unit whose ADDITIONALUNITS self-references the
    // unit being created ("Unit 'Bag' does not exist!") — the second unit
    // must already exist as its own master first, which defeats the point
    // for a single-shot seed. Falling back to plain simple units for
    // MT/Box/Bag: no auto Kg<->MT conversion, but unblocks everything
    // downstream. Revisit if the sync logic actually needs that conversion.
    await postImport(
        'Compound units (as simple — see comment)',
        compoundUnits.map((u) => simpleUnitXml({ symbol: u.symbol, formalName: u.formalName })).join('\n'),
    );
}

async function seedGodowns() {
    await postImport('Godowns', godowns.map(godownXml).join('\n'));
}

async function seedCostCentres() {
    await postImport(
        'Cost categories',
        costCategories.map((c) => costCategoryXml({ name: c.name })).join('\n'),
    );
    const centreXml = costCategories
        .flatMap((c) => c.centres.map((name) => costCentreXml({ name, category: c.name })))
        .join('\n');
    await postImport('Cost centres', centreXml);
}

async function seedLedgers() {
    // Batched in chunks so one bad ledger's LINEERROR doesn't obscure the
    // pass/fail signal for the other ~100.
    const chunkSize = 20;
    for (let i = 0; i < ledgers.length; i += chunkSize) {
        const chunk = ledgers.slice(i, i + chunkSize);
        await postImport(`Ledgers ${i + 1}-${i + chunk.length}`, chunk.map(ledgerXml).join('\n'));
    }
}

async function seedStockGroups() {
    await postImport('Stock groups', stockGroups.map(stockGroupXml).join('\n'));
}

async function seedVoucherTypes() {
    await postImport('Voucher types', voucherTypes.map(voucherTypeXml).join('\n'));
}

async function seedStockItems() {
    const otherXml = otherStockItems
        .map((i) => stockItemXml({ name: i.name, parent: i.group, baseUnit: i.unit, gstHsn: i.hsn, gstRate: i.gstRate }))
        .join('\n');
    await postImport('Stock items — raw materials/packing/scrap', otherXml);

    const bottleXml = bottleSkus
        .map((b) =>
            stockItemXml({
                name: b.name,
                parent: 'Finished Goods - Bottles',
                baseUnit: 'Nos',
                altUnit: { symbol: b.altSymbol, conversion: b.altConversion },
                gstHsn: b.hsn,
                gstRate: b.gstRate,
                components: b.components,
            }),
        )
        .join('\n');
    await postImport('Stock items — bottle SKUs (with BOM)', bottleXml);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function seedVouchers() {
    // A tight loop of rapid sequential POSTs produced intermittent, content-
    // unrelated "Voucher date is missing" failures against this Tally
    // instance's single-threaded gateway — a short gap between imports
    // clears it up.
    for (const v of vouchers) {
        await postImport(v.label, journalVoucherXml(v), 'Vouchers');
        await sleep(500);
    }
}

async function main() {
    const phase = process.argv[2] || 'all';
    console.log(`Seeding demo masters into "${COMPANY_NAME}" at ${TALLY_URL} — phase: ${phase}\n`);

    const phases = {
        units: seedUnits,
        godowns: seedGodowns,
        costcentres: seedCostCentres,
        ledgers: seedLedgers,
        stockgroups: seedStockGroups,
        vouchertypes: seedVoucherTypes,
        stockitems: seedStockItems,
        vouchers: seedVouchers,
    };

    if (phase === 'all') {
        // Dependency order: units/godowns/cost centres have no dependencies;
        // ledgers depend on nothing custom (all parents are default Tally
        // groups); stock groups must exist before stock items; stock items'
        // BOM components must exist before the bottle SKUs that reference them.
        await seedUnits();
        await seedGodowns();
        await seedCostCentres();
        await seedLedgers();
        await seedStockGroups();
        await seedVoucherTypes();
        await seedStockItems();
        await seedVouchers();
    } else if (phases[phase]) {
        await phases[phase]();
    } else {
        console.error(`Unknown phase "${phase}". Options: all, ${Object.keys(phases).join(', ')}`);
        process.exit(1);
    }
}

main().catch((err) => {
    console.error('Fatal error:', err);
    process.exit(1);
});
