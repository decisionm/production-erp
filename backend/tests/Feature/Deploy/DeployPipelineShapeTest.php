<?php

namespace Tests\Feature\Deploy;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Executable contract for the two files that can take the factory offline:
 * `.github/workflows/deploy.yml` and `backend/scripts/deploy.sh`, plus the
 * one other workflow that runs PHP on the live box
 * (`flip-voucher-granularity.yml`).
 *
 * WHY THIS EXISTS AT ALL. None of the properties below can be proven by
 * running anything: the deploy runs on GitHub's infrastructure against a live
 * Hostinger box, and the whole point of a maintenance window is that you do
 * not open one to test it. They are properties of the TEXT — which shell
 * evaluates a substitution, whether a timeout is on the job or on the step,
 * whether a fallback path still exists — and text is exactly what a targeted
 * assertion can hold. Every one of these was a real defect found by review,
 * not a hypothetical.
 *
 * WHY RAW TEXT rather than a YAML parser: the same reasoning
 * `tally-sync-agent/tests/releaseContract.test.js` gives — the repo carries no
 * YAML parser for PHP, and half of these claims are about quoting, which
 * survives no parse. It is also the honest shape of the claim: "the server
 * receives `$(...)` and not `\$(...)`" is a statement about characters.
 *
 * These tests touch no database and no network. They read files.
 */
class DeployPipelineShapeTest extends TestCase
{
    /**
     * Derived from __DIR__, deliberately NOT from base_path(): the workflow
     * data provider below is static and PHPUnit calls it before the Laravel
     * application exists, so a container helper would fatal there.
     *
     * backend/tests/Feature/Deploy -> backend/tests/Feature -> backend/tests
     * -> backend -> the repo root, where .github/ lives.
     */
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function read(string $relative): string
    {
        $path = self::repoRoot().'/'.$relative;

        if (! is_file($path)) {
            self::fail("{$relative} does not exist — this contract is about that file.");
        }

        return (string) file_get_contents($path);
    }

    /** Every `.github/workflows/*.yml`, keyed by name, for the data providers. */
    public static function workflows(): array
    {
        $files = glob(self::repoRoot().'/.github/workflows/*.yml') ?: [];

        return array_combine(
            array_map(fn (string $p): string => basename($p), $files),
            array_map(fn (string $p): array => [basename($p)], $files),
        );
    }

    /** The lines of one `- name: X` step block, up to the next step. */
    private function stepBlock(string $workflow, string $stepName): string
    {
        $lines = explode("\n", $workflow);
        $collected = [];
        $inside = false;

        foreach ($lines as $line) {
            if (preg_match('/^      - name: (.*)$/', $line, $m)) {
                if ($inside) {
                    break;
                }

                $inside = trim($m[1]) === $stepName;
            }

            if ($inside) {
                $collected[] = $line;
            }
        }

        $this->assertNotSame([], $collected, "deploy.yml has no step named '{$stepName}'.");

        return implode("\n", $collected);
    }

    // ---- 1. Which shell evaluates the substitution --------------------------

    /**
     * A `VAR='...\$...'` assignment is the bug that shipped in
     * flip-voucher-granularity.yml: single quotes are not an escape context,
     * so the backslash SURVIVES and the server receives `\$(bash ...)` — a
     * literal dollar followed by an unexpected `(`, i.e. a syntax error where
     * a path to PHP was meant. Every workflow that resolves PHP remotely must
     * use the double-quoted `\$(...)` form, where the runner's shell strips
     * the backslash and the server does the substitution.
     */
    #[DataProvider('workflows')]
    public function test_no_workflow_sends_an_escaped_dollar_inside_single_quotes(string $name): void
    {
        $workflow = self::read(".github/workflows/{$name}");

        $this->assertDoesNotMatchRegularExpression(
            "/^[ \t]*[A-Za-z_][A-Za-z0-9_]*='[^'\n]*\\\\\\\$/m",
            $workflow,
            "{$name} assigns a SINGLE-quoted string containing \\\$. Single quotes keep the backslash, "
            .'so the remote shell receives \\$(...) — a syntax error, not a command substitution. '
            .'Use double quotes: VAR="...\\$(...)...".',
        );
    }

