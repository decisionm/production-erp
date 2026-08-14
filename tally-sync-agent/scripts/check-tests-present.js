/**
 * Preflight for `npm test`: refuse to report success when there is nothing to run.
 *
 * WHY. `node --test "tests/*.test.js"` exits 0 when the glob matches nothing,
 * so a renamed, moved or deleted test file would turn the CI voucher-builder
 * gate green while testing zero things. That failure is silent and looks
 * exactly like a pass — the same shape of problem as a voucher that never
 * posts. This makes it loud instead.
 *
 * Dependency-free and cross-platform on purpose: node:fs and node:path only,
 * no shell globbing, no `find`, no new package. Paths are resolved relative to
 * the agent root so it behaves the same from any working directory.
 */

const fs = require('node:fs');
const path = require('node:path');

const AGENT_ROOT = path.resolve(__dirname, '..');
const TESTS_DIR = path.join(AGENT_ROOT, 'tests');

/** Files the suite must not lose. Named, so a rename fails rather than passes. */
const REQUIRED = ['stockJournal.test.js', 'releaseContract.test.js', 'versionAdvance.test.js'];

function fail(message) {
    console.error(`\n  npm test preflight FAILED\n\n  ${message}\n`);
    process.exit(1);
}

if (!fs.existsSync(TESTS_DIR) || !fs.statSync(TESTS_DIR).isDirectory()) {
    fail(`No tests directory at ${TESTS_DIR}. The voucher-builder contract is not being tested.`);
}

const present = fs
    .readdirSync(TESTS_DIR, { withFileTypes: true })
    .filter((e) => e.isFile() && e.name.endsWith('.test.js'))
    .map((e) => e.name)
    .sort();

if (present.length === 0) {
    fail(
        `${TESTS_DIR} contains no *.test.js files, so the test glob would match nothing ` +
            `and node --test would exit 0 having run nothing.`,
    );
}

const missing = REQUIRED.filter((name) => !present.includes(name));
if (missing.length > 0) {
    fail(
        `Required test file(s) missing from ${TESTS_DIR}: ${missing.join(', ')}.\n` +
            `  Found instead: ${present.join(', ') || '(none)'}.\n` +
            `  If a file was deliberately renamed, update REQUIRED in ${path.relative(AGENT_ROOT, __filename)}.`,
    );
}

console.log(`test preflight OK — ${present.length} test file(s): ${present.join(', ')}`);
