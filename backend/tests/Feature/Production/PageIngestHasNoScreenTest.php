<?php

namespace Tests\Feature\Production;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 7, P7-04 — the paper-page ingest endpoint has no caller in the SPA,
 * and that fact is pinned rather than left to be rediscovered.
 *
 * `POST production/shift-production-entries/page` works and is tested, but
 * nothing in the bundled frontend calls it. It is NOT being declared
 * "API-only": the priority it was built for is quoted in its docblocks from a
 * 05-Aug discussion, and a discussion is not a decision (AGENTS.md), so
 * whether the factory still wants the page screen is owner question Q49.
 *
 * This test states today's truth in both directions. If a screen is built,
 * this test fails and is DELETED with the answer to Q49 in its commit — the
 * failure is the reminder to close the question, not a defect.
 */
class PageIngestHasNoScreenTest extends TestCase
{
    public function test_no_screen_in_the_bundled_frontend_calls_the_page_ingest_endpoint(): void
    {
        $frontend = base_path('../frontend/src');
        $this->assertDirectoryExists($frontend, 'the SPA source is where this repo says it is');

        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($frontend, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, 'shift-production-entries/page')) {
                $hits[] = str_replace($frontend.'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $hits, implode("\n", [
            'A screen now calls the paper-page ingest endpoint:',
            ...$hits,
            '',
            'That is not a failure of the endpoint — it means owner question Q49',
            '(is the page screen wanted?) has been answered by building it.',
            'Record the answer in docs/factory and delete this test.',
        ]));
    }

    public function test_the_endpoint_itself_is_still_registered(): void
    {
        // The other half of the pin: "no caller" must never quietly become
        // "no endpoint either" without the question being answered.
        $registered = collect(Route::getRoutes())
            ->contains(fn ($route) => $route->uri() === 'api/v1/production/shift-production-entries/page'
                && in_array('POST', $route->methods(), true));

        $this->assertTrue($registered, 'the page ingest endpoint is still there (Q49 decides its future)');
    }
}