    public function test_the_granularity_flip_resolves_php_on_the_server_and_uses_it(): void
    {
        $workflow = self::read('.github/workflows/flip-voucher-granularity.yml');

        // The runner must send `PHP=$(bash scripts/pick-php.sh ...)` as text.
        $this->assertStringContainsString(
            'RESOLVE_PHP="PHP=\$(bash scripts/pick-php.sh 2>/dev/null || echo /opt/alt/php84/usr/bin/php)"',
            $workflow,
            'the flip must resolve PHP the way deploy.yml does — pick-php.sh exists only on the server (issue #167).',
        );

        // ...and every artisan call must go through that remote variable.
        // Comment lines are skipped: prose naming an artisan command is not an
        // invocation, and a contract that breaks when someone documents the
        // step teaches people to stop documenting it.
        $invocations = 0;

        foreach (explode("\n", $workflow) as $number => $line) {
            if (str_starts_with(ltrim($line), '#') || ! str_contains($line, 'artisan ')) {
                continue;
            }

            $invocations++;
            $this->assertMatchesRegularExpression(
                '/\\\\\$PHP artisan /',
                $line,
                'flip-voucher-granularity.yml line '.($number + 1).' invokes artisan without \\$PHP. It must be '
                .'ESCAPED so the SERVER expands it — a runner-expanded $PHP is empty, and a hardcoded '
                .'interpreter path is issue #167 all over again.',
            );
        }

        $this->assertGreaterThan(0, $invocations, 'the flip runs no artisan command at all — read the file.');

        // Both branches — the dry run reads config too, and it is the branch
        // an operator reaches for first.
        $this->assertSame(
            2,
            substr_count($workflow, 'REMOTE="$RESOLVE_PHP'),
            'BOTH the write and the dry-run branch must resolve PHP; a dry run that cannot run artisan '
            .'reports nothing about the effective config, which is the half of its job that matters.',
        );
    }

    // ---- 2. The maintenance window is bounded per STEP ----------------------

    /**
     * A job-level timeout counts npm ci + typecheck + vite build + composer +
     * ~2,100 tests in the same budget as the outage, and CANCELS the job when
     * it fires rather than failing a step — so the explainer steps, which are
     * the entire operator-facing recovery path, are not guaranteed to run.
     */
    public function test_the_deploy_job_carries_no_job_level_timeout(): void
    {
        $workflow = self::read('.github/workflows/deploy.yml');

        $this->assertDoesNotMatchRegularExpression(
            '/^    timeout-minutes:/m',
            $workflow,
            'a JOB-level timeout-minutes is back. It bounds the build+test gate together with the maintenance '
            .'window and cancels rather than fails, which is the state where the explainers are least likely '
            .'to run. Bound the maintenance-window STEPS instead.',
        );
    }

    /**
     * Each step that runs while the app is closed (or that is about to close
     * it) is bounded on its own, so a hang becomes an ordinary step failure —
     * red X on the step that hung, `failure()` true, explainers fire.
     *
     * @return array<string, array{0: string}>
     */
    public static function maintenanceWindowSteps(): array
    {
        return [
            'Enter maintenance mode' => ['Enter maintenance mode'],
            'Rsync backend to server' => ['Rsync backend to server'],
            'Run server-side deploy tasks' => ['Run server-side deploy tasks'],
            'Check the backup directory is writable' => ['Check the backup directory is writable'],
        ];
    }

    #[DataProvider('maintenanceWindowSteps')]
    public function test_each_maintenance_window_step_is_bounded_on_its_own(string $stepName): void
    {
        $block = $this->stepBlock(self::read('.github/workflows/deploy.yml'), $stepName);

        $this->assertMatchesRegularExpression(
            '/^        timeout-minutes: \d+$/m',
            $block,
            "'{$stepName}' runs inside (or immediately before) the maintenance window and has no step-level "
            .'timeout-minutes. Without one a hang sits on GitHub\'s 360-minute default with the factory at 503 '
            .'and the run showing "in progress" — the only SILENT outage in this pipeline.',
        );
    }

