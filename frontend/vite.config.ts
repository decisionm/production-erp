import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// The Laravel app is the single deployable unit: this build writes
// straight into backend/public/build, and Laravel's catch-all route
// serves the resulting index.html for every non-API path. In dev,
// requests to /api and /sanctum are proxied to the Laravel dev server
// so the browser sees everything as same-origin, matching production
// and avoiding CORS/Sanctum stateful-domain complexity.
export default defineConfig(({ command }) => ({
    plugins: [react()],
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
