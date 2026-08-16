/**
 * Pre-publish gate: refuse to publish unless this candidate genuinely ADVANCES
 * on what the factory is currently serving.
 *
 * WHY. Publishing writes the auto-update feed, and the running factory agent
 * self-updates from it within hours. Republishing an equal version makes the
 * feed disagree with itself; publishing a LOWER one silently rolls the factory
 * back. Neither is visible from the dispatch screen — the operator ticks a box
 * and finds out later. This makes both fail before a single byte is uploaded.
 *
 * WHY IT COMPARES AGAINST LIVE rather than a constant. A number hard-coded in
 * a test ("must be above 0.3.5") is true on the day it is written and quietly
 * wrong from the next release onward. The only durable source for "what is
 * published right now" is the published metadata itself.
 *
 * Dependency-free: node:fs and node:path only. The pure validator below takes
 * plain objects and throws — it touches no filesystem and no network, which is
 * what lets the offline tests cover advance / equal / downgrade / bad semver /
 * wrong filename / wrong commit without contacting anything.
 */

const fs = require('node:fs');

/** Strict x.y.z, numeric only. No prereleases, no build metadata, no `v`. */
function parseVersion(value, label) {
    if (typeof value !== 'string') {
        throw new Error(`${label} version must be a string, got ${typeof value}`);
    }
    const m = /^(\d+)\.(\d+)\.(\d+)$/.exec(value);
    if (!m) {
        throw new Error(
            `${label} version "${value}" is not a strict numeric x.y.z semver. ` +
                `Prereleases and build metadata are deliberately unsupported: the ` +
                `feed's ordering must be unambiguous.`,
        );
    }
    return [Number(m[1]), Number(m[2]), Number(m[3])];
}

/** -1 | 0 | 1 */
function compareVersions(a, b) {
    const pa = parseVersion(a, 'left');
    const pb = parseVersion(b, 'left');
    for (let i = 0; i < 3; i++) {
        if (pa[i] !== pb[i]) return pa[i] < pb[i] ? -1 : 1;
    }
    return 0;
}

/** The filename the artifact must carry, derived from its own version. */
function expectedFilename(version) {
    return `tally-sync-agent-setup-${version}.exe`;
}

/**
 * The whole gate, as a pure function. Throws with a specific message on the
 * first violation; returns a small summary when everything holds.
 *
 * @param {object}  args
 * @param {object}  args.candidate  parsed tally-sync-agent-latest.json from the build artifact
 * @param {object}  args.published  parsed tally-sync-agent-latest.json currently served live
 * @param {string}  args.commit     the short SHA of the workflow run doing the publishing
 */
function validateVersionAdvance({ candidate, published, commit }) {
    if (!candidate || typeof candidate !== 'object') {
        throw new Error('candidate metadata is missing or not an object');
    }
    if (!published || typeof published !== 'object') {
        // Never "assume first publish" — an unreadable live feed is exactly the
        // state in which a blind upload does the most damage.
        throw new Error(
            'published metadata is missing or not an object. Publishing is blocked: ' +
                'without knowing what the factory is serving, an advance cannot be proven.',
        );
    }
    if (typeof commit !== 'string' || commit.trim() === '') {
        throw new Error('the workflow commit SHA was not supplied');
    }

    const candidateVersion = candidate.version;
    const publishedVersion = published.version;
    parseVersion(candidateVersion, 'candidate');
    parseVersion(publishedVersion, 'published');

    const order = compareVersions(candidateVersion, publishedVersion);
    if (order === 0) {
        throw new Error(
            `candidate ${candidateVersion} EQUALS the published version. Republishing the ` +
                `same version makes the update feed disagree with itself; bump first.`,
        );
    }
    if (order < 0) {
        throw new Error(
            `candidate ${candidateVersion} is LOWER than the published ${publishedVersion}. ` +
                `Publishing would silently roll the factory agent backwards.`,
        );
    }

    const wanted = expectedFilename(candidateVersion);
    if (candidate.filename !== wanted) {
        throw new Error(
            `candidate filename "${candidate.filename}" does not match its own version ` +
                `${candidateVersion} — expected "${wanted}". The feed and the installer ` +
                `would name different things.`,
        );
    }

    if (candidate.commit !== commit) {
        throw new Error(
            `candidate was built from commit "${candidate.commit}" but this publish run is ` +
                `at "${commit}". Publishing an artifact from a different commit than the one ` +
                `being published is how an unreviewed build reaches the factory.`,
        );
    }

    return { from: publishedVersion, to: candidateVersion, commit, filename: wanted };
}

/* ── CLI ──────────────────────────────────────────────────────────────── */

function readJsonOrThrow(path, label) {
    let raw;
    try {
        raw = fs.readFileSync(path, 'utf8');
    } catch (err) {
        throw new Error(`${label} could not be read from ${path}: ${err.message}`);
    }
    try {
        return JSON.parse(raw);
    } catch (err) {
        throw new Error(`${label} at ${path} is not valid JSON: ${err.message}`);
    }
}

function parseArgs(argv) {
    const out = {};
    for (let i = 0; i < argv.length; i += 2) {
        const key = argv[i];
        if (!key.startsWith('--')) throw new Error(`unexpected argument "${key}"`);
        out[key.slice(2)] = argv[i + 1];
    }
    return out;
}

function main(argv) {
    const args = parseArgs(argv);
    for (const required of ['candidate', 'published', 'commit']) {
        if (!args[required]) {
            throw new Error(
                `missing --${required}. Usage: assert-version-advance.js ` +
                    `--candidate <path> --published <path> --commit <shortSha>`,
            );
        }
    }

    const result = validateVersionAdvance({
        candidate: readJsonOrThrow(args.candidate, 'candidate metadata'),
        published: readJsonOrThrow(args.published, 'published metadata'),
        commit: args.commit,
    });

    console.log(
        `version advance OK — ${result.from} -> ${result.to} ` +
            `(${result.filename}, commit ${result.commit})`,
    );
}

if (require.main === module) {
    try {
        main(process.argv.slice(2));
    } catch (err) {
        console.error(`\n  PUBLISH BLOCKED — ${err.message}\n`);
        process.exit(1);
    }
}

module.exports = { validateVersionAdvance, compareVersions, parseVersion, expectedFilename };