    // ---- 3. The backup directory, and where dumps may NOT land --------------

    public function test_the_backup_directory_is_proven_before_the_app_is_closed(): void
    {
        $workflow = self::read('.github/workflows/deploy.yml');

        $preflight = strpos($workflow, '- name: Check the backup directory is writable');
        $maintenance = strpos($workflow, '- name: Enter maintenance mode');

        $this->assertNotFalse($preflight, 'the backup-directory preflight step is gone.');
        $this->assertNotFalse($maintenance, 'deploy.yml no longer enters maintenance mode — read the file.');
        $this->assertLessThan(
            $maintenance,
            $preflight,
            'the preflight must run BEFORE artisan down. After it, a host that cannot hold the backup fails '
            .'with the floor already at 503 — which is the whole cost this step exists to avoid.',
        );

        $block = $this->stepBlock($workflow, 'Check the backup directory is writable');

        $this->assertStringContainsString('id: backup_preflight', $block, 'the explainer gating below keys on this id.');
        $this->assertStringContainsString('$SSH_MUX', $block, 'it must reuse the multiplexed connection — this host bans on rapid re-auth.');
        $this->assertStringContainsString('$HOME/backups/erp', $block, 'it must prove the directory deploy.sh actually writes to.');
        $this->assertStringContainsString('mkdir -p', $block);
        $this->assertStringContainsString('printf probe', $block, 'a WRITE, not just a stat.');
        $this->assertStringContainsString('grep -q probe', $block, 'and a READ BACK — a write that cannot be read is not a usable backup directory.');

        // A failing preflight must not also stack the gate explainer, whose
        // text names the build and test steps.
        $this->assertStringContainsString(
            "if: failure() && steps.backup_preflight.outcome != 'failure'",
            $workflow,
            "'Explain a failed gate' would otherwise fire for a preflight failure and tell the operator to look "
            .'at Type-check / Pint / Backend tests, none of which failed.',
        );
        $this->assertStringContainsString(
            "if: failure() && steps.backup_preflight.outcome == 'failure'",
            $workflow,
            'a timed-out preflight never reaches its inline error, so it needs a dedicated explainer.',
        );
    }

    /**
     * `../backups` resolves to $DEPLOY_PATH/backups, which since the
     * 19-Aug-2026 migration is INSIDE a live WordPress site's public_html. A
     * full dump carries password hashes, customers and purchase rates — the
     * last of which is Owner/Accounts only under FC-06. There is no acceptable
     * "only when something has already gone wrong" version of that.
     */
    public function test_the_deploy_script_never_falls_back_into_a_web_document_root(): void
    {
        $script = self::read('backend/scripts/deploy.sh');

        // Asserted on the ASSIGNMENTS, not on every mention: the comment block
        // and the refusal message both name ../backups deliberately, so the
        // operator reading either knows which path is refused and why.
        preg_match_all('/^\s*BACKUP_DIR=(?<value>.*)$/m', $script, $matches);

        $this->assertNotSame([], $matches['value'], 'deploy.sh never sets BACKUP_DIR — read the file.');

        foreach ($matches['value'] as $value) {
            $this->assertStringNotContainsString(
                '..',
                $value,
                "BACKUP_DIR is assigned a RELATIVE path ({$value}). Relative means \$DEPLOY_PATH/backups, which on "
                .'this host is inside another site\'s document root; a dump that lands there is an FC-06 '
                .'disclosure waiting on one unrelated .htaccess edit. Refuse the deploy instead.',
            );
        }
    }

