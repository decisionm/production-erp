/**
 * Executable contract for the RELEASE PATH — the packaging supply chain and
 * the rule that publishing is a deliberate, main-only, manual act.
 *
 * WHY THIS EXISTS. Publishing this agent IS deploying to the factory floor:
 * the running tray app auto-updates from the published feed within hours,
 * with no human in between. The guards that keep that deliberate live in YAML
 * and in a version pin — both of which are easy to loosen by accident and
 * impossible to notice afterwards. This file makes loosening them fail.
 *
 * WHY IT READS RAW TEXT rather than parsing YAML. Adding a YAML parser would
 * be a new dependency for a contract test, and the repo's own tooling budget
 * for this suite is zero dependencies. The assertions below are therefore
 * targeted string/regex checks against the real file — which is also the
 * honest way to assert "the condition does NOT accept a push", since that is
 * a claim about the text of the condition, not about a parsed value.
 *
 * Nothing here contacts Tally, GitHub, npm or the live ERP. It reads files.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const AGENT_ROOT = path.resolve(__dirname, '..');
const REPO_ROOT = path.resolve(AGENT_ROOT, '..');

const read = (p) => fs.readFileSync(p, 'utf8');
const readJson = (p) => JSON.parse(read(p));

const pkg = readJson(path.join(AGENT_ROOT, 'package.json'));
const lock = readJson(path.join(AGENT_ROOT, 'package-lock.json'));
const workflow = read(path.join(REPO_ROOT, '.github/workflows/build-agent.yml'));

/**
 * The publish job's `if:` expression, extracted EXACTLY.
 *
 * Handles the YAML folded scalar (`if: >-` followed by indented continuation
 * lines) and drops comment-only lines, so a comment placed inside or beside
 * the block cannot fail the contract. Everything else survives — which is the
 * point: an extra executable term must change the normalized string.
 */
