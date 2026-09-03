import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';
import { workboxOptions } from './src/pwa/workboxOptions';
import path from 'node:path';
import { execSync } from 'node:child_process';

// A human-readable build stamp, baked in at build time. The app is an
// installed PWA, so after a deploy the browser keeps running its saved copy
// until the background update lands — which made "I still don't see the
// change" undiagnosable: neither the owner nor the tooling could say WHICH
// version a screen was. The stamp (commit + build date, e.g. "7b5d09b ·
// 01 Aug 14:32") renders in the sidebar footer, so "what does the footer
// say?" replaces guessing about caches.
const buildStamp = (() => {
    let commit = 'dev';
    try {
        commit = execSync('git rev-parse --short HEAD', { encoding: 'utf8' }).trim();
    } catch {
        // Building outside a git checkout (or with git unavailable) is not an
        // error worth failing the build over — the date still identifies it.
    }
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${commit} · ${pad(now.getDate())}/${pad(now.getMonth() + 1)} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
})();

// The Laravel app is the single deployable unit: this build writes
// straight into backend/public/build, and Laravel's catch-all route
// serves the resulting index.html for every non-API path. In dev,
// requests to /api and /sanctum are proxied to the Laravel dev server
// so the browser sees everything as same-origin, matching production
// and avoiding CORS/Sanctum stateful-domain complexity.
export default defineConfig(({ command }) => ({
    plugins: [
        react(),
        VitePWA({
            registerType: 'autoUpdate',
            // `null`, not `false`. The plugin only turns on skipWaiting +
            // clientsClaim for an autoUpdate worker when injectRegister is
            // `auto` or nullish (`injectRegister === 'auto' || injectRegister == null`);
            // `false` fails that check and would silently produce a worker that
            // sits in "waiting" forever. Nullish also means the plugin injects
            // no <script> of its own — registration is done explicitly in
            // src/main.tsx via `virtual:pwa-register`, which is the only path
            // that reloads the page when the new worker activates.
            injectRegister: null,
            includeAssets: [
                'swaashpet-favicon.png',
                'swaashpet-apple-touch-icon.png',
                'swaashpet-logo.png',
                'swaashpet-mark.png',
                // Precached too, so the installed-app icon resolves from the
                // cache and never depends on a live fetch.
                'swaashpet-icon-192.png',
                'swaashpet-icon-512.png',
                'swaashpet-maskable-512.png',
            ],
            // The app is served at the site root (/login, /production/...), while
            // its assets — and the service worker — live under /build/. The SW
            // is registered at this root scope; the `.htaccess` sends
            // `Service-Worker-Allowed: /` on build/sw.js so Apache permits it.
            // This is what makes the PWA installable from the plain URL instead
            // of only from /build/.
            scope: '/',
            manifest: {
                name: 'SWAASHPET POLYMERS',
                // Android home screens truncate at roughly 12 characters, so the
                // installed icon drops "POLYMERS" — same casing as `name` so the
                // launcher label and the splash screen read consistently.
                short_name: 'SWAASHPET',
                description: 'Production, stores and compliance for Swaashpet Polymers Private Limited.',
                // Brand navy from the logo artwork (#0A145B) — the Android status
                // bar and task switcher would otherwise clash with it.
                theme_color: '#0A145B',
                background_color: '#ffffff',
                display: 'standalone',
                // Root scope + start page: install is offered from anywhere in
                // the app (all under /), and the installed icon opens the app
                // at its real root.
                start_url: '/',
                scope: '/',
                // The logo is a wide lockup (335x148), so the icons carry only the
                // chevron mark, centred on square white canvases — Chrome would
                // otherwise crop the wordmark badly. The maskable one pulls the
                // mark further in so it survives Android's circle/squircle mask.
                icons: [
                    { src: '/build/swaashpet-icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
                    { src: '/build/swaashpet-icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
                    { src: '/build/swaashpet-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
                ],
            },
            // Only the built app shell (JS/CSS/HTML/icons) is precached. API
            // responses are deliberately never cached by the service worker —
            // this is business data (stock, orders, invoices) where a stale
            // cached read is actively misleading, not just slow. The options
            // themselves live in src/pwa/workboxOptions.ts, where a test pins
            // the one that governs whether the floor sees a deploy at all.
            workbox: workboxOptions,
        }),
    ],
    define: {
        __BUILD_STAMP__: JSON.stringify(buildStamp),
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'src'),
        },
    },
    build: {
        outDir: '../backend/public/build',
        emptyOutDir: true,
    },
    // Production build is served from Laravel's public/build/; dev server
    // stays at root so `npm run dev` works the normal Vite way.
    base: command === 'build' ? '/build/' : '/',
    server: {
        host: '127.0.0.1',
        proxy: {
            // Explicit IPv4 loopback — plain "localhost" can resolve to ::1
            // first on some machines and silently hit a different local
            // service if anything else happens to share this port.
            '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true },
            '/sanctum': { target: 'http://127.0.0.1:8000', changeOrigin: true },
        },
    },
}));