    public function test_an_unusable_backup_directory_stops_the_deploy_rather_than_relocating_it(): void
    {
        $script = self::read('backend/scripts/deploy.sh');

        $start = strpos($script, 'BACKUP_DIR="${BACKUP_DIR:-$HOME/backups/erp}"');
        $this->assertNotFalse($start, 'deploy.sh no longer defaults BACKUP_DIR to a directory outside the web root.');

        $end = strpos($script, 'BACKUP_FILE=', $start);
        $this->assertNotFalse($end);
        $block = substr($script, $start, $end - $start);

        $this->assertStringContainsString(
            'exit 1',
            $block,
            'an unusable backup directory must STOP the deploy — the pre-migration dump is the only recovery '
            .'from a half-applied migration, and MySQL cannot roll back DDL.',
        );
        $this->assertStringNotContainsString(
            'WARN:',
            $block,
            'a warning is not a guard. The one deploy where this branch fires is the one nobody is reading.',
        );
        $this->assertStringContainsString(
            'Do NOT',
            $block,
            'the refusal happens with the app CLOSED, so it must carry the same recovery contract as every '
            .'other stop in this script: 503 is deliberate, fix and re-run, do not artisan up by hand.',
        );
    }

    // ---- 4. The recovery contract the explainers promise --------------------

    public function test_explainers_distinguish_closed_from_unknown_maintenance_state(): void
    {
        $workflow = self::read('.github/workflows/deploy.yml');

        $this->assertStringContainsString(
            "if: (failure() || cancelled()) && steps.maintenance.outcome == 'success'",
            $workflow,
            'the "app is closed" explainer must stay keyed on the maintenance step having SUCCEEDED.',
        );
        $this->assertStringContainsString(
            "if: (failure() || cancelled()) && steps.maintenance.outcome == 'failure'",
            $workflow,
            'a failed maintenance step is distinct from a skipped one.',
        );
        $this->assertStringContainsString(
            'The app state is UNKNOWN: artisan down may have completed',
            $workflow,
            'a timeout can fire after artisan down but before SSH returns, so failure must never claim the app stayed open.',
        );
        $this->assertStringContainsString(
            '~/backups/erp/erp-db-*.sql',
            $workflow,
            'the recovery text must name where the pre-migration dump actually is.',
        );
    }

    /**
     * THE SERVICE WORKER MUST NOT BE CACHEABLE.
     *
     * Everything the app does to keep itself current — autoUpdate
     * registration, the 60-second `registration.update()` poll in main.tsx,
     * skipWaiting and clientsClaim — rests on that update check fetching a
     * FRESH sw.js. Serve it from a cache and the whole chain silently does
     * nothing: no new worker installs, no reload fires, and the floor keeps
     * running a bundle from days ago while the server has been serving a
     * newer one all along. That is not hypothetical; it happened on live on
     * 31-Aug-2026, and only unregistering the worker by hand cleared it.
     *
     * A header is exactly the kind of thing a later edit drops without
     * noticing, and nothing else in the suite would go red if it did, so it
     * is pinned here beside the rest of the deploy contract.
     */
    public function test_the_service_worker_is_served_uncacheable(): void
    {
        $htaccess = self::read('backend/public/.htaccess');

        $start = strpos($htaccess, '<Files "sw.js">');
        $this->assertNotFalse($start, 'backend/public/.htaccess no longer has a <Files "sw.js"> block — the worker needs both its scope header and its no-cache header.');

        $end = strpos($htaccess, '</Files>', $start);
        $this->assertNotFalse($end);
        $block = substr($htaccess, $start, $end - $start);

        $this->assertMatchesRegularExpression(
            '/Header\s+set\s+Cache-Control\s+"no-cache"/i',
            $block,
            'sw.js is served without an explicit Cache-Control. With only Last-Modified and an ETag, any cache '
            .'between the factory and the server may apply heuristic freshness and answer the update check with '
            .'yesterday\'s worker — which stops the app updating at all.',
        );

        $this->assertMatchesRegularExpression(
            '/Header\s+set\s+Service-Worker-Allowed\s+"\/"/i',
            $block,
            'sw.js lost its Service-Worker-Allowed header; the worker ships under /build/ and cannot claim the app scope without it.',
        );
    }
}
