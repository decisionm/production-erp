import { describe, expect, it } from 'vitest';
import { workboxOptions } from '@/pwa/workboxOptions';

/**
 * WHETHER A DEPLOY REACHES THE FLOOR AT ALL.
 *
 * On 03-Sep-2026 the factory reported that many machines kept showing the old
 * application, and that only a hard refresh fixed it. The server was innocent:
 * Laravel sends `Cache-Control: no-cache` on the shell and always did. The
 * live service worker carried a NavigationRoute — compiled from
 * vite-plugin-pwa's default `navigateFallback: 'index.html'` — so a controlled
 * tab answered every navigation from its own precached shell and never asked
 * the server. A hard refresh is exactly the gesture that skips the worker.
 *
 * This is a one-line option whose failure mode is silent, remote, and looks
 * like a browser quirk. It gets a test.
 */
describe('service worker caching contract', () => {
    it('never answers a navigation from the precached shell', () => {
        // Any string here rebuilds the NavigationRoute and the floor goes back
        // to running whatever build its worker happens to hold.
        expect(workboxOptions.navigateFallback).toBeNull();
    });

    it('still precaches the real bundle rather than silently skipping it', () => {
        // Workbox's default cap is 2MB and this bundle is over 4MB: at the
        // default the shell is not precached at all, which turns the PWA into
        // a plain tab without saying so.
        expect(workboxOptions.maximumFileSizeToCacheInBytes).toBeGreaterThan(4 * 1024 * 1024);
    });
});
