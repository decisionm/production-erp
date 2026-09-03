/**
 * The service worker's caching contract, in one importable place so it can be
 * asserted by a test rather than only described in a comment.
 *
 * It lives here — not inline in vite.config.ts — because exactly one of these
 * options is load-bearing for a bug that reached the factory floor, and a
 * value that matters that much should fail a test when it changes, not be
 * rediscovered by curling the live worker.
 */
export const workboxOptions = {
    /**
     * NAVIGATIONS GO TO THE NETWORK, ALWAYS.
     *
     * `null` disables the NavigationRoute vite-plugin-pwa builds by default
     * (`navigateFallback: 'index.html'`), which compiled into the live worker
     * as `createHandlerBoundToURL("index.html")`.
     *
     * With that route, a controlled tab answers EVERY navigation from the
     * PRECACHED shell. The request never reaches Laravel, so the
     * `Cache-Control: no-cache` the server sends on index.html is real,
     * correct, and completely bypassed. The shell names a content-hashed
     * bundle, so a stale shell pins the whole app to the old build — and only
     * a hard refresh helped, because that is precisely the gesture that skips
     * the service worker.
     *
     * Reported from the factory 03-Sep-2026: "still in many machines it shows
     * old application, after hard refresh only it shows the right one".
     *
     * THE TRADE, STATED: navigating with no network now fails rather than
     * opening a cached shell. That costs nothing real here — every screen is
     * API-driven and no API response is ever cached by this worker, so an
     * offline tab could only ever render a frame that cannot answer a single
     * question. We give up an empty frame and get a floor running the build we
     * actually deployed.
     */
    navigateFallback: null,

    /**
     * The main bundle is a few MB (see the code-splitting warning at build
     * time, pre-dating PWA support) — raised past Workbox's 2MB default so it
     * actually gets precached instead of silently falling back to
     * network-only.
     */
    maximumFileSizeToCacheInBytes: 6 * 1024 * 1024,
} as const;
