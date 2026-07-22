import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';
import path from 'node:path';

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
            includeAssets: ['favicon.ico', 'apple-touch-icon.png'],
            manifest: {
                name: 'Manufacturing ERP',
                short_name: 'ERP',
                description: 'Inventory, Production, Procurement, Sales, Finance and HRMS for manufacturing.',
                theme_color: '#1677ff',
                background_color: '#ffffff',
                display: 'standalone',
                // Must match where the service worker can actually register
                // (see the comment on VitePWA's `scope` below) — not the
                // app's own root. Laravel's catch-all route (routes/web.php)
                // serves the exact same build/index.html for /build/ as it
                // does for every other path, so this is a safe, real start
                // page — React Router takes over normal in-app navigation
                // from there same as always.
                start_url: '/build/',
                scope: '/build/',
                icons: [
                    { src: 'icon-192.png', sizes: '192x192', type: 'image/png' },
                    { src: 'icon-512.png', sizes: '512x512', type: 'image/png' },
                    { src: 'icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
                ],
            },
            workbox: {
                // Only the built app shell (JS/CSS/HTML/icons) is precached.
                // API responses are deliberately never cached by the service
                // worker — this is business data (stock, orders, invoices)
                // where a stale cached read is actively misleading, not just
                // slow. Installability/fast loads come from the shell being
                // cached; every /api and /sanctum request always hits the
                // network, same as a normal tab.
                navigateFallbackDenylist: [/^\/api\//, /^\/sanctum\//],
                // The main bundle is a few MB (see the existing code-splitting
                // warning at build time, pre-dating PWA support) — raised
                // past Workbox's 2MB default so it actually gets precached
                // instead of silently falling back to network-only.
                maximumFileSizeToCacheInBytes: 6 * 1024 * 1024,
            },
        }),
    ],
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