function publishCondition() {
    const lines = workflow.split('\n');

    const jobAt = lines.findIndex((l) => /^ {2}publish:\s*$/.test(l));
    assert.notEqual(jobAt, -1, 'the publish job must exist');

    let ifAt = -1;
    for (let i = jobAt + 1; i < lines.length; i++) {
        if (/^ {2}\S/.test(lines[i])) break; // next job — went too far
        if (/^\s*if:/.test(lines[i])) {
            ifAt = i;
            break;
        }
    }
    assert.notEqual(ifAt, -1, 'the publish job must carry an if: condition');

    const indent = lines[ifAt].search(/\S/);
    const inline = lines[ifAt].replace(/^\s*if:\s*/, '');

    // A plain inline condition (`if: <expr>`) needs no folding.
    const isFolded = /^[>|][-+]?$/.test(inline.trim());
    const parts = isFolded ? [] : [inline];

    if (isFolded) {
        for (let i = ifAt + 1; i < lines.length; i++) {
            const line = lines[i];
            if (line.trim() === '') continue;
            if (line.search(/\S/) <= indent) break; // dedented out of the block
            parts.push(line);
        }
    }

    return parts
        .map((l) => l.trim())
        .filter((l) => l !== '' && !l.startsWith('#')) // comment-only lines ignored
        .join(' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/* ── Step-scoped extraction ───────────────────────────────────────────────
 *
 * Asserting against the WHOLE workflow text is a false-green generator: delete
 * a real `--header` line, paste the same text back as a `#` comment, and a
 * whole-file regex still matches. Everything below therefore locates the named
 * step, takes only that step's span, and strips comment lines before asserting.
 *
 * These helpers take the text as a parameter rather than closing over the real
 * workflow, so the adversarial suite at the bottom can run decoys through the
 * exact same code and prove it rejects them.
 * ─────────────────────────────────────────────────────────────────────── */

/** Every `- name:` / `- uses:` step, with the line span it occupies. */
function stepsOf(text) {
    const lines = text.split('\n');
    const found = [];
    lines.forEach((line, i) => {
        const m = /^(\s*)- (?:name|uses):\s*(.*?)\s*$/.exec(line);
        if (m) found.push({ indent: m[1].length, start: i, label: m[2] });
    });
    found.forEach((s, idx) => {
        let end = lines.length;
        for (let j = idx + 1; j < found.length; j++) {
            if (found[j].indent <= s.indent) {
                end = found[j].start;
                break;
            }
        }
        s.end = end;
    });
    return { lines, steps: found };
}

/** The one step with this exact name. Duplicates are themselves a finding. */
function namedStep(text, name) {
    const { lines, steps } = stepsOf(text);
    const hits = steps.filter((s) => s.label === name);
    assert.equal(
        hits.length,
        1,
        `expected exactly one step named "${name}", found ${hits.length} — a duplicate ` +
            `step name makes every positional assertion ambiguous`,
    );
    const s = hits[0];
    return { ...s, text: lines.slice(s.start, s.end).join('\n') };
}

/** A step's EXECUTABLE lines: comment-only lines removed, blanks removed. */
function executableLines(stepText) {
    return stepText
        .split('\n')
        .filter((l) => l.trim() !== '' && !l.trim().startsWith('#'));
}

/**
 * Drop an INLINE shell comment, quote-aware.
 *
 * Removing comment-only lines is not enough: `true # --header 'Cache-Control:
 * no-cache'` is a line of executable code whose text still satisfies any
 * substring check, so a mutation can delete the real curl argument, paste it
 * after a `#`, and stay green. A `#` only opens a comment when it is outside
 * quotes AND starts a word — `max-age=0` inside '...' must survive untouched.
 */
function stripInlineComment(line) {
    let inSingle = false;
    let inDouble = false;
    for (let i = 0; i < line.length; i++) {
        const c = line[i];
        if (c === "'" && !inDouble) inSingle = !inSingle;
        else if (c === '"' && !inSingle) inDouble = !inDouble;
        else if (c === '#' && !inSingle && !inDouble && (i === 0 || /\s/.test(line[i - 1]))) {
            return line.slice(0, i);
        }
    }
    return line;
}

/** Trimmed lines of real CODE: comments (whole-line and inline) gone. */
function codeLines(stepText) {
    return stepText
        .split('\n')
        .map((l) => stripInlineComment(l).trim())
        .filter((l) => l !== '');
}

const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Is there a code line that BEGINS with this command/argument? */
function startsALine(lines, pattern) {
    const re = typeof pattern === 'string' ? new RegExp('^' + escapeRe(pattern)) : pattern;
    return lines.some((l) => re.test(l));
}

/** Is this a real whitespace-delimited argument on some code line? */
function hasArg(lines, arg) {
    const re = new RegExp(`(^|\\s)${escapeRe(arg)}(\\s|=|$)`);
    return lines.some((l) => re.test(l));
}

/** A step's executable content, joined — what actually runs. */
function executableBody(text, name) {
    return executableLines(namedStep(text, name).text).join('\n');
}

/**
 * A step's `run:` script, comments stripped — handling BOTH YAML forms:
 * the inline `run: npm test` used by the build steps, and the `run: |` block
 * used by the publish steps. The `run:` key itself is never returned, so an
 * assertion can anchor on the command at line start in either form.
 */
function runBody(text, name) {
    const step = namedStep(text, name);
    const lines = step.text.split('\n');
    const runAt = lines.findIndex((l) => /^\s*run:/.test(l));
    assert.notEqual(runAt, -1, `step "${name}" must have a run: script`);

    const runIndent = lines[runAt].search(/\S/);
    const first = lines[runAt].replace(/^\s*run:\s*/, '');
    const isBlock = /^[|>][-+]?$/.test(first.trim());

    // Continuation = anything indented deeper than the `run:` key itself.
    // Stopping at the first dedent keeps sibling keys (env:, with:) out.
    const cont = [];
    for (let i = runAt + 1; i < lines.length; i++) {
        if (lines[i].trim() === '') continue;
        if (lines[i].search(/\S/) <= runIndent) break;
        cont.push(lines[i]);
    }

    const script = isBlock ? cont : [first, ...cont];
    return executableLines(script.join('\n')).join('\n');
}

/** 0-based line index where a named step begins — for ordering assertions. */
function stepPosition(text, name) {
    return namedStep(text, name).start;
}

/** The real step names this contract pins, so a rename is caught, not missed. */
const STEP = {
    // build job — the packaging gate
    buildInstall: 'Install deps',
    buildTest: 'Voucher + release contract tests',
    buildAudit: 'Audit build + runtime dependencies',
    buildPackage: 'Build Windows installer',
    // publish job — the release gate
    fetch: 'Fetch currently published metadata',
    gate: 'Assert this candidate advances on what is published',
    ssh: 'Set up SSH',
    upload: 'Upload installer + update feed to the ERP\'s public storage',
};

/** The one expression that is safe. Anything else is a finding, not a variant. */
const SAFE_PUBLISH_CONDITION =
    "github.event_name == 'workflow_dispatch' " +
    '&& inputs.publish == true ' +
    "&& github.ref == 'refs/heads/main'";

/* ── Version identity ─────────────────────────────────────────────────── */

test('package.json and both package-lock root versions agree', () => {
    assert.equal(
        lock.version,
        pkg.version,
        'package-lock top-level version drifted from package.json',
    );
    assert.equal(
        lock.packages[''].version,
        pkg.version,
        'package-lock packages[""] version drifted from package.json',
    );
});

test('the version is a strict numeric x.y.z', () => {
    // Whether it ADVANCES on what is live is not knowable offline and is not
    // guessed here — assert-version-advance.js proves it at publish time
    // against the real published metadata. A constant in this file would be
    // true the day it was written and quietly wrong from the next release on.
    assert.match(pkg.version, /^\d+\.\d+\.\d+$/);
});

/* ── The packaging toolchain pin ──────────────────────────────────────── */

test('electron-builder is pinned EXACTLY, with no range operator', () => {
    const spec = pkg.devDependencies['electron-builder'];
    assert.equal(
        spec,
        '26.15.3',
        'electron-builder must be an exact pin; a caret lets the tool that ' +
            'builds the factory installer drift without review',
    );
    assert.doesNotMatch(spec, /[\^~><*|xX]/, 'the pin must carry no range operator');
});

test('the lockfile resolves electron-builder to exactly the pinned version', () => {
    const entry = lock.packages['node_modules/electron-builder'];
    assert.ok(entry, 'electron-builder missing from the lockfile');
    assert.equal(entry.version, '26.15.3');
});

/* ── The publish gate ─────────────────────────────────────────────────── */

test('the publish condition is EXACTLY the safe expression, character for character', () => {
    // Deliberately an equality, not a bundle of regexes. A regex bundle passes
    // on `!inputs.publish`, on `inputs.publish != true`, and on any extra
    // disjunct bolted onto the end — every one of which publishes to the
    // factory under conditions nobody reviewed. Equality has no such gaps:
    // ANY deviation, in either polarity or in extra terms, fails here.
    assert.equal(
        publishCondition(),
        SAFE_PUBLISH_CONDITION,
        'the publish condition deviates from the one reviewed-safe expression',
    );
});

test('the workflow_dispatch publish input defaults to false and warns explicitly', () => {
    const inputs = workflow.slice(workflow.indexOf('workflow_dispatch:'), workflow.indexOf('concurrency:'));
    assert.match(inputs, /default:\s*false/, 'publish must default to false');
    assert.match(
        inputs,
        /PUBLISHING IS DEPLOYING/i,
        'the input description must say plainly that publishing deploys to the factory',
    );
});

/* ── Run safety ───────────────────────────────────────────────────────── */

test('a newer run must never cancel a packaging or publish run', () => {
    assert.match(
        workflow,
        /cancel-in-progress:\s*false/,
        'cancelling mid-publish can leave a truncated installer or a half-written feed',
    );
});

test('the workflow watches itself in the push path filter', () => {
    const on = workflow.slice(0, workflow.indexOf('concurrency:'));
    assert.match(
        on,
        /- '\.github\/workflows\/build-agent\.yml'/,
        'a change to the release workflow must itself trigger a build for review',
    );
});

/* ── The packaging gate: tests and audit run BEFORE the installer ─────── */

test('the build job runs the tests, as a command not a comment', () => {
    // Named step + line-anchored, for the same reason the publish path is:
    // whole-file indexOf lets the real step be deleted and its text pasted
    // back as a comment, leaving the packaging gate green and absent.
    const code = codeLines(runBody(workflow, STEP.buildTest));
    assert.ok(
        startsALine(code, /^npm test\b/),
        'the test step must BEGIN a line with `npm test`',
    );
});

test('the build job audits dev/build dependencies too, as a command not a comment', () => {
    const code = codeLines(runBody(workflow, STEP.buildAudit));

    assert.ok(
        startsALine(code, /^npm audit --audit-level=high\b/),
        'the audit step must BEGIN a line with `npm audit --audit-level=high`',
    );
    // electron-builder BUILDS the installer that auto-updates the factory PC,
    // so omitting dev deps would pass while that supply chain sat unexamined.
    assert.equal(
        hasArg(code, '--omit=dev'),
        false,
        'the packaging audit must NOT be narrowed to runtime dependencies',
    );
});

test('the build job still builds the installer, as a command not a comment', () => {
    const code = codeLines(runBody(workflow, STEP.buildPackage));
    assert.ok(
        startsALine(code, /^npm run package:win\b/),
        'the packaging step must BEGIN a line with `npm run package:win`',
    );
});

test('tests and the audit both run BEFORE the installer is built', () => {
    // By named-step position. Order is the safety property: a gate that runs
    // after packaging has already produced the artifact it was meant to stop.
    const testAt = stepPosition(workflow, STEP.buildTest);
    const auditAt = stepPosition(workflow, STEP.buildAudit);
    const packageAt = stepPosition(workflow, STEP.buildPackage);
    const installAt = stepPosition(workflow, STEP.buildInstall);

    assert.ok(installAt < testAt, 'deps must be installed before the tests run');
    assert.ok(testAt < packageAt, 'npm test must run BEFORE package:win, not after');
    assert.ok(auditAt < packageAt, 'the audit must run BEFORE package:win, not after');
});

/* ── The pre-publish version-advance gate ─────────────────────────────── */

test('the version-advance gate runs BEFORE SSH setup and before any upload', () => {
    // By NAMED STEP POSITION, not by first indexOf of a string. A comment
    // mentioning the checker early in the file must not be able to satisfy an
    // ordering claim while the real gate sits after the upload.
    const gateAt = stepPosition(workflow, STEP.gate);
    const fetchAt = stepPosition(workflow, STEP.fetch);
    const sshAt = stepPosition(workflow, STEP.ssh);
    const uploadAt = stepPosition(workflow, STEP.upload);

    assert.ok(fetchAt < gateAt, 'the live metadata must be fetched before the gate reads it');
    assert.ok(gateAt < sshAt, 'the gate must precede SSH setup — before any key is written');
    assert.ok(gateAt < uploadAt, 'the gate must precede the upload — before any byte is sent');
});

test('the gate step actually invokes the checker, as a command not a comment', () => {
    const code = codeLines(runBody(workflow, STEP.gate));

    // Anchored at line start: `echo ok # node ...assert-version-advance.js`
    // is a real line of code whose TEXT mentions the checker while running
    // nothing. Only a line that BEGINS with the invocation counts.
    assert.ok(
        startsALine(code, /^node\s+\S*scripts\/assert-version-advance\.js/),
        'the gate step must begin a line with the node invocation of the checker',
    );
    assert.ok(
        startsALine(code, '--candidate out/tally-sync-agent-latest.json'),
        'the candidate must come from the downloaded build artifact, not the repo',
    );
    assert.ok(startsALine(code, '--published published.json'), 'expected --published as a real arg');
    assert.ok(startsALine(code, /^--commit\s/), 'the run commit must be passed as a real arg');
});

test('the fetch step really fetches, with fail/retry/timeout as real arguments', () => {
    const code = codeLines(runBody(workflow, STEP.fetch));

    assert.ok(startsALine(code, /^curl\b/), 'a line must BEGIN with curl');
    assert.ok(hasArg(code, '--fail'), 'without --fail an HTML error page is saved as metadata');
    assert.ok(startsALine(code, /^--retry\s+\d/), 'bounded retries must be a real argument line');
    assert.ok(
        startsALine(code, /^--connect-timeout\s+\d/),
        'the connect timeout must be a real argument line',
    );
    assert.ok(hasArg(code, '--max-time'), 'the fetch must be time-bounded');
});

test('the live metadata fetch cannot be served from a cache', () => {
    // Scoped to the fetch step, comments (whole-line AND inline) removed, and
    // anchored at line start. A cached copy predating the last publish reports
    // an older version, which would wave through exactly the republish or
    // rollback this gate exists to block.
    const code = codeLines(runBody(workflow, STEP.fetch));

    assert.ok(
        startsALine(code, /^--header 'Cache-Control: no-cache[^']*'/),
        'a real curl argument line must send a no-cache Cache-Control header',
    );
    assert.ok(
        startsALine(code, "--header 'Pragma: no-cache'"),
        'a real curl argument line must send Pragma: no-cache',
    );

    // Unique per run AND per attempt AND per commit — a re-run of the same
    // commit must not be able to reuse the previous attempt's cache entry.
    const buster = code.find((l) => /^CACHE_BUSTER=/.test(l));
    assert.ok(buster, 'expected a line BEGINNING with a CACHE_BUSTER assignment');
    for (const part of ['GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT', 'GITHUB_SHA']) {
        assert.match(buster, new RegExp(part), `the cache-buster must vary with ${part}`);
    }
});

test('the cache-buster is a query on the real feed path, and the URL is quoted', () => {
    const step = executableBody(workflow, STEP.fetch);
    const code = codeLines(runBody(workflow, STEP.fetch));
    const body = code.join('\n');

    // FEED_URL lives in the step's env:, which is executable YAML.
    assert.match(
        step,
        /FEED_URL: https:\/\/erp\.actech\.co\.in\/storage\/agent\/tally-sync-agent-latest\.json\s*$/m,
        'the feed URL must remain the real generic-provider metadata path',
    );
    assert.ok(
        startsALine(code, '"${FEED_URL}?cachebust=${CACHE_BUSTER}"'),
        'a real argument line must be the QUOTED query appended to FEED_URL — unquoted ' +
            'invites word splitting, and a path segment would fetch a different document',
    );
    assert.doesNotMatch(
        body,
        /\$\{FEED_URL\}\/cachebust/,
        'the buster must not be a path segment',
    );
});

test('the publish job checks out the exact SHA being published', () => {
    const publishJob = workflow.slice(workflow.indexOf('\n  publish:'));
    assert.match(
        publishJob,
        /ref: \$\{\{ github\.sha \}\}/,
        'the checker must run from the SHA being published, not a moving branch tip',
    );
});

/* ── The published artifact contract, preserved ───────────────────────── */

test('stable filenames and checksum metadata survive the hardening', () => {
    assert.match(pkg.build.artifactName, /tally-sync-agent-setup-\$\{version\}\.exe/);
    assert.match(workflow, /sha256/, 'the metadata json must still carry a sha256');
    assert.match(workflow, /latest\.yml/, 'the electron-updater feed must still be published');
    assert.match(workflow, /retention-days:\s*7/, 'artifact retention must be preserved');
});

/* ── Docs must not claim a merge publishes ────────────────────────────── */

test('no doc claims that a merge or a push to main publishes', () => {
    const docs = {
        'DEPLOY.md': read(path.join(REPO_ROOT, 'DEPLOY.md')),
        'tally-sync-agent/CLAUDE.md': read(path.join(AGENT_ROOT, 'CLAUDE.md')),
    };

    // Phrasings that would tell a reader merging is enough to reach the factory.
    const claims = [
        /whose push always\s+publishes/i,
        /push(es)? to main always publish/i,
        /merge to `?main`?,? which publishes/i,
    ];

    for (const [name, text] of Object.entries(docs)) {
        for (const claim of claims) {
            assert.doesNotMatch(text, claim, `${name} still claims a merge publishes`);
        }
    }
});

/* ── Adversarial: the extractors must reject decoys ───────────────────────
 *
 * These run SYNTHETIC workflows through the same helpers the real assertions
 * use. They exist because the previous version of this file scanned the whole
 * workflow text, so a deleted curl argument pasted back as a comment still
 * passed — a false green on the gate that keeps unreviewed builds off the
 * factory floor. If the helpers ever regress to whole-file scanning, these
 * fail before the real assertions get the chance to lie.
 * ─────────────────────────────────────────────────────────────────────── */

/** A minimal publish job whose fetch step's real curl arguments are gone. */
const DECOY_COMMENT_ONLY_HEADERS = `
jobs:
  publish:
    steps:
      - name: Fetch currently published metadata
        env:
          FEED_URL: https://erpdemo.amrtech.in/storage/agent/tally-sync-agent-latest.json
        run: |
          # --header 'Cache-Control: no-cache, no-store, max-age=0'
          # --header 'Pragma: no-cache'
          # CACHE_BUSTER="\${GITHUB_RUN_ID}-\${GITHUB_RUN_ATTEMPT}-\${GITHUB_SHA}"
          curl --fail -o published.json "\${FEED_URL}"
      - name: Set up SSH
        run: echo ssh
`;

/** The checker named only in an early comment; the real gate runs last. */
const DECOY_EARLY_GATE_COMMENT = `
jobs:
  publish:
    steps:
      # we run node scripts/assert-version-advance.js very early, honest
      - name: Fetch currently published metadata
        run: curl --fail -o published.json "\${FEED_URL}"
      - name: Set up SSH
        run: echo ssh
      - name: Upload installer + update feed to the ERP's public storage
        run: scp out/* server:/dest
      - name: Assert this candidate advances on what is published
        run: node scripts/assert-version-advance.js --candidate a --published b --commit c
`;

test('ADVERSARIAL: comment-only cache headers do NOT satisfy the fetch contract', () => {
    const body = runBody(DECOY_COMMENT_ONLY_HEADERS, STEP.fetch);

    // The decoy's text contains all three strings — but only inside comments.
    assert.match(DECOY_COMMENT_ONLY_HEADERS, /Cache-Control: no-cache/);
    assert.match(DECOY_COMMENT_ONLY_HEADERS, /Pragma: no-cache/);
    assert.match(DECOY_COMMENT_ONLY_HEADERS, /CACHE_BUSTER=/);

    // Scoped to the executable body, none of them survive.
    assert.doesNotMatch(body, /--header 'Cache-Control/, 'a commented header must not count');
    assert.doesNotMatch(body, /--header 'Pragma/, 'a commented header must not count');
    assert.equal(
        executableLines(body).find((l) => l.includes('CACHE_BUSTER=')),
        undefined,
        'a commented cache-buster must not count',
    );
});

test('ADVERSARIAL: an early gate COMMENT does not make the real gate early', () => {
    // Whole-file indexOf would find the checker name in the comment on line 3
    // and conclude the gate precedes SSH. Step positions tell the truth.
    const naiveIndex = DECOY_EARLY_GATE_COMMENT.indexOf('assert-version-advance.js');
    const naiveSsh = DECOY_EARLY_GATE_COMMENT.indexOf('Set up SSH');
    assert.ok(naiveIndex < naiveSsh, 'precondition: the naive check is fooled by this decoy');

    const gateAt = stepPosition(DECOY_EARLY_GATE_COMMENT, STEP.gate);
    const sshAt = stepPosition(DECOY_EARLY_GATE_COMMENT, STEP.ssh);
    const uploadAt = stepPosition(DECOY_EARLY_GATE_COMMENT, STEP.upload);

    assert.ok(gateAt > sshAt, 'step positions must expose that the real gate is AFTER ssh');
    assert.ok(gateAt > uploadAt, 'step positions must expose that the real gate is AFTER upload');
});

/** The real curl args deleted and hidden after an INLINE `#` on a live line. */
const DECOY_INLINE_COMMENT_HEADERS = `
jobs:
  publish:
    steps:
      - name: Fetch currently published metadata
        env:
          FEED_URL: https://erpdemo.amrtech.in/storage/agent/tally-sync-agent-latest.json
        run: |
          true # CACHE_BUSTER="\${GITHUB_RUN_ID}-\${GITHUB_RUN_ATTEMPT}-\${GITHUB_SHA}"
          curl --fail -o published.json \\
               true # --header 'Cache-Control: no-cache, no-store, max-age=0'
               true # --header 'Pragma: no-cache'
               "\${FEED_URL}"
      - name: Set up SSH
        run: echo ssh
`;

/** The checker hidden after an inline `#` on a line that runs something else. */
const DECOY_INLINE_COMMENT_NODE = `
jobs:
  publish:
    steps:
      - name: Assert this candidate advances on what is published
        run: |
          echo ok # node tally-sync-agent/scripts/assert-version-advance.js \\
          echo ok # --candidate out/tally-sync-agent-latest.json \\
          echo ok # --published published.json --commit deadbee
`;

test('ADVERSARIAL: INLINE-comment cache headers do NOT satisfy the fetch contract', () => {
    const code = codeLines(runBody(DECOY_INLINE_COMMENT_HEADERS, STEP.fetch));

    // The decoy text contains every string, on lines that really execute.
    assert.match(DECOY_INLINE_COMMENT_HEADERS, /--header 'Cache-Control: no-cache/);
    assert.match(DECOY_INLINE_COMMENT_HEADERS, /--header 'Pragma: no-cache'/);
    assert.match(DECOY_INLINE_COMMENT_HEADERS, /CACHE_BUSTER=/);
    // And a comment-only-line filter would keep them: these lines start with `true`.
    assert.ok(
        executableLines(DECOY_INLINE_COMMENT_HEADERS).some((l) => l.includes("Cache-Control")),
        'precondition: comment-only filtering is fooled by this decoy',
    );

    // Anchored, inline-comment-stripped: none of them are real arguments.
    assert.equal(
        startsALine(code, /^--header 'Cache-Control/),
        false,
        'an inline-commented header must not count',
    );
    assert.equal(
        startsALine(code, "--header 'Pragma: no-cache'"),
        false,
        'an inline-commented header must not count',
    );
    assert.equal(
        code.some((l) => /^CACHE_BUSTER=/.test(l)),
        false,
        'an inline-commented cache-buster must not count',
    );
});

test('ADVERSARIAL: an INLINE-comment node invocation does NOT satisfy the gate', () => {
    const code = codeLines(runBody(DECOY_INLINE_COMMENT_NODE, STEP.gate));

    assert.match(DECOY_INLINE_COMMENT_NODE, /node tally-sync-agent\/scripts\/assert-version-advance\.js/);
    assert.ok(
        executableLines(DECOY_INLINE_COMMENT_NODE).some((l) => l.includes('assert-version-advance')),
        'precondition: comment-only filtering is fooled by this decoy',
    );

    assert.equal(
        startsALine(code, /^node\s+\S*scripts\/assert-version-advance\.js/),
        false,
        'an inline-commented node invocation must not count as running the gate',
    );
    assert.equal(
        startsALine(code, '--candidate out/tally-sync-agent-latest.json'),
        false,
        'an inline-commented argument must not count',
    );
});

test('ADVERSARIAL: stripInlineComment preserves # inside quotes', () => {
    // A real argument must not be mangled: the guard has to be quote-aware or
    // it would break legitimate values and invite someone to loosen it again.
    assert.equal(
        stripInlineComment(`--header 'Cache-Control: no-cache, max-age=0' \\`).trim(),
        `--header 'Cache-Control: no-cache, max-age=0' \\`,
    );
    assert.equal(stripInlineComment(`echo "a # b"`), `echo "a # b"`);
    assert.equal(stripInlineComment(`curl --fail # trailing note`).trim(), 'curl --fail');
    assert.equal(stripInlineComment(`# whole line`), '');
});

/** Build job with the real test/audit steps DELETED, their text left in comments. */
const DECOY_BUILD_STEPS_COMMENTED = `
jobs:
  build:
    steps:
      - name: Install deps
        run: npm ci
      # - name: Voucher + release contract tests
      #   run: npm test
      # - name: Audit build + runtime dependencies
      #   run: npm audit --audit-level=high
      - name: Build Windows installer
        run: npm run package:win
`;

/** Build steps present by name, but their commands neutered by echo/inline #. */
const DECOY_BUILD_STEPS_ECHOED = `
jobs:
  build:
    steps:
      - name: Install deps
        run: npm ci
      - name: Voucher + release contract tests
        run: echo skipping # npm test
      - name: Audit build + runtime dependencies
        run: |
          # npm audit --audit-level=high
          echo ok
      - name: Build Windows installer
        run: npm run package:win
`;

/** The audit narrowed to runtime only — the packaging chain unexamined. */
const DECOY_BUILD_AUDIT_OMIT_DEV = `
jobs:
  build:
    steps:
      - name: Audit build + runtime dependencies
        run: npm audit --audit-level=high --omit=dev
`;

test('ADVERSARIAL: deleted build steps left as comments are refused', () => {
    // A whole-file scan finds all three strings and passes.
    assert.match(DECOY_BUILD_STEPS_COMMENTED, /run: npm test/);
    assert.match(DECOY_BUILD_STEPS_COMMENTED, /npm audit --audit-level=high/);

    // Named-step lookup exposes that the steps do not exist.
    assert.throws(() => stepPosition(DECOY_BUILD_STEPS_COMMENTED, STEP.buildTest), /found 0/);
    assert.throws(() => stepPosition(DECOY_BUILD_STEPS_COMMENTED, STEP.buildAudit), /found 0/);
});

test('ADVERSARIAL: echo / inline-comment decoys do not satisfy the build gate', () => {
    const testCode = codeLines(runBody(DECOY_BUILD_STEPS_ECHOED, STEP.buildTest));
    const auditCode = codeLines(runBody(DECOY_BUILD_STEPS_ECHOED, STEP.buildAudit));

    assert.match(DECOY_BUILD_STEPS_ECHOED, /npm test/); // present as text
    assert.match(DECOY_BUILD_STEPS_ECHOED, /npm audit --audit-level=high/);

    assert.equal(startsALine(testCode, /^npm test\b/), false, 'echo + inline # must not count');
    assert.equal(
        startsALine(auditCode, /^npm audit --audit-level=high\b/),
        false,
        'a commented audit must not count',
    );
});

test('ADVERSARIAL: an audit narrowed with --omit=dev is refused', () => {
    const code = codeLines(runBody(DECOY_BUILD_AUDIT_OMIT_DEV, STEP.buildAudit));
    assert.ok(startsALine(code, /^npm audit --audit-level=high\b/), 'precondition: the command is real');
    assert.equal(hasArg(code, '--omit=dev'), true, 'precondition: the decoy narrows the audit');
    // The real assertion inverts this — proving the check would fire.
});

test('ADVERSARIAL: a renamed or duplicated build step is refused', () => {
    const renamed = DECOY_BUILD_STEPS_ECHOED.replace(
        'Voucher + release contract tests',
        'Run the tests',
    );
    assert.throws(() => stepPosition(renamed, STEP.buildTest), /found 0/);

    const duped = `
jobs:
  build:
    steps:
      - name: Build Windows installer
        run: npm run package:win
      - name: Build Windows installer
        run: npm run package:win
`;
    assert.throws(() => stepPosition(duped, STEP.buildPackage), /expected exactly one step named/);
});

test('ADVERSARIAL: a duplicate step name is itself refused', () => {
    const dupe = `
jobs:
  publish:
    steps:
      - name: Set up SSH
        run: echo one
      - name: Set up SSH
        run: echo two
`;
    assert.throws(() => stepPosition(dupe, STEP.ssh), /expected exactly one step named/);
});

test('ADVERSARIAL: a missing step is refused rather than silently skipped', () => {
    assert.throws(() => stepPosition('jobs:\n  publish:\n    steps: []\n', STEP.gate), /found 0/);
});

test('DEPLOY.md states the candidate-then-dispatch shape positively', () => {
    const deploy = read(path.join(REPO_ROOT, 'DEPLOY.md'));
    assert.match(deploy, /CANDIDATE ARTIFACT ONLY|candidate artifact only|does NOT publish/i);
    assert.match(deploy, /publish: true/);
});
